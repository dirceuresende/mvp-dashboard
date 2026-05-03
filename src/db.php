<?php
declare(strict_types=1);

define('DB_PATH', dirname(__DIR__) . '/data/mvps.sqlite');
define('SCHEMA_PATH', dirname(__DIR__) . '/app/schema.sql');

function getConnection(): PDO
{
    $dir = dirname(DB_PATH);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $pdo = new PDO('sqlite:' . DB_PATH);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA journal_mode=WAL');
    $pdo->exec('PRAGMA busy_timeout=30000');  // wait up to 30s before "database is locked"
    $pdo->exec('PRAGMA synchronous=NORMAL');
    $pdo->exec('PRAGMA cache_size=-20000');   // 20 MB page cache
    $pdo->exec('PRAGMA temp_store=MEMORY');
    $pdo->exec('PRAGMA mmap_size=134217728'); // 128 MB memory-mapped I/O
    return $pdo;
}

function initDb(): PDO
{
    $pdo = getConnection();
    $schema = file_get_contents(SCHEMA_PATH);
    $pdo->exec($schema);

    require_once __DIR__ . '/countries.php';
    $geoCount = (int)$pdo->query("SELECT COUNT(*) FROM country_geo")->fetchColumn();
    if ($geoCount === 0) {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare(
            'INSERT OR REPLACE INTO country_geo(country, latitude, longitude) VALUES (?,?,?)'
        );
        foreach (COUNTRY_COORDS as $country => $coords) {
            $stmt->execute([$country, $coords[0], $coords[1]]);
        }
        $pdo->commit();
    }

    migrateDb($pdo);
    return $pdo;
}

function migrateDb(PDO $pdo): void
{
    $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('mvps', $tables, true)) {
        return;
    }
    $existing = array_column($pdo->query('PRAGMA table_info(mvps)')->fetchAll(), 'name');
    $newCols = [
        ['years_in_program_api',   'INTEGER'],
        ['award_category',         'TEXT'],
        ['technology_focus_area',  'TEXT'],
        ['functional_roles',       'TEXT'],
        ['company_name',           'TEXT'],
        ['company_role',           'TEXT'],
        ['enriched_at',            'TEXT'],
        ['activities_updated_at',  'TEXT'],
    ];
    foreach ($newCols as [$col, $type]) {
        if (!in_array($col, $existing, true)) {
            $pdo->exec("ALTER TABLE mvps ADD COLUMN {$col} {$type}");
        }
    }

    // Create contribution/event tables if they don't exist yet (idempotent)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS mvp_contributions (
            id            INTEGER PRIMARY KEY,
            mvp_id        INTEGER NOT NULL REFERENCES mvps(id) ON DELETE CASCADE,
            title         TEXT,
            description   TEXT,
            date          TEXT,
            image_url     TEXT,
            url           TEXT,
            type_name     TEXT,
            category_name TEXT,
            first_seen_at TEXT NOT NULL,
            last_seen_at  TEXT NOT NULL,
            removed_at    TEXT
        );
        CREATE INDEX IF NOT EXISTS ix_contrib_mvp     ON mvp_contributions(mvp_id);
        CREATE INDEX IF NOT EXISTS ix_contrib_removed ON mvp_contributions(removed_at);

        CREATE TABLE IF NOT EXISTS mvp_events (
            id          INTEGER PRIMARY KEY,
            mvp_id      INTEGER NOT NULL REFERENCES mvps(id) ON DELETE CASCADE,
            title       TEXT,
            description TEXT,
            date_start  TEXT,
            date_end    TEXT,
            event_uri   TEXT,
            first_seen_at TEXT NOT NULL,
            last_seen_at  TEXT NOT NULL,
            removed_at    TEXT
        );
        CREATE INDEX IF NOT EXISTS ix_events_mvp     ON mvp_events(mvp_id);
        CREATE INDEX IF NOT EXISTS ix_events_removed ON mvp_events(removed_at);
    ");
}
