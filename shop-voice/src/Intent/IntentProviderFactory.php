<?php
declare(strict_types=1);

namespace ShopVoice\Intent;

use ShopVoice\Intent\Providers\AnthropicProvider;
use ShopVoice\Intent\Providers\FallbackProvider;
use ShopVoice\Intent\Providers\OpenAiCompatibleProvider;
use ShopVoice\Intent\Providers\RuleBasedProvider;
use ShopVoice\Support\Config;
use ShopVoice\Support\HttpClient;
use ShopVoice\Support\Logger;

/**
 * Provider chosen by config (§3). Swapping to a paid key is a config change,
 * never a rewrite — this factory is the only place that knows vendor names.
 *
 * Every hosted provider is wrapped in a FallbackProvider over the deterministic
 * parser, so a rate limit degrades the shop instead of stopping it.
 */
final class IntentProviderFactory
{
    public static function make(Config $config, HttpClient $http, Logger $logger): IntentProvider
    {
        $rules = new RuleBasedProvider();
        $vendor = strtolower($config->string('intent.provider', 'mock'));

        $hosted = match ($vendor) {
            'groq' => OpenAiCompatibleProvider::groq(
                $http,
                $config->string('groq.key'),
                $config->string('groq.model', 'llama-3.3-70b-versatile'),
                $logger
            ),
            'openai' => new OpenAiCompatibleProvider(
                $http,
                $config->string('openai.key'),
                $config->string('openai.model', 'gpt-4o-mini'),
                $logger
            ),
            'anthropic' => new AnthropicProvider(
                $http,
                $config->string('anthropic.key'),
                $config->string('anthropic.model', 'claude-haiku-4-5-20251001'),
                $logger
            ),
            'mock', 'rule_based', '' => null,
            default => throw new \InvalidArgumentException(
                "Unknown INTENT_PROVIDER '{$vendor}'. Expected one of: groq, openai, anthropic, mock."
            ),
        };

        if ($hosted === null) {
            return $rules;
        }

        return new FallbackProvider($hosted, $rules, $logger);
    }
}
