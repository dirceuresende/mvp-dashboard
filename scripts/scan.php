<?php
declare(strict_types=1);
/**
 * MVP Scraper — PHP CLI script.
 *
 * Fetches all MVP profiles from the Microsoft Maven API, upserts them into
 * SQLite, and maintains a full audit trail in mvp_history.
 *
 * WARNING (2026-07-24): this script still uses the LEGACY bulk search
 * endpoint (GET /api/UserProfiles/search/), which was found to be
 * stale/incomplete compared to what mvp.microsoft.com actually uses (it was
 * missing dozens of genuinely-active MVPs, causing false "left the program"
 * markings — see scripts/sync.php's header comment for the full story).
 * scripts/sync.php was rewritten to use the live paginated
 * POST /api/CommunityLeaders/search/ endpoint instead and is the actively
 * maintained, production entry point (called by cron and the setup UI).
 * Prefer `php scripts/sync.php --no-enrich` over this script until this one
 * is updated to match.
 *
 * Usage: php scripts/scan.php
 */

require_once __DIR__ . '/../src/db.php';
require_once __DIR__ . '/../src/helpers.php';

// NOTE (2026-07-24): mavenapi-prod.azurewebsites.net was retired/blocked by
// Microsoft (403 at the Azure edge). The API is still reachable at the same
// paths under mavenapi-prod.microsoft.com instead — only the domain changed.
define('API_URL', 'https://mavenapi-prod.microsoft.com/api/UserProfiles/search/?program=MVP&pageIndex=1&pageSize=18');
define('IMAGE_BASE', 'https://images.mvp.microsoft.com/');
define('USER_AGENT', 'Mozilla/5.0 MVP-Extract/1.0');
define('MIN_EXPECTED_PROFILES', 500);   // abort if API returns fewer than this
define('MAX_SUSPICIOUS_LEFT_PCT', 40);  // abort if >40% of active MVPs appear to have left in one scan

$TRACKED_FIELDS = [
    'first_name', 'last_name', 'localized_first_name', 'localized_last_name',
    'title', 'headline', 'biography', 'country', 'gender',
    'level_id', 'level_name', 'languages', 'tenants', 'picture_url',
    'first_awarded_date', 'is_private',
];

// ---------- Helpers ----------

function fetchAll(int $maxRetries = 3): array
{
    $lastErr = 'unknown';
    for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
        try {
            $ctx = stream_context_create(['http' => [
                'method'  => 'GET',
                'timeout' => 120,
                'header'  => 'User-Agent: ' . USER_AGENT . "\r\n",
            ]]);
            $body = @file_get_contents(API_URL, false, $ctx);
            if ($body === false) {
                throw new RuntimeException('HTTP request returned false');
            }
            $data     = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
            $profiles = $data['userProfiles'] ?? [];
            $mvps     = array_values(array_filter($profiles, fn($p) => in_array('MVP', $p['tenants'] ?? [], true)));
            fwrite(STDERR, sprintf("[INFO] Total profiles: %d / MVPs: %d\n", count($profiles), count($mvps)));
            return $mvps;
        } catch (Exception $e) {
            $lastErr = $e->getMessage();
            if ($attempt < $maxRetries) {
                $wait = 2 ** ($attempt - 1); // 1s, 2s
                fwrite(STDERR, sprintf("[WARN] Scan fetch attempt %d/%d failed: %s — retrying in %ds\n", $attempt, $maxRetries, $lastErr, $wait));
                sleep($wait);
            }
        }
    }
    throw new RuntimeException("fetchAll failed after {$maxRetries} attempts: {$lastErr}");
}

function profilePictureUrl(array $profile): ?string
{
    if (!empty($profile['profilePictureUrl'])) {
        return $profile['profilePictureUrl'];
    }
    $ident = $profile['userProfileIdentifier'] ?? null;
    return $ident ? IMAGE_BASE . $ident : null;
}

