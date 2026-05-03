-- MVP Extract — SQLite schema
PRAGMA foreign_keys = ON;

-- Each scan run
CREATE TABLE IF NOT EXISTS scans (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    started_at      TEXT NOT NULL,
    finished_at     TEXT,
    total_fetched   INTEGER,
    total_mvps      INTEGER,
    new_count       INTEGER DEFAULT 0,
    updated_count   INTEGER DEFAULT 0,
    left_count      INTEGER DEFAULT 0,
    notes           TEXT
);

-- Master MVP record (current state)
CREATE TABLE IF NOT EXISTS mvps (
    id                          INTEGER PRIMARY KEY,                 -- mvp.microsoft.com user id
    user_profile_identifier     TEXT UNIQUE NOT NULL,                -- GUID
    first_name                  TEXT,
    last_name                   TEXT,
    localized_first_name        TEXT,
    localized_last_name         TEXT,
    title                       TEXT,
    headline                    TEXT,
    biography                   TEXT,
    country                     TEXT,
    country_loc_key             TEXT,
    gender                      TEXT,
    gender_pronoun              TEXT,
    level_id                    INTEGER,
    level_name                  TEXT,
    languages                   TEXT,                                -- JSON array
    tenants                     TEXT,                                -- JSON array (MVP, RD, MSP)
    picture_url                 TEXT,                                -- computed image URL
    first_awarded_date          TEXT,                                -- ISO from API (may be epoch)
    program_entry_date          TEXT,                                -- our computed entry date
    is_private                  INTEGER DEFAULT 0,
    -- enriched from /api/mvp/UserProfiles/public/{identifier}
    years_in_program_api        INTEGER,
    award_category              TEXT,                                -- JSON array
    technology_focus_area       TEXT,                                -- JSON array
    functional_roles            TEXT,                                -- JSON array
    company_name                TEXT,
    company_role                TEXT,
    enriched_at                 TEXT,
    -- scan tracking
    first_seen_at               TEXT NOT NULL,
    first_seen_scan_id          INTEGER REFERENCES scans(id),
    last_seen_at                TEXT NOT NULL,
    last_seen_scan_id           INTEGER REFERENCES scans(id),
    left_at                     TEXT,                                -- date detected as missing
    is_active                   INTEGER DEFAULT 1                    -- 0 once detected as left
);

CREATE INDEX IF NOT EXISTS ix_mvps_country  ON mvps(country);
CREATE INDEX IF NOT EXISTS ix_mvps_active   ON mvps(is_active);
CREATE INDEX IF NOT EXISTS ix_mvps_entry    ON mvps(program_entry_date);

-- Social networks (one row per network per MVP)
CREATE TABLE IF NOT EXISTS mvp_social_networks (
    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
    mvp_id              INTEGER NOT NULL REFERENCES mvps(id) ON DELETE CASCADE,
    network_id          INTEGER,
    network_name        TEXT,
    handle              TEXT,
    url                 TEXT,
    UNIQUE(mvp_id, network_id, handle)
);
CREATE INDEX IF NOT EXISTS ix_sn_mvp ON mvp_social_networks(mvp_id);

-- Schools / education
CREATE TABLE IF NOT EXISTS mvp_schools (
    id                          INTEGER PRIMARY KEY AUTOINCREMENT,
    mvp_id                      INTEGER NOT NULL REFERENCES mvps(id) ON DELETE CASCADE,
    school_id                   INTEGER,
    school_name                 TEXT,
    program_name                TEXT,
    degree_level                TEXT,
    country                     TEXT,
    state                       TEXT,
    expected_graduation_date    TEXT,
    UNIQUE(mvp_id, school_id)
);

-- History of all changes detected on each scan (audit trail)
CREATE TABLE IF NOT EXISTS mvp_history (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    mvp_id          INTEGER NOT NULL REFERENCES mvps(id) ON DELETE CASCADE,
    scan_id         INTEGER NOT NULL REFERENCES scans(id),
    changed_at      TEXT NOT NULL,
    change_type     TEXT NOT NULL,    -- 'created' | 'updated' | 'left' | 'returned'
    field_name      TEXT,             -- null for created/left/returned
    old_value       TEXT,
    new_value       TEXT
);
CREATE INDEX IF NOT EXISTS ix_hist_mvp ON mvp_history(mvp_id);
CREATE INDEX IF NOT EXISTS ix_hist_scan ON mvp_history(scan_id);

-- Featured Activities from /api/Contributions/HighImpact/{identifier}/MVP
CREATE TABLE IF NOT EXISTS mvp_contributions (
    id              INTEGER PRIMARY KEY,   -- Microsoft's activity id
    mvp_id          INTEGER NOT NULL REFERENCES mvps(id) ON DELETE CASCADE,
    title           TEXT,
    description     TEXT,
    date            TEXT,                  -- ISO date of the activity
    image_url       TEXT,
    url             TEXT,
    type_name       TEXT,
    category_name   TEXT,
    first_seen_at   TEXT NOT NULL,
    last_seen_at    TEXT NOT NULL,
    removed_at      TEXT                   -- NULL = still present; set when API no longer returns it
);
CREATE INDEX IF NOT EXISTS ix_contrib_mvp     ON mvp_contributions(mvp_id);
CREATE INDEX IF NOT EXISTS ix_contrib_removed ON mvp_contributions(removed_at);

-- Events from /api/Events/HighImpact/{identifier}/MVP
CREATE TABLE IF NOT EXISTS mvp_events (
    id              INTEGER PRIMARY KEY,   -- Microsoft's event id
    mvp_id          INTEGER NOT NULL REFERENCES mvps(id) ON DELETE CASCADE,
    title           TEXT,
    description     TEXT,
    date_start      TEXT,
    date_end        TEXT,
    event_uri       TEXT,
    first_seen_at   TEXT NOT NULL,
    last_seen_at    TEXT NOT NULL,
    removed_at      TEXT                   -- NULL = still present; set when API no longer returns it
);
CREATE INDEX IF NOT EXISTS ix_events_mvp     ON mvp_events(mvp_id);
CREATE INDEX IF NOT EXISTS ix_events_removed ON mvp_events(removed_at);

-- Snapshot table: one row per MVP per scan with raw JSON (point-in-time)
CREATE TABLE IF NOT EXISTS mvp_snapshots (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    mvp_id          INTEGER NOT NULL REFERENCES mvps(id) ON DELETE CASCADE,
    scan_id         INTEGER NOT NULL REFERENCES scans(id),
    captured_at     TEXT NOT NULL,
    raw_json        TEXT NOT NULL,
    UNIQUE(mvp_id, scan_id)
);

-- Country geocoding cache (lat/lng for map)
CREATE TABLE IF NOT EXISTS country_geo (
    country     TEXT PRIMARY KEY,
    latitude    REAL,
    longitude   REAL
);
