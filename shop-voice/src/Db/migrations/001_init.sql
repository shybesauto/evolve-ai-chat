-- Shop Voice Assistant — initial schema.
--
-- Portability note: every primary key is an application-generated ULID-ish
-- string and every timestamp is an ISO-8601 UTC string in a VARCHAR. That is
-- deliberate:
--   * app-generated ids let the bay client mint an id for a note it dictated
--     while offline, so the queue flush is idempotent (§10);
--   * ISO strings sort lexicographically and behave identically on MySQL
--     (production, cPanel) and SQLite (the test suite), so one DDL file serves
--     both with no dialect translation.

CREATE TABLE users (
    id              VARCHAR(40) NOT NULL PRIMARY KEY,   -- Shopmonkey userId, verbatim (§7)
    name            VARCHAR(120) NOT NULL,
    email           VARCHAR(190),
    role            VARCHAR(20) NOT NULL DEFAULT 'tech', -- tech | advisor | admin
    cert_number     VARCHAR(60),                         -- MCL 257.1313b state certification
    pin_hash        VARCHAR(255),                        -- gates the first high-risk write after a voice switch
    active          INTEGER NOT NULL DEFAULT 1,
    created_at      VARCHAR(32) NOT NULL,
    updated_at      VARCHAR(32) NOT NULL
);
CREATE UNIQUE INDEX idx_users_email ON users (email);

CREATE TABLE devices (
    id                  VARCHAR(40) NOT NULL PRIMARY KEY,
    name                VARCHAR(60) NOT NULL,            -- "Bay 1"
    token_hash          VARCHAR(255) NOT NULL,           -- device token; the raw value is shown once at enrollment
    wake_word_enabled   INTEGER NOT NULL DEFAULT 1,      -- §9 per-device kill switch
    enrolled_at         VARCHAR(32) NOT NULL,
    last_seen_at        VARCHAR(32),
    revoked_at          VARCHAR(32)
);
CREATE UNIQUE INDEX idx_devices_token ON devices (token_hash);

CREATE TABLE enrollment_codes (
    code        VARCHAR(20) NOT NULL PRIMARY KEY,
    device_name VARCHAR(60) NOT NULL,
    created_at  VARCHAR(32) NOT NULL,
    expires_at  VARCHAR(32) NOT NULL,
    used_at     VARCHAR(32),
    device_id   VARCHAR(40)
);

CREATE TABLE sessions (
    id                      VARCHAR(40) NOT NULL PRIMARY KEY,
    device_id               VARCHAR(40) NOT NULL,
    user_id                 VARCHAR(40) NOT NULL,
    token_hash              VARCHAR(255) NOT NULL,
    claimed_via             VARCHAR(20) NOT NULL DEFAULT 'login',  -- login | voice_switch
    high_risk_unlocked_at   VARCHAR(32),                 -- set by a tap/PIN on the tablet (§7)
    -- Sticky context (§5) lives on the session, not the device: it follows the
    -- user, so a voice switch clears it by definition.
    context_ro_number       VARCHAR(40),
    context_order_id        VARCHAR(40),
    context_vehicle_id      VARCHAR(40),
    context_label           VARCHAR(190),                -- "RO 4471, Henderson's Tahoe"
    context_loaded_at       VARCHAR(32),
    started_at              VARCHAR(32) NOT NULL,
    last_activity_at        VARCHAR(32) NOT NULL,
    ended_at                VARCHAR(32),
    end_reason              VARCHAR(30)                  -- logout | voice_switch | nightly | idle
);
CREATE UNIQUE INDEX idx_sessions_token ON sessions (token_hash);
CREATE INDEX idx_sessions_device ON sessions (device_id, ended_at);

CREATE TABLE dictated_notes (
    id                      VARCHAR(40) NOT NULL PRIMARY KEY,
    client_uuid             VARCHAR(64) NOT NULL,        -- minted on the tablet; makes queue flush idempotent
    ro_number               VARCHAR(40) NOT NULL,
    order_id                VARCHAR(40),
    vehicle_id              VARCHAR(40),
    user_id                 VARCHAR(40) NOT NULL,
    device_id               VARCHAR(40),
    -- The tech's exact words. Never rewritten, never paraphrased (§4, §6).
    raw_transcript          TEXT NOT NULL,
    -- Model-generated plain-language version. Never posts on its own (§6).
    customer_draft          TEXT,
    customer_draft_edited   TEXT,                        -- advisor's wording, if they changed it
    accuracy_approved_at    VARCHAR(32),                 -- stage 1: tech, at the lift
    accuracy_approved_by    VARCHAR(40),
    customer_approved_at    VARCHAR(32),                 -- stage 2: advisor, at the desk (high-risk, tap only)
    customer_approved_by    VARCHAR(40),
    sync_state              VARCHAR(20) NOT NULL DEFAULT 'local_only', -- local_only | pending | synced | failed
    remote_note_id          VARCHAR(64),                 -- filled once a Shopmonkey write endpoint exists
    sync_error              TEXT,
    dictated_at             VARCHAR(32) NOT NULL,        -- when the tech spoke it (may predate arrival)
    created_at              VARCHAR(32) NOT NULL,
    undone_at               VARCHAR(32)
);
CREATE UNIQUE INDEX idx_notes_client_uuid ON dictated_notes (client_uuid);
CREATE INDEX idx_notes_ro ON dictated_notes (ro_number, created_at);
CREATE INDEX idx_notes_sync ON dictated_notes (sync_state);