function mapProfile(array $profile): array
{
    $level = $profile['levelStatus'] ?? [];
    return [
        'id'                      => $profile['id'],
        'user_profile_identifier' => $profile['userProfileIdentifier'],
        'first_name'              => $profile['firstName'] ?? null,
        'last_name'               => $profile['lastName'] ?? null,
        'localized_first_name'    => $profile['localizedFirstName'] ?? null,
        'localized_last_name'     => $profile['localizedLastName'] ?? null,
        'title'                   => $profile['titleName'] ?? null,
        'headline'                => $profile['headline'] ?? null,
        'biography'               => $profile['biography'] ?? null,
        'country'                 => $profile['addressCountryOrRegionName'] ?? null,
        'country_loc_key'         => $profile['addressCountryOrRegionNameLocKey'] ?? null,
        'gender'                  => $profile['genderName'] ?? null,
        'gender_pronoun'          => $profile['genderPronounPreferenceName'] ?? null,
        'level_id'                => $level['id'] ?? null,
        'level_name'              => $level['levelName'] ?? null,
        'languages'               => json_encode($profile['languages'] ?? [], JSON_UNESCAPED_UNICODE),
        'tenants'                 => json_encode($profile['tenants'] ?? [], JSON_UNESCAPED_UNICODE),
        'picture_url'             => profilePictureUrl($profile),
        'first_awarded_date'      => parseFirstAwarded($profile['firstAwardedDate'] ?? null),
        'is_private'              => empty($profile['isPrivate']) ? 0 : 1,
    ];
}

function upsertSocialNetworks(PDO $pdo, int $mvpId, array $profile, array $stmts): void
{
    $networks = $profile['userProfileSocialNetwork'] ?? [];
    $existing = [];
    $stmts['snSelect']->execute([$mvpId]);
    foreach ($stmts['snSelect']->fetchAll() as $row) {
        $existing[$row['network_id'] . '|' . $row['handle']] = $row['id'];
    }

    $seen = [];
    foreach ($networks as $n) {
        $netId  = $n['socialNetworkId'] ?? null;
        $handle = $n['handle'] ?? null;
        if (!$handle) continue;
        $key = $netId . '|' . $handle;
        if (isset($seen[$key])) continue;
        $seen[$key] = true;

        if (isset($existing[$key])) {
            $stmts['snUpdate']->execute([$n['socialNetworkName'] ?? null, $n['socialNetworkImageLink'] ?? null, $existing[$key]]);
        } else {
            $stmts['snInsert']->execute([$mvpId, $netId, $n['socialNetworkName'] ?? null, $handle, $n['socialNetworkImageLink'] ?? null]);
        }
    }
    foreach ($existing as $key => $rowId) {
        if (!isset($seen[$key])) {
            $stmts['snDelete']->execute([$rowId]);
        }
    }
}

function upsertSchools(PDO $pdo, int $mvpId, array $profile, array $stmts): void
{
    $schools = $profile['userProfileSchool'] ?? [];
    $existing = [];
    $stmts['schSelect']->execute([$mvpId]);
    foreach ($stmts['schSelect']->fetchAll() as $row) {
        $existing[$row['school_id']] = $row['id'];
    }

    $seen = [];
    foreach ($schools as $s) {
        $sid = $s['schoolId'] ?? null;
        $seen[$sid] = true;
        $params = [
            $s['schoolName'] ?? null,
            $s['schoolDegreeProgramName'] ?? null,
            $s['schoolDegreeLevelName'] ?? null,
            $s['schoolCountryRegionName'] ?? null,
            $s['schoolStateProvinceName'] ?? null,
            $s['expectedGraduationDate'] ?? null,
        ];
        if (isset($existing[$sid])) {
            $stmts['schUpdate']->execute([...$params, $existing[$sid]]);
        } else {
            $stmts['schInsert']->execute([$mvpId, $sid, ...$params]);
        }
    }
    foreach ($existing as $sid => $rowId) {
        if (!isset($seen[$sid])) {
            $stmts['schDelete']->execute([$rowId]);
        }
    }
}

// ---------- Main ----------

$pdo = initDb();

$nowDt  = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$nowIso = $nowDt->format('Y-m-d\TH:i:sP');
$force  = in_array('--force', $argv ?? [], true);

