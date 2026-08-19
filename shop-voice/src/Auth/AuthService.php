<?php
declare(strict_types=1);

namespace ShopVoice\Auth;

use ShopVoice\Db\Db;
use ShopVoice\Shopmonkey\Order;
use ShopVoice\Shopmonkey\ShopmonkeyGateway;
use ShopVoice\Support\Clock;
use ShopVoice\Support\Id;
use ShopVoice\Support\Logger;

/**
 * Devices, users, sessions and the trust boundary around them (§7).
 *
 * Two rules shape everything here:
 *
 *   The tablet never holds the Shopmonkey token. Tablets get stolen and left in
 *   bays. A device authenticates to us with a device token; we hold the
 *   third-party credentials and can revoke a device from the server.
 *
 *   Identity is the Shopmonkey user id, carried verbatim. `user` is one of the
 *   eleven documented objects, so a write lands with the right author and no
 *   mapping table. That is not tidiness — MCL 257.1313b puts the mechanic's
 *   name and certification number on the invoice for diagnosis and repair, so
 *   attribution cannot be approximate.
 */
final class AuthService
{
    public function __construct(
        private Db $db,
        private ShopmonkeyGateway $gateway,
        private Logger $logger,
        private Clock $clock = new Clock(),
        private int $idleMinutes = 720,
    ) {
    }

    // --- users ------------------------------------------------------------

    /**
     * Pull the shop's users from Shopmonkey into our table.
     *
     * We mirror rather than own: the id stays theirs, so nothing here can drift
     * out of step with who is real in Shopmonkey.
     *
     * @return int number of users synced
     */
    public function syncUsers(): int
    {
        $users = $this->gateway->users();
        $now = $this->clock->iso();

        foreach ($users as $user) {
            $existing = $this->db->first('SELECT id FROM users WHERE id = :id', ['id' => $user['id']]);
            $fields = [
                'name' => $user['name'],
                'email' => $user['email'],
                'role' => $user['role'],
                'cert_number' => $user['cert_number'],
                'updated_at' => $now,
            ];

            if ($existing === null) {
                // pin_hash is deliberately left null: a user cannot approve a
                // high-risk write until someone sets a PIN on the tablet.
                $this->db->insert('users', $fields + ['id' => $user['id'], 'active' => 1, 'created_at' => $now]);
            } else {
                $this->db->update('users', $fields, ['id' => $user['id']]);
            }
        }

        $this->logger->info('auth.users_synced', ['count' => count($users), 'source' => $this->gateway->describe()]);
        return count($users);
    }

    /** @return list<array<string,mixed>> */
    public function activeUsers(): array
    {
        return $this->db->all(
            'SELECT id, name, role, cert_number, (pin_hash IS NOT NULL) AS has_pin
             FROM users WHERE active = 1 ORDER BY name'
        );
    }

    public function setPin(string $userId, string $pin): void
    {
        if (!preg_match('/^\d{4,8}$/', $pin)) {
            throw new AuthException('PIN must be 4 to 8 digits.', 422);
        }

        $this->db->update(
            'users',
            ['pin_hash' => password_hash($pin, PASSWORD_DEFAULT), 'updated_at' => $this->clock->iso()],
            ['id' => $userId]
        );
    }

    // --- devices ----------------------------------------------------------

    /** Admin generates a code; the tablet trades it for a device token, once. */
    public function createEnrollmentCode(string $deviceName, int $ttlMinutes = 60): string
    {
        $code = Id::enrollmentCode();
        $this->db->insert('enrollment_codes', [
            'code' => $code,
            'device_name' => $deviceName,
            'created_at' => $this->clock->iso(),
            'expires_at' => $this->clock->now()->modify("+{$ttlMinutes} minutes")
                ->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
        ]);
        return $code;
    }

