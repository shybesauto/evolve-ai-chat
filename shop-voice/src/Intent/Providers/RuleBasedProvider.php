<?php
declare(strict_types=1);

namespace ShopVoice\Intent\Providers;

use ShopVoice\History\KeywordServiceCategoryMatcher;
use ShopVoice\Intent\ActionCatalog;
use ShopVoice\Intent\Intent;
use ShopVoice\Intent\IntentContext;
use ShopVoice\Intent\IntentProvider;
use ShopVoice\Intent\IntentTarget;

/**
 * A deterministic parser with no network dependency.
 *
 * Not a stub. It exists for three real jobs:
 *   - it is what runs at INTENT_PROVIDER=mock, so the whole system demos and
 *     the test suite runs with no API key and no rate limit;
 *   - it is the fallback when a hosted provider is down or throttled — a free
 *     tier is goodwill, not a contract (§3), and a throttle should cost nuance,
 *     not the ability to load an RO;
 *   - it fixes the expected behaviour of every phrase in the spec, which is
 *     what the hosted adapters are then measured against.
 *
 * It handles the phrasings a bay actually produces. It does not generalise, and
 * it reports honest low confidence when it is guessing — which routes the
 * utterance to a clarifying question rather than an action.
 */
final class RuleBasedProvider implements IntentProvider
{
    public function __construct(
        private KeywordServiceCategoryMatcher $categories = new KeywordServiceCategoryMatcher(),
    ) {
    }

    public function name(): string
    {
        return 'rule_based';
    }

    public function model(): string
    {
        return 'deterministic';
    }

    public function parse(string $transcript, IntentContext $context): Intent
    {
        $text = strtolower(trim($transcript));
        $make = fn (string $action, IntentTarget $target, array $params, float $confidence): Intent
            => new Intent($action, $target, $params, $confidence, $transcript, $this->name(), $this->model());

        // Inside inspection mode a finding is the default reading of almost
        // anything, so it is checked before the general command patterns (§8).
        if ($context->inspectionOpen && !$this->looksLikeCommand($text)) {
            return $make(ActionCatalog::ADD_INSPECTION_ITEM, new IntentTarget('context'), [
                'value' => $transcript,
                'condition' => $this->extractCondition($text),
            ], $this->extractCondition($text) !== null ? 0.9 : 0.72);
        }

        if ($this->matches($text, ['end inspection', 'finish inspection', 'done with the inspection', 'close inspection'])) {
            return $make(ActionCatalog::END_INSPECTION, new IntentTarget('context'), [], 0.97);
        }

        if ($this->matches($text, ['start inspection', 'begin inspection', 'start an inspection', 'start the inspection'])) {
            return $make(ActionCatalog::START_INSPECTION, new IntentTarget('context'), [], 0.97);
        }

        if ($this->matches($text, ['undo that', 'undo', 'scratch that', 'never mind that', 'take that back'])) {
            return $make(ActionCatalog::UNDO, new IntentTarget('none'), [], 0.95);
        }

        if (($user = $this->extractSwitchUser($text)) !== null) {
            return $make(ActionCatalog::SWITCH_USER, new IntentTarget('user', $user), ['user_name' => $user], 0.93);
        }

        if ($this->matches($text, ['release', 'clear the car', 'clear context', 'unload', 'done with this one', 'close this one'])) {
            return $make(ActionCatalog::RELEASE_CONTEXT, new IntentTarget('context'), [], 0.92);
        }

        if ($this->matches($text, ['note', 'make a note', 'add a note', 'start a note', 'write this down', 'dictate'])) {
            return $make(ActionCatalog::ADD_NOTE, new IntentTarget('context'), [
                'body' => $this->stripNotePreamble($transcript),
            ], 0.9);
        }

        if ($this->matches($text, ['history', 'last time', 'when did we', 'have we ever', 'previous', 'past work'])) {
            $params = [];
            $category = $this->extractCategory($text);
            if ($category !== null) {
                $params['service_category'] = $category;
            }
            return $make(ActionCatalog::GET_HISTORY, $this->extractTarget($text, $context), $params, 0.92);
        }

        if ($this->matches($text, ['diagram', 'wiring', 'schematic', 'connector view', 'pinout'])) {
            return $make(ActionCatalog::GET_DIAGRAM, $this->extractTarget($text, $context), [
                'system' => $this->extractCategory($text) ?? 'unspecified',
            ], 0.9);
        }

        if ($this->matches($text, ['spec', 'specs', 'torque', 'capacity', 'how much oil', 'fluid type', 'what weight'])) {
            return $make(ActionCatalog::GET_SPECS, $this->extractTarget($text, $context), [
                'spec_type' => $this->extractSpecType($text),
            ], 0.9);
        }

        if ($this->matches($text, ['mark it', 'set status', 'change status', 'move it to'])) {
            return $make(ActionCatalog::SET_STATUS, $this->extractTarget($text, $context), [
                'status' => $this->extractStatus($text) ?? '',
            ], 0.88);
        }

        if ($this->matches($text, ['delete', 'remove that', 'get rid of'])) {
            return $make('delete_note', $this->extractTarget($text, $context), [], 0.85);
        }

        if ($this->matches($text, ['pull up', 'bring up', 'open', 'show me', 'load', 'switch to', 'go to', 'r.o.', ' ro ', 'repair order'])
            || preg_match('/^\s*#?\d{3,7}\s*$/', $text) === 1) {
            $target = $this->extractTarget($text, $context, requireExplicit: true);
            return $make(
                ActionCatalog::GET_ORDER,
                $target,
                [],
                $target->type === 'ro_number' || $target->type === 'vin' ? 0.95 : 0.85
            );
        }

        // Nothing matched at all. Emitting a real action with low confidence
        // would turn "order me a pizza" into "which repair order?"; emitting
        // nothing recognisable is what §4 actually asks for — the validator
        // rejects it flatly and it lands in intent_log for prompt tuning.
        return $make('unrecognised', new IntentTarget('none'), [], 0.2);
    }

