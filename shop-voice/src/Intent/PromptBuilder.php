<?php
declare(strict_types=1);

namespace ShopVoice\Intent;

/**
 * Builds the model prompt from the action catalog, so the prompt and the
 * server-side whitelist can never disagree about what is legal.
 */
final class PromptBuilder
{
    /**
     * The prompt template. Two placeholders are filled by render(), so the
     * action catalog stays the single source of truth for both the prompt and
     * the validator.
     */
    private static function template(): string
    {
        return <<<PROMPT
        You convert one spoken sentence from an automotive technician into a single JSON object.
        You are a parser. You do not answer questions, give repair advice, or add commentary.

        Reply with JSON only, in exactly this shape:
        {"action": "...", "target": {"type": "...", "value": "..."}, "params": {...}, "confidence": 0.0}

        Allowed actions — emit nothing outside this list:
        {actions}

        target.type is one of: context, ro_number, vin, plate, customer, vehicle, user, none.
          - "context" means the repair order already loaded. Use it for "this one", "it", "this truck".
          - Use ro_number / vin / plate / customer / vehicle when the technician names a specific car.
          - target.value carries the spoken identifier, cleaned up but not invented.

        confidence is your honest probability, 0 to 1, that this is what the technician meant.
        Speech in a loud shop is often misheard. A low number is useful; a guessed high number is not.
        If you are unsure which action applies, return your best guess with a low confidence rather
        than inventing an action name.

        Current situation:
        {context}

        PROMPT;
    }

    public static function render(IntentContext $context): string
    {
        return str_replace(
            ['{actions}', '{context}'],
            [ActionCatalog::promptLines(), $context->describe()],
            self::template()
        );
    }

    /** @param list<string> $serviceNames */
    public static function categoryMatch(string $category, array $serviceNames): string
    {
        $list = implode("\n", array_map(static fn (string $n): string => '- ' . $n, $serviceNames));

        return <<<PROMPT
        A repair shop records service under inconsistent names. Decide which of these past service
        names belong to the category "{$category}". Abbreviations are common ("BR-FRT" is front
        brakes). Include a name only if a technician would agree it belongs.

        Service names:
        {$list}

        Reply with JSON only: {"matches": ["exact name", ...]}
        Every string must be copied exactly from the list above. If none match, return an empty array.
        PROMPT;
    }

    public static function customerDraft(string $rawTranscript, ?string $vehicleDescription): string
    {
        $vehicle = $vehicleDescription !== null ? " on a {$vehicleDescription}" : '';

        return <<<PROMPT
        Rewrite a technician's dictated diagnosis{$vehicle} into plain language a customer can read.

        Rules, in order of importance:
        1. Never strengthen a finding. "Possible", "looks like", "might be" and "suspect" must survive
           as uncertainty. Do not turn a maybe into a definitely.
        2. Never add a cause, a part, a symptom or a recommendation the technician did not say.
        3. Keep it to two or three short sentences, no jargon, no scare language, no pricing.
        4. If the dictation is too fragmentary to rewrite honestly, say so instead of filling gaps.

        This draft is reviewed by a person before a customer ever sees it. Accuracy beats polish.

        Technician's words:
        {$rawTranscript}

        Reply with JSON only: {"draft": "..."}
        PROMPT;
    }

    /** @param list<array{field_key:string,label:string,unit:?string}> $fields */
    public static function inspectionSlot(string $transcript, array $fields): string
    {
        $list = implode("\n", array_map(
            static fn (array $f): string => sprintf(
                '- %s (%s)%s',
                $f['field_key'],
                $f['label'],
                $f['unit'] !== null && $f['unit'] !== '' ? ', measured in ' . $f['unit'] : ''
            ),
            $fields
        ));

        return <<<PROMPT
        A technician is walking a vehicle calling out inspection findings in whatever order suits the
        car. Slot this one finding into the correct sheet field.

        Sheet fields:
        {$list}

        Reply with JSON only:
        {"field_key": "...", "value": "...", "condition": "green|yellow|red", "confidence": 0.0}

        value is the measurement or observation as spoken ("4mm", "holds 12.4 volts", "seeping").
        condition is the technician's own colour call if they gave one; otherwise infer it only when
        it is obvious, and lower your confidence when you do.
        If no field fits, return field_key null with a low confidence. Do not force a match.
        PROMPT;
    }
}
