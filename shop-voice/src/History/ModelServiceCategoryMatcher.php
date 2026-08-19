<?php
declare(strict_types=1);

namespace ShopVoice\History;

use ShopVoice\Intent\IntentProvider;
use ShopVoice\Support\Logger;

/**
 * Semantic category matching via the intent model, with the keyword table as a
 * fallback.
 *
 * The model sees only service *names* — no customer data, no transcript — which
 * keeps this call cheap and keeps shop data out of the prompt beyond what the
 * question needs.
 *
 * If the model is slow, rate-limited or wrong-shaped, we fall back rather than
 * fail: a free-tier hiccup must not take history lookup off the board (§3).
 */
final class ModelServiceCategoryMatcher implements ServiceCategoryMatcher
{
    public function __construct(
        private IntentProvider $provider,
        private KeywordServiceCategoryMatcher $fallback,
        private Logger $logger,
    ) {
    }

    public function match(string $category, array $serviceNames): array
    {
        if ($serviceNames === []) {
            return [];
        }

        // Keyword hits are certain; the model only has to find the ones the
        // table would miss, and its answer is intersected back against the
        // candidate list so it cannot invent a service.
        $keywordHits = $this->fallback->match($category, $serviceNames);

        try {
            $modelHits = $this->provider->matchServiceCategory($category, $serviceNames);
        } catch (\Throwable $e) {
            $this->logger->warn('history.category_match_failed', [
                'category' => $category,
                'provider' => $this->provider->name(),
                'error' => $e->getMessage(),
            ]);
            return $keywordHits;
        }

        $valid = array_values(array_filter(
            $modelHits,
            static fn (string $name): bool => in_array($name, $serviceNames, true)
        ));

        return array_values(array_unique([...$keywordHits, ...$valid]));
    }

    public function label(string $category): string
    {
        return $this->fallback->label($category);
    }
}