// Bootstrap detection: first ever scan?
$isBootstrap = (int)$pdo->query("SELECT COUNT(*) FROM scans")->fetchColumn() === 0;

$scanStmt = $pdo->prepare("INSERT INTO scans(started_at) VALUES (?)");
$scanStmt->execute([$nowIso]);
$scanId = (int)$pdo->lastInsertId();

try {
    $profiles = fetchAll();
} catch (Exception $e) {
    $pdo->prepare("UPDATE scans SET finished_at=?, notes=? WHERE id=?")
        ->execute([$nowDt->format('Y-m-d\TH:i:sP'), 'ERROR: ' . $e->getMessage(), $scanId]);
    fwrite(STDERR, "[ERROR] " . $e->getMessage() . "\n");
    exit(1);
}

// ---- Safety guard: abort if API returned suspiciously few profiles ----
if (count($profiles) < MIN_EXPECTED_PROFILES) {
    $msg = sprintf(
        'SAFETY ABORT: API returned only %d MVPs (minimum expected: %d). '
            . 'Refusing to modify DB to prevent mass false deactivation.',
        count($profiles), MIN_EXPECTED_PROFILES
    );
    $pdo->prepare("UPDATE scans SET finished_at=?, notes=? WHERE id=?")
        ->execute([$nowDt->format('Y-m-d\TH:i:sP'), $msg, $scanId]);
    fwrite(STDERR, "[SAFETY] " . $msg . "\n");
    exit(2);
}

// ---- Pre-prepare all statements for performance ----
$stmts = [
    'selectMvp'       => $pdo->prepare("SELECT * FROM mvps WHERE id = ?"),
    'updateMvpSeen'   => $pdo->prepare(
        "UPDATE mvps SET last_seen_at=?, last_seen_scan_id=? WHERE id=?"
    ),
    'updateMvpReturn' => $pdo->prepare(
        "UPDATE mvps SET left_at=NULL, is_active=1 WHERE id=?"
    ),
    'updateMvpAll'    => $pdo->prepare(
        "UPDATE mvps SET
            first_name=?, last_name=?, localized_first_name=?, localized_last_name=?,
            title=?, headline=?, biography=?, country=?, gender=?,
            level_id=?, level_name=?, languages=?, tenants=?, picture_url=?,
            first_awarded_date=?, is_private=?,
            last_seen_at=?, last_seen_scan_id=?
        WHERE id=?"
    ),
    'insertMvp'       => $pdo->prepare(
        "INSERT INTO mvps(
            id, user_profile_identifier, first_name, last_name,
            localized_first_name, localized_last_name, title, headline,
            biography, country, country_loc_key, gender, gender_pronoun,
            level_id, level_name, languages, tenants, picture_url,
            first_awarded_date, program_entry_date, is_private,
            first_seen_at, first_seen_scan_id, last_seen_at, last_seen_scan_id, is_active
        ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,1)"
    ),
    'insertHistory'   => $pdo->prepare(
        "INSERT INTO mvp_history(mvp_id, scan_id, changed_at, change_type, field_name, old_value, new_value)
         VALUES (?,?,?,?,?,?,?)"
    ),
    'insertSnapshot'  => $pdo->prepare(
        "INSERT OR REPLACE INTO mvp_snapshots(mvp_id, scan_id, captured_at, raw_json) VALUES (?,?,?,?)"
    ),
    'markLeft'        => $pdo->prepare(
        "UPDATE mvps SET left_at=?, is_active=0 WHERE id=?"
    ),
    // Social networks
    'snSelect'  => $pdo->prepare("SELECT id, network_id, handle FROM mvp_social_networks WHERE mvp_id = ?"),
    'snUpdate'  => $pdo->prepare("UPDATE mvp_social_networks SET network_name=?, url=? WHERE id=?"),
    'snInsert'  => $pdo->prepare("INSERT INTO mvp_social_networks(mvp_id, network_id, network_name, handle, url) VALUES (?,?,?,?,?)"),
    'snDelete'  => $pdo->prepare("DELETE FROM mvp_social_networks WHERE id = ?"),
    // Schools
    'schSelect' => $pdo->prepare("SELECT id, school_id FROM mvp_schools WHERE mvp_id = ?"),
    'schUpdate' => $pdo->prepare("UPDATE mvp_schools SET school_name=?, program_name=?, degree_level=?, country=?, state=?, expected_graduation_date=? WHERE id=?"),
    'schInsert' => $pdo->prepare("INSERT INTO mvp_schools(mvp_id, school_id, school_name, program_name, degree_level, country, state, expected_graduation_date) VALUES (?,?,?,?,?,?,?,?)"),
    'schDelete' => $pdo->prepare("DELETE FROM mvp_schools WHERE id = ?"),
];