    public function matchServiceCategory(string $category, array $serviceNames): array
    {
        return $this->categories->match($category, $serviceNames);
    }

    public function draftCustomerNote(string $rawTranscript, ?string $vehicleDescription = null): string
    {
        // Without a model there is nothing honest to paraphrase into, so the
        // draft is the technician's own words, flagged for a human to rewrite.
        // Silently shipping an unrewritten draft as if a model had checked it
        // is exactly the failure mode §6 is about.
        return trim($rawTranscript);
    }

    public function slotInspectionItem(string $transcript, array $fields): array
    {
        $text = strtolower($transcript);
        $best = null;
        $bestScore = 0;

        foreach ($fields as $field) {
            $score = 0;
            foreach ($this->fieldWords($field) as $word) {
                if (str_contains($text, $word)) {
                    $score += strlen($word);
                }
            }
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $field;
            }
        }

        return [
            'field_key' => $best['field_key'] ?? null,
            'value' => $this->extractMeasurement($transcript),
            'condition' => $this->extractCondition($text),
            'confidence' => $best === null ? 0.2 : min(0.95, 0.55 + ($bestScore / 40)),
        ];
    }

    // --- helpers ----------------------------------------------------------

    /** @param list<string> $needles */
    private function matches(string $text, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains(' ' . $text . ' ', $needle)) {
                return true;
            }
        }
        return false;
    }

    /** Words that mean "this is a command", not "this is a finding". */
    private function looksLikeCommand(string $text): bool
    {
        return $this->matches($text, [
            'end inspection', 'finish inspection', 'close inspection', 'undo',
            'pull up', 'bring up', 'switch to', 'this is ', 'release', 'start inspection',
        ]);
    }

    private function extractTarget(string $text, IntentContext $context, bool $requireExplicit = false): IntentTarget
    {
        if (preg_match('/\b(?:r\.?o\.?|repair order|order|ticket)\s*(?:number\s*)?#?\s*(\d{3,7})\b/i', $text, $m)) {
            return new IntentTarget('ro_number', $m[1]);
        }
        if (preg_match('/^\s*#?(\d{3,7})\s*$/', $text, $m)) {
            return new IntentTarget('ro_number', $m[1]);
        }
        if (preg_match('/\b([a-hj-npr-z0-9]{17})\b/i', $text, $m)) {
            return new IntentTarget('vin', strtoupper($m[1]));
        }
        if (preg_match('/\b(?:plate|tag)\s*[:#]?\s*([a-z0-9]{1,4}[\s-]?[a-z0-9]{1,6})/i', $text, $m)) {
            return new IntentTarget('plate', strtoupper(trim($m[1])));
        }

        if ($this->matches($text, ['this one', 'this truck', 'this car', 'this vehicle', ' it ', 'on this'])) {
            return new IntentTarget('context');
        }

        $descriptive = \ShopVoice\Resolver\RoResolver::stripLeadIn($text);
        if ($descriptive !== '' && $requireExplicit) {
            return new IntentTarget('vehicle', $descriptive);
        }

        return $context->hasContext() ? new IntentTarget('context') : new IntentTarget('none');
    }

    private function extractCategory(string $text): ?string
    {
        foreach (KeywordServiceCategoryMatcher::known() as $category) {
            if (str_contains($text, $category)) {
                return $category;
            }
            if ($this->categories->match($category, [$text]) !== []) {
                return $category;
            }
        }
        return null;
    }

    private function extractCondition(string $text): ?string
    {
        foreach (['green', 'yellow', 'red'] as $condition) {
            if (str_contains($text, $condition)) {
                return $condition;
            }
        }
        return null;
    }

    private function extractStatus(string $text): ?string
    {
        foreach (['scheduled', 'in progress', 'awaiting parts', 'awaiting approval', 'ready', 'complete', 'invoiced'] as $status) {
            if (str_contains($text, $status)) {
                return $status;
            }
        }
        return null;
    }

    private function extractSpecType(string $text): string
    {
        return match (true) {
            str_contains($text, 'torque') => 'torque',
            str_contains($text, 'capacit'), str_contains($text, 'how much') => 'capacity',
            str_contains($text, 'oil'), str_contains($text, 'fluid'), str_contains($text, 'weight') => 'fluid',
            default => 'general',
        };
    }

    private function extractSwitchUser(string $text): ?string
    {
        if (preg_match('/this is ([a-z][a-z\'-]+(?:\s+[a-z][a-z\'-]+)?)(?:[,.]|\s+switch|\s*$)/i', $text, $m)) {
            return ucwords(trim($m[1]));
        }
        if (preg_match('/switch to ([a-z][a-z\'-]+(?:\s+[a-z][a-z\'-]+)?)(?:\'s)?\s*(?:user|account)?\s*$/i', $text, $m)) {
            $name = trim($m[1]);
            if (strtolower($name) !== 'my') {
                return ucwords($name);
            }
        }
        return null;
    }

    private function stripNotePreamble(string $transcript): string
    {
        $stripped = preg_replace(
            '/^\s*(?:ok(?:ay)?[,\s]+)?(?:make|add|start|write|take)\s+(?:a|an|the)?\s*note(?:\s+(?:on|for|that|saying))?[:,]?\s*/i',
            '',
            trim($transcript)
        ) ?? $transcript;
        return trim($stripped);
    }

    /** @param array{field_key:string,label:string,unit:?string} $field */
    private function fieldWords(array $field): array
    {
        $source = strtolower($field['label'] . ' ' . str_replace('_', ' ', $field['field_key']));
        $words = array_filter(
            preg_split('/[^a-z0-9]+/', $source) ?: [],
            static fn (string $w): bool => strlen($w) > 2
        );
        return array_values(array_unique($words));
    }

    private function extractMeasurement(string $transcript): ?string
    {
        // "front pads four millimetres" has to become 4mm before any of this
        // matches — a tech says the number, he does not spell it.
        $transcript = \ShopVoice\Resolver\SpokenNumbers::digitize($transcript, preserveLoneSmallNumbers: false);

        if (preg_match('/(\d+(?:\.\d+)?)\s*(millimet(?:er|re)s?|mm|\/32|32nds?|volts?|v\b|psi|percent|%)/i', $transcript, $m)) {
            return trim($m[1] . ' ' . $this->normalizeUnit($m[2]));
        }
        if (preg_match('/\b(\d+(?:\.\d+)?)\b/', $transcript, $m)) {
            return $m[1];
        }
        return null;
    }

    private function normalizeUnit(string $unit): string
    {
        $unit = strtolower(trim($unit));
        return match (true) {
            str_starts_with($unit, 'millimet'), $unit === 'mm' => 'mm',
            $unit === '32nd' || $unit === '32nds' || $unit === '/32' => '/32',
            str_starts_with($unit, 'volt'), $unit === 'v' => 'V',
            $unit === 'percent', $unit === '%' => '%',
            default => $unit,
        };
    }
}
