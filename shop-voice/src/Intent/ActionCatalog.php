<?php
declare(strict_types=1);

namespace ShopVoice\Intent;

/**
 * The closed action list (§4) — the single source of truth.
 *
 * Both the validator's whitelist and the model's prompt are generated from this
 * table, so the prompt cannot drift from what the server will actually accept.
 * Anything the model emits outside this list is rejected, not guessed at.
 */
final class ActionCatalog
{
    public const GET_ORDER = 'get_order';
    public const GET_HISTORY = 'get_history';
    public const GET_DIAGRAM = 'get_diagram';
    public const GET_SPECS = 'get_specs';
    public const ADD_NOTE = 'add_note';
    public const START_INSPECTION = 'start_inspection';
    public const ADD_INSPECTION_ITEM = 'add_inspection_item';
    public const END_INSPECTION = 'end_inspection';
    public const SWITCH_USER = 'switch_user';
    public const RELEASE_CONTEXT = 'release_context';
    public const UNDO = 'undo';
    public const SET_STATUS = 'set_status';
    public const EDIT_PRICE = 'edit_price';

    /**
     * @var array<string,array{tier:RiskTier,params:list<string>,description:string}>
     */
    private const ACTIONS = [
        self::GET_ORDER => [
            'tier' => RiskTier::Read,
            'params' => [],
            'description' => 'Load a repair order into context.',
        ],
        self::GET_HISTORY => [
            'tier' => RiskTier::Read,
            'params' => ['service_category'],
            'description' => 'Past service on this vehicle, optionally filtered to a category such as brakes.',
        ],
        self::GET_DIAGRAM => [
            'tier' => RiskTier::Read,
            'params' => ['system', 'component'],
            'description' => 'Open a wiring diagram or procedure page.',
        ],
        self::GET_SPECS => [
            'tier' => RiskTier::Read,
            'params' => ['spec_type', 'component'],
            'description' => 'Fluids, torque specs and capacities.',
        ],
        self::ADD_NOTE => [
            'tier' => RiskTier::LowRiskWrite,
            'params' => ['body'],
            'description' => 'Dictate an internal note onto the loaded order.',
        ],
        self::START_INSPECTION => [
            'tier' => RiskTier::Mode,
            'params' => ['template'],
            'description' => 'Open a persistent inspection listening session.',
        ],
        self::ADD_INSPECTION_ITEM => [
            'tier' => RiskTier::LowRiskWrite,
            'params' => ['field_key', 'value', 'condition'],
            'description' => 'Record one inspection finding. Only valid inside inspection mode.',
        ],
        self::END_INSPECTION => [
            'tier' => RiskTier::Mode,
            'params' => [],
            'description' => 'Close the inspection and read back what is missing.',
        ],
        self::SWITCH_USER => [
            'tier' => RiskTier::Session,
            'params' => ['user_name'],
            'description' => 'Claim the session for a different shop user.',
        ],
        self::RELEASE_CONTEXT => [
            'tier' => RiskTier::Session,
            'params' => [],
            'description' => 'Unload the current repair order.',
        ],
        self::UNDO => [
            'tier' => RiskTier::Session,
            'params' => [],
            'description' => 'Reverse the last low-risk write, within 30 seconds.',
        ],
        self::SET_STATUS => [
            'tier' => RiskTier::HighRisk,
            'params' => ['status'],
            'description' => 'Change the order status. Requires a tap on the tablet.',
        ],
        self::EDIT_PRICE => [
            'tier' => RiskTier::HighRisk,
            'params' => ['service_id', 'amount'],
            'description' => 'Change a price. Requires a tap on the tablet.',
        ],
    ];

    /**
     * `delete_*` in the spec table is a family, not one action. Anything in it is
     * high-risk by construction, so a delete verb we have not thought of yet
     * still lands on the tap path rather than sliding through as unknown.
     *
     * @var list<string>
     */
    private const DELETE_ACTIONS = ['delete_note', 'delete_service', 'delete_inspection_item'];

    public static function exists(string $action): bool
    {
        return isset(self::ACTIONS[$action]) || self::isDelete($action);
    }

    public static function isDelete(string $action): bool
    {
        return str_starts_with($action, 'delete_');
    }

    public static function tier(string $action): ?RiskTier
    {
        if (self::isDelete($action)) {
            return RiskTier::HighRisk;
        }
        return self::ACTIONS[$action]['tier'] ?? null;
    }

    /** @return list<string> */
    public static function params(string $action): array
    {
        if (self::isDelete($action)) {
            return ['target_id'];
        }
        return self::ACTIONS[$action]['params'] ?? [];
    }

    public static function description(string $action): string
    {
        if (self::isDelete($action)) {
            return 'Delete a record. Requires a tap on the tablet.';
        }
        return self::ACTIONS[$action]['description'] ?? '';
    }

    /** @return list<string> */
    public static function all(): array
    {
        return [...array_keys(self::ACTIONS), ...self::DELETE_ACTIONS];
    }

    /** Actions the model is allowed to emit, formatted for the prompt. */
    public static function promptLines(): string
    {
        $lines = [];
        foreach (self::all() as $action) {
            $params = self::params($action);
            $lines[] = sprintf(
                '- %s%s — %s',
                $action,
                $params === [] ? '' : ' (params: ' . implode(', ', $params) . ')',
                self::description($action)
            );
        }
        return implode("\n", $lines);
    }
}