$newCount     = 0;
$updatedCount = 0;
$errorCount   = 0;

// ---- Pre-compute seenIds from API response (no DB writes yet) ----
$seenIds = [];
foreach ($profiles as $profile) {
    $seenIds[$profile['id']] = true;
}

// ---- Safety guard 2: check BEFORE any writes ----
// Skipped on first-ever scan (no active MVPs in DB yet) and when --force is set.
if (!$force && !$isBootstrap) {
    $activeBefore = (int)$pdo->query("SELECT COUNT(*) FROM mvps WHERE is_active=1")->fetchColumn();
    if ($activeBefore > 0) {
        $seenPlaceholders = implode(',', array_fill(0, count($seenIds), '?'));
        $wouldLeaveStmt   = $pdo->prepare(
            "SELECT COUNT(*) FROM mvps WHERE is_active=1 AND id NOT IN ({$seenPlaceholders})"
        );
        $wouldLeaveStmt->execute(array_keys($seenIds));
        $wouldLeaveCount = (int)$wouldLeaveStmt->fetchColumn();

        if ($wouldLeaveCount > (int)($activeBefore * MAX_SUSPICIOUS_LEFT_PCT / 100)) {
            $msg = sprintf(
                'SAFETY ABORT: %d MVPs (%.1f%% of %d active) would be marked as left in one scan. '
                    . 'Possible API issue. Run with --force to override.',
                $wouldLeaveCount, $wouldLeaveCount / $activeBefore * 100, $activeBefore
            );
            $pdo->prepare("UPDATE scans SET finished_at=?, notes=? WHERE id=?")
                ->execute([$nowDt->format('Y-m-d\TH:i:sP'), $msg, $scanId]);
            fwrite(STDERR, "[SAFETY] " . $msg . "\n");
            exit(3);
        }
    }
}

