<?php
declare(strict_types=1);

namespace ShopVoice\Intent\Providers;

use ShopVoice\Intent\Intent;
use ShopVoice\Intent\IntentContext;
use ShopVoice\Intent\IntentProvider;
use ShopVoice\Support\Logger;

/**
 * Runs a primary provider and falls back to a secondary when it returns
 * nothing usable.
 *
 * This is the operational answer to "free tiers are goodwill, not contract"
 * (§3): a 429 at 11am on a Tuesday drops the shop to the deterministic parser
 * for a minute instead of taking voice off the board. It is composition, not a
 * special case — the seam holds because the fallback is itself an
 * IntentProvider.
 */
final class FallbackProvider implements IntentProvider
{
    public function __construct(
        private IntentProvider $primary,
        private IntentProvider $secondary,
        private Logger $logger,
        private float $minimumConfidence = 0.01,
    ) {
    }

    public function name(): string
    {
        return $this->primary->name() . '+' . $this->secondary->name();
    }

    public function model(): string
    {
        return $this->primary->model();
    }

    public function parse(string $transcript, IntentContext $context): Intent
    {
        $intent = $this->primary->parse($transcript, $context);

        // An empty action or zero confidence is how HostedProvider reports a
        // failed call, and it is indistinguishable from a genuinely unparseable
        // utterance — which the secondary is also entitled to have an opinion on.
        if ($intent->action !== '' && $intent->confidence >= $this->minimumConfidence) {
            return $intent;
        }

        $this->logger->warn('intent.fallback_used', [
            'primary' => $this->primary->name(),
            'secondary' => $this->secondary->name(),
        ]);

        return $this->secondary->parse($transcript, $context);
    }

    public function matchServiceCategory(string $category, array $serviceNames): array
    {
        $matches = $this->primary->matchServiceCategory($category, $serviceNames);
        return $matches !== [] ? $matches : $this->secondary->matchServiceCategory($category, $serviceNames);
    }

    public function draftCustomerNote(string $rawTranscript, ?string $vehicleDescription = null): string
    {
        $draft = $this->primary->draftCustomerNote($rawTranscript, $vehicleDescription);
        return trim($draft) !== '' ? $draft : $this->secondary->draftCustomerNote($rawTranscript, $vehicleDescription);
    }

    public function slotInspectionItem(string $transcript, array $fields): array
    {
        $slot = $this->primary->slotInspectionItem($transcript, $fields);
        if (($slot['field_key'] ?? null) !== null && ($slot['confidence'] ?? 0.0) > 0.0) {
            return $slot;
        }
        return $this->secondary->slotInspectionItem($transcript, $fields);
    }
}