CREATE TABLE inspection_templates (
    id          VARCHAR(40) NOT NULL PRIMARY KEY,
    name        VARCHAR(120) NOT NULL,
    active      INTEGER NOT NULL DEFAULT 1,
    created_at  VARCHAR(32) NOT NULL
);

CREATE TABLE inspection_template_items (
    id           VARCHAR(40) NOT NULL PRIMARY KEY,
    template_id  VARCHAR(40) NOT NULL,
    field_key    VARCHAR(60) NOT NULL,       -- front_brakes, battery_test, ...
    label        VARCHAR(120) NOT NULL,      -- "Front brakes", as printed on the sheet
    spoken_label VARCHAR(120),               -- "the battery test", as it reads in a sentence
    unit         VARCHAR(20),                -- mm, /32, V, psi
    required     INTEGER NOT NULL DEFAULT 1, -- drives the missing-items readback (§8)
    sort_order   INTEGER NOT NULL DEFAULT 0,
    synonyms     TEXT                        -- JSON array, seeds semantic slotting
);
CREATE INDEX idx_tpl_items_template ON inspection_template_items (template_id, sort_order);

CREATE TABLE inspections (
    id           VARCHAR(40) NOT NULL PRIMARY KEY,
    template_id  VARCHAR(40) NOT NULL,
    session_id   VARCHAR(40) NOT NULL,
    user_id      VARCHAR(40) NOT NULL,
    ro_number    VARCHAR(40) NOT NULL,
    order_id     VARCHAR(40),
    vehicle_id   VARCHAR(40),
    status       VARCHAR(20) NOT NULL DEFAULT 'open',  -- open | ended | timed_out
    started_at   VARCHAR(32) NOT NULL,
    last_item_at VARCHAR(32),
    ended_at     VARCHAR(32)
);
CREATE INDEX idx_inspections_session ON inspections (session_id, status);

CREATE TABLE inspection_items (
    id              VARCHAR(40) NOT NULL PRIMARY KEY,
    inspection_id   VARCHAR(40) NOT NULL,
    field_key       VARCHAR(60) NOT NULL,
    value           VARCHAR(120),
    condition_code  VARCHAR(10),             -- green | yellow | red
    raw_transcript  TEXT NOT NULL,           -- verbatim, same rule as notes
    recorded_at     VARCHAR(32) NOT NULL,
    undone_at       VARCHAR(32)
);
CREATE INDEX idx_items_inspection ON inspection_items (inspection_id, field_key);

CREATE TABLE undo_log (
    id           VARCHAR(40) NOT NULL PRIMARY KEY,
    session_id   VARCHAR(40) NOT NULL,
    user_id      VARCHAR(40) NOT NULL,
    action       VARCHAR(40) NOT NULL,       -- add_note | add_inspection_item
    target_type  VARCHAR(40) NOT NULL,
    target_id    VARCHAR(40) NOT NULL,
    summary      VARCHAR(190) NOT NULL,      -- spoken back on undo
    created_at   VARCHAR(32) NOT NULL,
    expires_at   VARCHAR(32) NOT NULL,       -- created_at + UNDO_WINDOW_SECONDS (§6)
    consumed_at  VARCHAR(32)
);
CREATE INDEX idx_undo_session ON undo_log (session_id, consumed_at, expires_at);

CREATE TABLE intent_log (
    id              VARCHAR(40) NOT NULL PRIMARY KEY,
    session_id      VARCHAR(40),
    user_id         VARCHAR(40),
    device_id       VARCHAR(40),
    provider        VARCHAR(40) NOT NULL,
    model           VARCHAR(80),
    raw_transcript  TEXT NOT NULL,
    action          VARCHAR(40),
    confidence      VARCHAR(10),
    accepted        INTEGER NOT NULL DEFAULT 0,
    reject_reason   VARCHAR(60),             -- unknown_action | low_confidence | schema | provider_error
    latency_ms      INTEGER,
    created_at      VARCHAR(32) NOT NULL
);
CREATE INDEX idx_intent_log_accepted ON intent_log (accepted, created_at);

CREATE TABLE audit_log (
    id           VARCHAR(40) NOT NULL PRIMARY KEY,
    session_id   VARCHAR(40),
    user_id      VARCHAR(40),
    device_id    VARCHAR(40),
    action       VARCHAR(40) NOT NULL,
    risk_tier    VARCHAR(20) NOT NULL,       -- read | low_risk | high_risk | mode | session
    target_type  VARCHAR(40),
    target_id    VARCHAR(64),
    via          VARCHAR(20) NOT NULL,       -- voice | tap | pin | system
    detail       TEXT,
    created_at   VARCHAR(32) NOT NULL
);
CREATE INDEX idx_audit_created ON audit_log (created_at);