// ---- Process each profile in its own transaction ----
// If a single profile fails (bad data, constraint error, etc.) the rest continue.
foreach ($profiles as $profile) {
    $mapped = mapProfile($profile);
    $mvpId  = $mapped['id'];

    try {
        $pdo->beginTransaction();

        $stmts['selectMvp']->execute([$mvpId]);
        $existing = $stmts['selectMvp']->fetch() ?: null;
        $shouldSnapshot = $existing === null; // new, changed, or returned — never on a plain "still here, unchanged" pass

        if ($existing === null) {
            $ticksDate = extractTicksDate($mapped['picture_url'] ?? null);
            $entryDate = ($isBootstrap && !$mapped['first_awarded_date'] && !$ticksDate)
                ? null
                : computeEntryDate($mapped['first_awarded_date'], $nowDt, null, $ticksDate);

            $stmts['insertMvp']->execute([
                $mapped['id'], $mapped['user_profile_identifier'],
                $mapped['first_name'], $mapped['last_name'],
                $mapped['localized_first_name'], $mapped['localized_last_name'],
                $mapped['title'], $mapped['headline'], $mapped['biography'],
                $mapped['country'], $mapped['country_loc_key'],
                $mapped['gender'], $mapped['gender_pronoun'],
                $mapped['level_id'], $mapped['level_name'],
                $mapped['languages'], $mapped['tenants'], $mapped['picture_url'],
                $mapped['first_awarded_date'], $entryDate, $mapped['is_private'],
                $nowIso, $scanId, $nowIso, $scanId,
            ]);
            $stmts['insertHistory']->execute([$mvpId, $scanId, $nowIso, 'created', null, null, null]);
            $newCount++;
        } else {
            $changes = [];
            foreach ($TRACKED_FIELDS as $f) {
                if ($existing[$f] !== $mapped[$f]) {
                    $changes[] = [$f, $existing[$f], $mapped[$f]];
                }
            }

            if ($existing['left_at'] !== null) {
                $stmts['updateMvpReturn']->execute([$mvpId]);
                $stmts['insertHistory']->execute([$mvpId, $scanId, $nowIso, 'returned', null, null, null]);
                $shouldSnapshot = true;
            }

            if ($changes) {
                $stmts['updateMvpAll']->execute([
                    $mapped['first_name'], $mapped['last_name'],
                    $mapped['localized_first_name'], $mapped['localized_last_name'],
                    $mapped['title'], $mapped['headline'], $mapped['biography'],
                    $mapped['country'], $mapped['gender'],
                    $mapped['level_id'], $mapped['level_name'],
                    $mapped['languages'], $mapped['tenants'], $mapped['picture_url'],
                    $mapped['first_awarded_date'], $mapped['is_private'],
                    $nowIso, $scanId, $mvpId,
                ]);
                foreach ($changes as [$field, $old, $new]) {
                    $stmts['insertHistory']->execute([
                        $mvpId, $scanId, $nowIso, 'updated', $field,
                        $old === null ? null : (string)$old,
                        $new === null ? null : (string)$new,
                    ]);
                }
                $updatedCount++;
                $shouldSnapshot = true;
            } else {
                $stmts['updateMvpSeen']->execute([$nowIso, $scanId, $mvpId]);
            }
        }

        if ($shouldSnapshot) {
            $stmts['insertSnapshot']->execute([$mvpId, $scanId, $nowIso, json_encode($profile, JSON_UNESCAPED_UNICODE)]);
        }
        upsertSocialNetworks($pdo, $mvpId, $profile, $stmts);
        upsertSchools($pdo, $mvpId, $profile, $stmts);

        $pdo->commit();
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $errorCount++;
        fwrite(STDERR, sprintf("[WARN] Profile %d skipped: %s\n", $mvpId, $e->getMessage()));
    }
}

// ---- Mark MVPs that left the program (separate transaction) ----
$leftCount = 0;
try {
    $seenPlaceholders = implode(',', array_fill(0, count($seenIds), '?'));
    $leftStmt = $pdo->prepare(
        "SELECT id FROM mvps WHERE is_active=1 AND id NOT IN ({$seenPlaceholders})"
    );
    $leftStmt->execute(array_keys($seenIds));
    $leftRows  = $leftStmt->fetchAll(PDO::FETCH_COLUMN);
    $leftCount = count($leftRows);

    if ($leftCount > 0) {
        $todayIso = $nowDt->format('Y-m-d');
        $pdo->beginTransaction();
        foreach ($leftRows as $leftId) {
            $stmts['markLeft']->execute([$todayIso, $leftId]);
            $stmts['insertHistory']->execute([$leftId, $scanId, $nowIso, 'left', null, null, null]);
        }
        $pdo->commit();
    }
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, "[ERROR] mark-left failed: " . $e->getMessage() . "\n");
}

// ---- Finalise scan record ----
$finishedAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:sP');
$pdo->prepare(
    "UPDATE scans SET finished_at=?, total_fetched=?, total_mvps=?, new_count=?, updated_count=?, left_count=?, notes=? WHERE id=?"
)->execute([
    $finishedAt, count($profiles), count($profiles),
    $newCount, $updatedCount, $leftCount,
    $errorCount > 0 ? "Skipped {$errorCount} profile(s) due to errors" : null,
    $scanId,
]);

fwrite(STDERR, sprintf(
    "[INFO] Scan complete. New: %d  Updated: %d  Left: %d  Errors: %d\n",
    $newCount, $updatedCount, $leftCount, $errorCount
));