    /** @return array{device_id:string,device_name:string,token:string} */
    public function enrollDevice(string $code): array
    {
        $row = $this->db->first('SELECT * FROM enrollment_codes WHERE code = :code', ['code' => strtoupper(trim($code))]);

        if ($row === null) {
            throw new AuthException('That enrollment code is not valid.');
        }
        if ($row['used_at'] !== null) {
            throw new AuthException('That enrollment code has already been used.');
        }
        if ((string) $row['expires_at'] < $this->clock->iso()) {
            throw new AuthException('That enrollment code has expired.');
        }

        $token = Id::token();
        $deviceId = Id::generate($this->clock);
        $now = $this->clock->iso();

        $this->db->transaction(function (Db $db) use ($deviceId, $row, $token, $now): void {
            $db->insert('devices', [
                'id' => $deviceId,
                'name' => $row['device_name'],
                'token_hash' => Id::hash($token),
                'wake_word_enabled' => 1,
                'enrolled_at' => $now,
            ]);
            $db->update('enrollment_codes', ['used_at' => $now, 'device_id' => $deviceId], ['code' => $row['code']]);
        });

        $this->logger->info('auth.device_enrolled', ['device_id' => $deviceId, 'name' => $row['device_name']]);

        // The raw token is returned exactly once, here.
        return ['device_id' => $deviceId, 'device_name' => (string) $row['device_name'], 'token' => $token];
    }

    /** @return array<string,mixed> */
    public function authenticateDevice(string $deviceToken): array
    {
        $device = $this->db->first(
            'SELECT * FROM devices WHERE token_hash = :hash AND revoked_at IS NULL',
            ['hash' => Id::hash($deviceToken)]
        );

        if ($device === null) {
            throw new AuthException('Unknown or revoked device.');
        }

        $this->db->update('devices', ['last_seen_at' => $this->clock->iso()], ['id' => $device['id']]);
        return $device;
    }

    public function setWakeWordEnabled(string $deviceId, bool $enabled): void
    {
        // One obvious switch, not buried in settings (§9). A bay next to a
        // compressor may be hopeless, and push-to-talk still works.
        $this->db->update('devices', ['wake_word_enabled' => $enabled ? 1 : 0], ['id' => $deviceId]);
    }

    public function revokeDevice(string $deviceId): void
    {
        $now = $this->clock->iso();
        $this->db->update('devices', ['revoked_at' => $now], ['id' => $deviceId]);
        $this->db->run(
            'UPDATE sessions SET ended_at = :now, end_reason = :reason
             WHERE device_id = :device AND ended_at IS NULL',
            ['now' => $now, 'reason' => 'device_revoked', 'device' => $deviceId]
        );
        $this->logger->warn('auth.device_revoked', ['device_id' => $deviceId]);
    }

    // --- sessions ---------------------------------------------------------

    /** @return array{session:Session,token:string} */
    public function login(string $deviceToken, string $userId, ?string $pin = null): array
    {
        $device = $this->authenticateDevice($deviceToken);
        $user = $this->requireUser($userId);

        // A PIN is optional at login and required only to clear the high-risk
        // gate. Making it mandatory at shift start would push techs to share one.
        if ($pin !== null && !$this->pinMatches($user, $pin)) {
            throw new AuthException('That PIN is not right.');
        }

        return $this->startSession(
            (string) $device['id'],
            $user,
            'login',
            $pin !== null && $user['pin_hash'] !== null
        );
    }

    /**
     * "Hey, this is Russ, switch to my user." (§7)
     *
     * Solves the walk-off problem by making logout irrelevant: whoever speaks
     * next claims the session. The previous session ends, which clears its
     * context — a note must never land on the previous tech's car.
     *
     * @return array{session:Session,token:string}
     */
    public function voiceSwitch(Session $current, string $spokenName): array
    {
        $user = $this->findUserByName($spokenName);
        if ($user === null) {
            throw new AuthException("I don't have a user called {$spokenName}.", 404);
        }

        $this->endSession($current->id, 'voice_switch');

        $result = $this->startSession($current->deviceId, $user, 'voice_switch', false);

        $this->audit($result['session'], 'switch_user', 'session', 'voice', [
            'from_user' => $current->userId,
            'to_user' => $user['id'],
            'spoken_name' => $spokenName,
        ]);

        return $result;
    }

