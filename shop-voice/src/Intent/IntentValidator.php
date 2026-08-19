<?php
declare(strict_types=1);

namespace ShopVoice\Intent;

/**
 * The service layer validates every intent against a whitelist and rejects
 * anything unrecognised rather than guessing (§4).
 *
 * This class is the whole trust boundary between the model and the shop's data.
 * It is intentionally boring and intentionally strict: unknown action out,
 * unknown parameter dropped, low confidence turned into a question.
 */
final class IntentValidator
{
    /** @var list<string> */
    private const CONDITIONS = ['green', 'yellow', 'red'];

    /**
     * Statuses a voice command may even propose. set_status still needs a tap;
     * this list just stops the model inventing a workflow state.
     *
     * @var list<string>
     */
    private const STATUSES = [
        'scheduled', 'in progress', 'awaiting parts', 'awaiting approval',
        'ready', 'complete', 'invoiced',
    ];

    public function __construct(private float $confidenceThreshold = 0.70)
    {
    }

    public function validate(Intent $intent, IntentContext $context): ValidationResult
    {
        if ($intent->action === '') {
            return ValidationResult::rejected('schema', $intent);
        }

        if (!ActionCatalog::exists($intent->action)) {
            // Logged by the caller for prompt tuning — this log is the only
            // feedback loop the prompt has.
            return ValidationResult::rejected('unknown_action', $intent);
        }

        $tier = ActionCatalog::tier($intent->action);
        if ($tier === null) {
            return ValidationResult::rejected('unknown_action', $intent);
        }

        $params = $this->filterParams($intent);

        if (($paramError = $this->validateParams($intent->action, $params)) !== null) {
            return ValidationResult::rejected($paramError, $intent);
        }

        $cleaned = $intent->withParams($params);

        // Confidence gate last, so a low-confidence *and* malformed intent is
        // reported as malformed rather than asked about.
        if ($cleaned->confidence < $this->confidenceThreshold) {
            return ValidationResult::clarify($this->clarifyQuestion($cleaned, $context), $cleaned);
        }

        if (($contextError = $this->validateContext($cleaned, $context)) !== null) {
            return $contextError;
        }

        return ValidationResult::accepted($cleaned);
    }

    /**
     * Drop parameters the action does not declare. An unexpected key is far more
     * likely to be model noise than a feature, and silently dropping it is
     * safer than passing it into a handler.
     *
     * @return array<string,mixed>
     */
    private function filterParams(Intent $intent): array
    {
        $allowed = ActionCatalog::params($intent->action);
        $params = [];
        foreach ($intent->params as $key => $value) {
            if (is_string($key) && in_array($key, $allowed, true)) {
                $params[$key] = $value;
            }
        }
        return $params;
    }

    /** @param array<string,mixed> $params */
    private function validateParams(string $action, array $params): ?string
    {
        if (isset($params['condition'])) {
            $condition = strtolower((string) $params['condition']);
            if (!in_array($condition, self::CONDITIONS, true)) {
                return 'bad_condition';
            }
        }

        if ($action === ActionCatalog::SET_STATUS && isset($params['status'])) {
            $status = strtolower(trim((string) $params['status']));
            if (!in_array($status, self::STATUSES, true)) {
                return 'bad_status';
            }
        }

        if ($action === ActionCatalog::EDIT_PRICE && isset($params['amount']) && !is_numeric($params['amount'])) {
            return 'bad_amount';
        }

        return null;
    }

    /** Actions that need something loaded, when nothing is. */
    private function validateContext(Intent $intent, IntentContext $context): ?ValidationResult
    {
        $needsOrder = [
            ActionCatalog::GET_HISTORY, ActionCatalog::ADD_NOTE, ActionCatalog::START_INSPECTION,
            ActionCatalog::GET_DIAGRAM, ActionCatalog::GET_SPECS, ActionCatalog::SET_STATUS,
            ActionCatalog::EDIT_PRICE,
        ];

        if (in_array($intent->action, $needsOrder, true)
            && $intent->target->isContext()
            && !$context->hasContext()) {
            return ValidationResult::clarify(
                'I don\'t have a repair order loaded. Which one?',
                $intent,
                'no_context'
            );
        }

        if ($intent->action === ActionCatalog::ADD_INSPECTION_ITEM && !$context->inspectionOpen) {
            // Inspection items only mean something inside inspection mode (§8).
            return ValidationResult::rejected('no_inspection', $intent, 'Say "start inspection" first.');
        }

        return null;
    }

    /**
     * Below the threshold we ask, and the question names what we think we heard
     * so the tech can correct one word instead of repeating the sentence.
     */
    private function clarifyQuestion(Intent $intent, IntentContext $context): string
    {
        return match ($intent->action) {
            ActionCatalog::GET_ORDER => $intent->target->value !== null
                ? sprintf('Did you say %s?', $intent->target->value)
                : 'Which repair order?',
            ActionCatalog::GET_HISTORY => sprintf(
                'History on %s — for what, brakes?',
                $context->vehicleDescription ?? 'this one'
            ),
            ActionCatalog::ADD_NOTE => 'Do you want me to start a note?',
            ActionCatalog::SWITCH_USER => 'Who am I switching to?',
            default => "I didn't quite get that — say it again?",
        };
    }
}