    /**
     * Clear the §7 identity gate with a tap or a PIN on the tablet.
     *
     * `tap` alone is accepted only for a user who has no PIN set: it is the
     * shop's own tablet in the shop's own bay, and demanding a PIN nobody has
     * configured would just mean high-risk writes never work. Once a PIN
     * exists, it is required.
     */
    public function unlockHighRisk(Session $session, ?string $pin = null): Session
    {
        $user = $this->requireUser($session->userId);

        if ($user['pin_hash'] !== null) {
            if ($pin === null || !$this->pinMatches($user, $pin)) {
                throw new AuthException('That PIN is not right.');
            }
        }

        $now = $this->clock->iso();
        $this->db->update('sessions', ['high_risk_unlocked_at' => $now], ['id' => $session->id]);

        $this->audit($session, 'unlock_high_risk', 'session', $user['pin_hash'] !== null ? 'pin' : 'tap', []);

        return $this->requireSessionById($session->id);
    }

    public function sessionFromToken(string $sessionToken): Session
    {
        $row = $this->db->first(
            'SELECT * FROM sessions WHERE token_hash = :hash',
            ['hash' => Id::hash($sessionToken)]
        );

        if ($row === null) {
            throw new AuthException('Not signed in on this tablet.');
        }

        if ($row['ended_at'] !== null) {
            throw new AuthException(match ($row['end_reason']) {
                'nightly' => 'That session was closed overnight — log back in.',
                'voice_switch' => 'Someone else claimed this tablet.',
                default => 'That session has ended.',
            });
        }

        if ($this->isIdle($row)) {
            $this->endSession((string) $row['id'], 'idle');
            throw new AuthException('That session timed out.');
        }

        $this->db->update('sessions', ['last_activity_at' => $this->clock->iso()], ['id' => $row['id']]);

        return $this->hydrate($row);
    }

    public function requireSessionById(string $sessionId): Session
    {
        $row = $this->db->first('SELECT * FROM sessions WHERE id = :id', ['id' => $sessionId]);
        if ($row === null) {
            throw new AuthException('Session not found.', 404);
        }
        return $this->hydrate($row);
    }

    public function endSession(string $sessionId, string $reason): void
    {
        $this->db->run(
            'UPDATE sessions SET ended_at = :now, end_reason = :reason WHERE id = :id AND ended_at IS NULL',
            ['now' => $this->clock->iso(), 'reason' => $reason, 'id' => $sessionId]
        );
    }

    /**
     * Nightly auto-logout, so no session runs silently into the next day (§7).
     * Driven by cron — see tools/nightly_logout.php.
     */
    public function endAllSessions(string $reason = 'nightly'): int
    {
        $stmt = $this->db->run(
            'UPDATE sessions SET ended_at = :now, end_reason = :reason WHERE ended_at IS NULL',
            ['now' => $this->clock->iso(), 'reason' => $reason]
        );
        $count = $stmt->rowCount();
        $this->logger->info('auth.sessions_ended', ['count' => $count, 'reason' => $reason]);
        return $count;
    }

    // --- context ----------------------------------------------------------

    /**
     * Load an RO into the session. Sticky: it stays until explicitly released,
     * a different RO is requested, the user switches, or the nightly logout
     * fires (§5).
     */
    public function loadContext(Session $session, Order $order): Session
    {
        $this->db->update('sessions', [
            'context_ro_number' => $order->number,
            'context_order_id' => $order->id,
            'context_vehicle_id' => $order->vehicle->id,
            'context_label' => $order->readbackLabel(),
            'context_loaded_at' => $this->clock->iso(),
        ], ['id' => $session->id]);

        return $this->requireSessionById($session->id);
    }

    public function releaseContext(Session $session): Session
    {
        $this->db->update('sessions', [
            'context_ro_number' => null,
            'context_order_id' => null,
            'context_vehicle_id' => null,
            'context_label' => null,
            'context_loaded_at' => null,
        ], ['id' => $session->id]);

        return $this->requireSessionById($session->id);
    }

    // --- audit ------------------------------------------------------------

    /** @param array<string,mixed> $detail */
    public function audit(Session $session, string $action, string $tier, string $via, array $detail = [], ?string $targetType = null, ?string $targetId = null): void
    {
        $this->db->insert('audit_log', [
            'id' => Id::generate($this->clock),
            'session_id' => $session->id,
            'user_id' => $session->userId,
            'device_id' => $session->deviceId,
            'action' => $action,
            'risk_tier' => $tier,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'via' => $via,
            'detail' => $detail === [] ? null : json_encode($detail, JSON_UNESCAPED_SLASHES),
            'created_at' => $this->clock->iso(),
        ]);
    }

    // --- internals --------------------------------------------------------

    /**
     * @param array<string,mixed> $user
     * @return array{session:Session,token:string}
     */
    private function startSession(string $deviceId, array $user, string $claimedVia, bool $unlocked): array
    {
        $token = Id::token();
        $sessionId = Id::generate($this->clock);
        $now = $this->clock->iso();

        // One live session per tablet: the bay client is a single-user surface,
        // and a stale session left open is exactly the walk-off problem.
        $this->db->run(
            'UPDATE sessions SET ended_at = :now, end_reason = :reason WHERE device_id = :device AND ended_at IS NULL',
            ['now' => $now, 'reason' => 'superseded', 'device' => $deviceId]
        );

        $this->db->insert('sessions', [
            'id' => $sessionId,
            'device_id' => $deviceId,
            'user_id' => $user['id'],
            'token_hash' => Id::hash($token),
            'claimed_via' => $claimedVia,
            'high_risk_unlocked_at' => $unlocked ? $now : null,
            'started_at' => $now,
            'last_activity_at' => $now,
        ]);

        $this->logger->info('auth.session_started', [
            'session_id' => $sessionId,
            'user_id' => $user['id'],
            'device_id' => $deviceId,
            'claimed_via' => $claimedVia,
        ]);

        return ['session' => $this->requireSessionById($sessionId), 'token' => $token];
    }

    /** @param array<string,mixed> $row */
    private function hydrate(array $row): Session
    {
        $user = $this->db->first('SELECT name, role FROM users WHERE id = :id', ['id' => $row['user_id']]);
        return Session::fromRow(
            $row,
            (string) ($user['name'] ?? 'Unknown'),
            (string) ($user['role'] ?? 'tech')
        );
    }

    /** @return array<string,mixed> */
    private function requireUser(string $userId): array
    {
        $user = $this->db->first('SELECT * FROM users WHERE id = :id AND active = 1', ['id' => $userId]);
        if ($user === null) {
            throw new AuthException('Unknown user.', 404);
        }
        return $user;
    }

    /** @return array<string,mixed>|null */
    private function findUserByName(string $spokenName): ?array
    {
        $name = trim($spokenName);
        if ($name === '') {
            return null;
        }

        $exact = $this->db->first(
            'SELECT * FROM users WHERE active = 1 AND LOWER(name) = LOWER(:name)',
            ['name' => $name]
        );
        if ($exact !== null) {
            return $exact;
        }

        // Techs say first names. Only accept a partial match when it is
        // unambiguous — two Dannys must not silently resolve to one of them.
        $matches = $this->db->all(
            'SELECT * FROM users WHERE active = 1 AND LOWER(name) LIKE LOWER(:needle)',
            ['needle' => $name . '%']
        );
        return count($matches) === 1 ? $matches[0] : null;
    }

    /** @param array<string,mixed> $user */
    private function pinMatches(array $user, string $pin): bool
    {
        $hash = $user['pin_hash'] ?? null;
        return is_string($hash) && password_verify($pin, $hash);
    }

    /** @param array<string,mixed> $row */
    private function isIdle(array $row): bool
    {
        $cutoff = $this->clock->now()
            ->modify("-{$this->idleMinutes} minutes")
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s\Z');

        return (string) $row['last_activity_at'] < $cutoff;
    }
}
