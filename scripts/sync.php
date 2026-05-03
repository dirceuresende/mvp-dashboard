<?php
declare(strict_types=1);
/**
 * MVP Sync — scan + enrich in one pipelined run.
 *
 * Phase 1 (SCAN):   Fetch the full MVP list from the search API; upsert
 *                   basic profile data; detect who joined / left / changed.
 * Phase 2 (ENRICH): Immediately enrich new / stale profiles in parallel
 *                   using a curl_multi rolling pool — no second script needed.
 *
 * Usage:
 *   php scripts/sync.php                      # scan + enrich new/unenriched
 *   php scripts/sync.php --force-enrich       # scan + re-enrich ALL active MVPs
 *   php scripts/sync.php --no-enrich          # scan only, skip enrichment
 *   php scripts/sync.php --workers 20         # override parallel workers
 *   php scripts/sync.php --force              # bypass suspicious-left safety guard
 */

require_once __DIR__ . '/../src/db.php';
require_once __DIR__ . '/../src/helpers.php';

// ── Configurable constants ──────────────────────────────────────────────────
define('SYNC_SCAN_URL',       'https://mavenapi-prod.azurewebsites.net/api/UserProfiles/search/?program=MVP&pageIndex=1&pageSize=18');
define('SYNC_ENRICH_BASE',    'https://mavenapi-prod.azurewebsites.net/api');
define('SYNC_UA',             'Mozilla/5.0 MVP-Extract/1.0');
define('SYNC_SCAN_TIMEOUT',   120);   // seconds for the full-list fetch
define('SYNC_ENRICH_TIMEOUT', 20);    // seconds per individual profile fetch
define('SYNC_MAX_RETRIES',    3);     // total attempts per profile (1 parallel + 2 sequential)
define('SYNC_WORKERS',        10);    // ← parallel enrichment workers — change this freely
define('SYNC_FLUSH_EVERY',    50);    // write enriched rows to DB in batches of this size
define('SYNC_MIN_PROFILES',   500);   // safety: abort if API returns fewer MVPs than this
define('SYNC_MAX_LEFT_PCT',   10);    // safety: abort if >N% of active MVPs appear to have left

// ── CLI arguments ───────────────────────────────────────────────────────────
$args        = $argv ?? [];
$forceGuard  = in_array('--force',        $args, true);
$forceEnrich = in_array('--force-enrich', $args, true);
$noEnrich    = in_array('--no-enrich',    $args, true);
$workersIdx  = array_search('--workers',  $args);
$workers     = ($workersIdx !== false && isset($args[$workersIdx + 1]))
    ? max(1, (int)$args[$workersIdx + 1])
    : SYNC_WORKERS;

$TRACKED_FIELDS = [
    'first_name', 'last_name', 'localized_first_name', 'localized_last_name',
    'title', 'headline', 'biography', 'country', 'gender',
    'level_id', 'level_name', 'languages', 'tenants', 'picture_url',
    'first_awarded_date', 'is_private',
];

// ── Init DB ─────────────────────────────────────────────────────────────────
$pdo = initDb();

$nowDt  = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$nowIso = $nowDt->format('Y-m-d\TH:i:sP');


// ╔══════════════════════════════════════════════════════════════════════════╗
// ║  PHASE 1 — SCAN                                                         ║
// ╚══════════════════════════════════════════════════════════════════════════╝
fwrite(STDERR, "[SCAN] Fetching MVP list from API...\n");

$isBootstrap = (int)$pdo->query("SELECT COUNT(*) FROM scans")->fetchColumn() === 0;

$scanStmt = $pdo->prepare("INSERT INTO scans(started_at) VALUES (?)");
$scanStmt->execute([$nowIso]);
$scanId = (int)$pdo->lastInsertId();

// Fetch with exponential-backoff retry
$profiles = null;
$lastErr  = 'unknown';
for ($attempt = 1; $attempt <= SYNC_MAX_RETRIES; $attempt++) {
    $ctx  = stream_context_create(['http' => [
        'method'  => 'GET',
        'timeout' => SYNC_SCAN_TIMEOUT,
        'header'  => 'User-Agent: ' . SYNC_UA . "\r\n",
    ]]);
    $body = @file_get_contents(SYNC_SCAN_URL, false, $ctx);
    if ($body !== false) {
        $decoded  = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        $all      = $decoded['userProfiles'] ?? [];
        $profiles = array_values(array_filter($all, fn($p) => in_array('MVP', $p['tenants'] ?? [], true)));
        fwrite(STDERR, sprintf("[SCAN] API returned %d total profiles, %d MVPs\n", count($all), count($profiles)));
        break;
    }
    $lastErr = error_get_last()['message'] ?? 'unknown';
    if ($attempt < SYNC_MAX_RETRIES) {
        $wait = 2 ** ($attempt - 1);
        fwrite(STDERR, sprintf("[SCAN] Attempt %d/%d failed — retrying in %ds\n", $attempt, SYNC_MAX_RETRIES, $wait));
        sleep($wait);
    }
}

if ($profiles === null) {
    $msg = "fetchAll failed after " . SYNC_MAX_RETRIES . " attempts: $lastErr";
    $pdo->prepare("UPDATE scans SET finished_at=?, notes=? WHERE id=?")
        ->execute([$nowDt->format('Y-m-d\TH:i:sP'), "ERROR: $msg", $scanId]);
    fwrite(STDERR, "[ERROR] $msg\n");
    exit(1);
}

// Safety guard 1: minimum profiles
if (count($profiles) < SYNC_MIN_PROFILES) {
    $msg = sprintf(
        'SAFETY ABORT: API returned only %d MVPs (minimum expected: %d). '
            . 'Refusing to modify DB to prevent mass false deactivation.',
        count($profiles), SYNC_MIN_PROFILES
    );
    $pdo->prepare("UPDATE scans SET finished_at=?, notes=? WHERE id=?")
        ->execute([$nowDt->format('Y-m-d\TH:i:sP'), $msg, $scanId]);
    fwrite(STDERR, "[SAFETY] $msg\n");
    exit(2);
}

// Pre-prepare all scan statements (avoids re-preparing in a 4000-MVP loop)
$stmts = [
    'selectMvp'       => $pdo->prepare("SELECT * FROM mvps WHERE id = ?"),
    'updateMvpSeen'   => $pdo->prepare("UPDATE mvps SET last_seen_at=?, last_seen_scan_id=? WHERE id=?"),
    'updateMvpReturn' => $pdo->prepare("UPDATE mvps SET left_at=NULL, is_active=1, enriched_at=NULL WHERE id=?"),
    'updateMvpAll'    => $pdo->prepare("
        UPDATE mvps SET
            first_name=?, last_name=?, localized_first_name=?, localized_last_name=?,
            title=?, headline=?, biography=?, country=?, gender=?,
            level_id=?, level_name=?, languages=?, tenants=?, picture_url=?,
            first_awarded_date=?, is_private=?,
            last_seen_at=?, last_seen_scan_id=?
        WHERE id=?
    "),
    'insertMvp'       => $pdo->prepare("
        INSERT INTO mvps(
            id, user_profile_identifier, first_name, last_name,
            localized_first_name, localized_last_name, title, headline,
            biography, country, country_loc_key, gender, gender_pronoun,
            level_id, level_name, languages, tenants, picture_url,
            first_awarded_date, program_entry_date, is_private,
            first_seen_at, first_seen_scan_id, last_seen_at, last_seen_scan_id, is_active
        ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,1)
    "),
    'insertHistory'   => $pdo->prepare("
        INSERT INTO mvp_history(mvp_id, scan_id, changed_at, change_type, field_name, old_value, new_value)
        VALUES (?,?,?,?,?,?,?)
    "),
    'insertSnapshot'  => $pdo->prepare(
        "INSERT OR REPLACE INTO mvp_snapshots(mvp_id, scan_id, captured_at, raw_json) VALUES (?,?,?,?)"
    ),
    'markLeft'        => $pdo->prepare("UPDATE mvps SET left_at=?, is_active=0 WHERE id=?"),
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
$enrichQueue  = []; // rows ready for Phase 2

// ── Pre-compute seenIds (no DB writes yet) ─────────────────────────────────
$seenIds = [];
foreach ($profiles as $profile) {
    $seenIds[$profile['id']] = true;
}

// ── Safety guard 2: check BEFORE any writes ────────────────────────────────
if (!$forceGuard && !$isBootstrap) {
    $activeBefore = (int)$pdo->query("SELECT COUNT(*) FROM mvps WHERE is_active=1")->fetchColumn();
    if ($activeBefore > 0) {
        $guardPh        = implode(',', array_fill(0, count($seenIds), '?'));
        $wouldLeaveStmt = $pdo->prepare(
            "SELECT COUNT(*) FROM mvps WHERE is_active=1 AND id NOT IN ({$guardPh})"
        );
        $wouldLeaveStmt->execute(array_keys($seenIds));
        $wouldLeave = (int)$wouldLeaveStmt->fetchColumn();

        if ($wouldLeave > (int)($activeBefore * SYNC_MAX_LEFT_PCT / 100)) {
            $msg = sprintf(
                'SAFETY ABORT: %d MVPs (%.1f%% of %d active) would be marked as left. '
                    . 'Possible API issue. Run with --force to override.',
                $wouldLeave, $wouldLeave / $activeBefore * 100, $activeBefore
            );
            $pdo->prepare("UPDATE scans SET finished_at=?, notes=? WHERE id=?")
                ->execute([$nowDt->format('Y-m-d\TH:i:sP'), $msg, $scanId]);
            fwrite(STDERR, "[SAFETY] $msg\n");
            exit(3);
        }
    }
}

// ── Write progress marker so the setup UI can show total ──────────────────
$progressFile = dirname(__DIR__) . '/data/setup.progress';
file_put_contents($progressFile, json_encode(['phase' => 'scan', 'total' => count($profiles)]));

// ── Process each profile in its own transaction ────────────────────────────
foreach ($profiles as $profile) {
    $mapped = syncMapProfile($profile);
    $mvpId  = $mapped['id'];

    try {
        $pdo->beginTransaction();

        $stmts['selectMvp']->execute([$mvpId]);
        $existing = $stmts['selectMvp']->fetch() ?: null;

        if ($existing === null) {
            // ── New MVP ────────────────────────────────────────────────────
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

            // Always enrich new MVPs
            $enrichQueue[] = [
                'id'                      => $mvpId,
                'user_profile_identifier' => $mapped['user_profile_identifier'],
                'picture_url'             => $mapped['picture_url'],
                'program_entry_date'      => $entryDate,
                'first_awarded_date'      => $mapped['first_awarded_date'],
            ];
        } else {
            // ── Existing MVP ───────────────────────────────────────────────
            $changes = [];
            foreach ($TRACKED_FIELDS as $f) {
                if ($existing[$f] !== $mapped[$f]) {
                    $changes[] = [$f, $existing[$f], $mapped[$f]];
                }
            }

            if ($existing['left_at'] !== null) {
                $stmts['updateMvpReturn']->execute([$mvpId]);
                $stmts['insertHistory']->execute([$mvpId, $scanId, $nowIso, 'returned', null, null, null]);
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
            } else {
                $stmts['updateMvpSeen']->execute([$nowIso, $scanId, $mvpId]);
            }

            if ($forceEnrich || $existing['enriched_at'] === null) {
                $enrichQueue[] = [
                    'id'                      => $existing['id'],
                    'user_profile_identifier' => $existing['user_profile_identifier'],
                    'picture_url'             => $mapped['picture_url'],
                    'program_entry_date'      => $existing['program_entry_date'],
                    'first_awarded_date'      => $existing['first_awarded_date'],
                ];
            }
        }

        $stmts['insertSnapshot']->execute([
            $mvpId, $scanId, $nowIso,
            json_encode($profile, JSON_UNESCAPED_UNICODE),
        ]);
        syncUpsertSocialNetworks($pdo, $mvpId, $profile, $stmts);
        syncUpsertSchools($pdo, $mvpId, $profile, $stmts);

        $pdo->commit();
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $errorCount++;
        fwrite(STDERR, sprintf("[WARN] Profile %d skipped: %s\n", $mvpId, $e->getMessage()));
    }
}

// ── Mark MVPs that left (separate transaction) ─────────────────────────────
$leftCount = 0;
try {
    $seenPh   = implode(',', array_fill(0, count($seenIds), '?'));
    $leftStmt = $pdo->prepare("SELECT id FROM mvps WHERE is_active=1 AND id NOT IN ({$seenPh})");
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

fwrite(STDERR, sprintf(
    "[SCAN] Done. New: %d  Updated: %d  Left: %d  Errors: %d  Enrich queue: %d\n",
    $newCount, $updatedCount, $leftCount, $errorCount, count($enrichQueue)
));


// Record scan stats (no finished_at yet — enrich still pending)
$pdo->prepare("UPDATE scans SET total_fetched=?, total_mvps=?, new_count=?, updated_count=?, left_count=?, notes=? WHERE id=?")
    ->execute([
        count($profiles), count($profiles),
        $newCount, $updatedCount, $leftCount,
        $errorCount > 0 ? "Skipped {$errorCount} profile(s) due to errors" : null,
        $scanId,
    ]);


// ╔══════════════════════════════════════════════════════════════════════════╗
// ║  PHASE 2 — ENRICH (parallel rolling pool)                               ║
// ╚══════════════════════════════════════════════════════════════════════════╝

if ($noEnrich || count($enrichQueue) === 0) {
    if (count($enrichQueue) === 0) {
        fwrite(STDERR, "[ENRICH] Nothing to enrich.\n");
    }
    // No enrich phase — mark scan as fully done now
    $pdo->prepare("UPDATE scans SET finished_at=? WHERE id=?")->execute([$nowIso, $scanId]);
    if (file_exists($progressFile)) { @unlink($progressFile); }
    exit(0);
}

fwrite(STDERR, sprintf(
    "[ENRICH] Starting enrichment of %d profiles (%d parallel workers)...\n",
    count($enrichQueue), $workers
));

// Switch progress file to enrich phase
$enrichTotal = count($enrichQueue);
file_put_contents($progressFile, json_encode(['phase' => 'enrich', 'done' => 0, 'total' => $enrichTotal]));
$enrichFlushedDone = 0; // tracks how many have been written to progress file

$enrichNow    = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$enrichNowIso = $enrichNow->format('Y-m-d\TH:i:sP');

$stmtEnrich = $pdo->prepare("
    UPDATE mvps SET
        years_in_program_api  = ?,
        award_category        = ?,
        technology_focus_area = ?,
        functional_roles      = ?,
        company_name          = ?,
        company_role          = ?,
        program_entry_date    = ?,
        enriched_at           = ?
    WHERE id = ?
");

// Rolling pool state
$mh         = curl_multi_init();
curl_multi_setopt($mh, CURLMOPT_MAXCONNECTS, $workers);
$pool       = [];        // (int)$ch => ['row' => ..., 'ch' => ...]
$queueIdx   = 0;
$enrichBatch = [];
$enrichDone  = 0;
$enrichErrors = 0;
$retryList   = [];       // rows that failed in the parallel pass → sequential retry

// Seed the pool
while ($queueIdx < count($enrichQueue) && count($pool) < $workers) {
    syncAddEnrichHandle($mh, $pool, $enrichQueue[$queueIdx++]);
}

$running = 0;
do {
    curl_multi_exec($mh, $running);
    if ($running) {
        curl_multi_select($mh, 0.05);
    }

    // Harvest all completed handles
    while (($info = curl_multi_info_read($mh)) !== false) {
        $ch      = $info['handle'];
        $key     = (int)$ch;
        $entry   = $pool[$key];
        $row     = $entry['row'];

        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $body     = curl_multi_getcontent($ch);
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
        unset($pool[$key]);

        $data = ($httpCode === 200 && $body) ? json_decode($body, true) : null;

        if ($data) {
            $enrichBatch[] = syncBuildEnrichEntry($row, $data, $enrichNowIso, $enrichNow);
            $enrichDone++;
        } elseif ($httpCode === 404) {
            fwrite(STDERR, sprintf("[ENRICH] 404 for %s\n", $row['user_profile_identifier']));
            $enrichErrors++;
        } else {
            // Non-404 failure: queue for sequential retry
            fwrite(STDERR, sprintf("[ENRICH] WARN: HTTP %d for id=%d — will retry\n", $httpCode, $row['id']));
            $retryList[] = $row;
        }

        // Flush enriched batch to DB
        if (count($enrichBatch) >= SYNC_FLUSH_EVERY) {
            syncFlushEnrichBatch($pdo, $stmtEnrich, $enrichBatch);
            $enrichFlushedDone += SYNC_FLUSH_EVERY;
            file_put_contents($progressFile, json_encode(['phase' => 'enrich', 'done' => $enrichFlushedDone, 'total' => $enrichTotal]));
        }

        // Progress report
        $processed = $enrichDone + $enrichErrors + count($retryList);
        if ($processed % 500 === 0) {
            fwrite(STDERR, sprintf("[ENRICH] %d/%d done, %d errors, %d pending retry\n",
                $enrichDone, count($enrichQueue), $enrichErrors, count($retryList)));
        }

        // Add the next queued item to keep the pool at capacity
        if ($queueIdx < count($enrichQueue)) {
            syncAddEnrichHandle($mh, $pool, $enrichQueue[$queueIdx++]);
        }
    }
} while ($running > 0 || !empty($pool));

curl_multi_close($mh);

// Sequential retry with back-off for anything that failed in the parallel pass
if ($retryList) {
    fwrite(STDERR, sprintf("[ENRICH] Retrying %d failed profiles sequentially...\n", count($retryList)));
}
foreach ($retryList as $row) {
    $url  = SYNC_ENRICH_BASE . '/mvp/UserProfiles/public/' . urlencode($row['user_profile_identifier']);
    $data = null;
    for ($a = 2; $a <= SYNC_MAX_RETRIES; $a++) {
        sleep(2 ** ($a - 2)); // 1 s, then 2 s
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => SYNC_ENRICH_TIMEOUT,
            CURLOPT_USERAGENT      => SYNC_UA,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $body     = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($httpCode === 200 && $body) {
            $data = json_decode($body, true);
            break;
        }
        fwrite(STDERR, sprintf("[ENRICH] Retry %d/%d id=%d HTTP %d\n", $a, SYNC_MAX_RETRIES, $row['id'], $httpCode));
    }
    if ($data) {
        $enrichBatch[] = syncBuildEnrichEntry($row, $data, $enrichNowIso, $enrichNow);
        $enrichDone++;
    } else {
        fwrite(STDERR, sprintf("[ENRICH] ERROR: giving up on id=%d\n", $row['id']));
        $enrichErrors++;
    }
    if (count($enrichBatch) >= SYNC_FLUSH_EVERY) {
        syncFlushEnrichBatch($pdo, $stmtEnrich, $enrichBatch);
        $enrichFlushedDone += SYNC_FLUSH_EVERY;
        file_put_contents($progressFile, json_encode(['phase' => 'enrich', 'done' => $enrichFlushedDone, 'total' => $enrichTotal]));
    }
}

// Final flush of any remaining enriched data
syncFlushEnrichBatch($pdo, $stmtEnrich, $enrichBatch);

// Mark scan as fully complete (Phase 1 + Phase 2 done)
$finalNowIso = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:sP');
$pdo->prepare("UPDATE scans SET finished_at=? WHERE id=?")->execute([$finalNowIso, $scanId]);
if (file_exists($progressFile)) { @unlink($progressFile); }

fwrite(STDERR, sprintf("[ENRICH] Complete. Updated: %d  Errors: %d\n", $enrichDone, $enrichErrors));


// ╔══════════════════════════════════════════════════════════════════════════╗
// ║  HELPERS                                                                ║
// ╚══════════════════════════════════════════════════════════════════════════╝

function syncMapProfile(array $profile): array
{
    $level = $profile['levelStatus'] ?? [];
    $ident = $profile['userProfileIdentifier'] ?? null;
    return [
        'id'                      => $profile['id'],
        'user_profile_identifier' => $ident,
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
        'picture_url'             => !empty($profile['profilePictureUrl'])
            ? $profile['profilePictureUrl']
            : ($ident ? 'https://images.mvp.microsoft.com/' . $ident : null),
        'first_awarded_date'      => parseFirstAwarded($profile['firstAwardedDate'] ?? null),
        'is_private'              => empty($profile['isPrivate']) ? 0 : 1,
    ];
}

function syncUpsertSocialNetworks(PDO $pdo, int $mvpId, array $profile, array $stmts): void
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

function syncUpsertSchools(PDO $pdo, int $mvpId, array $profile, array $stmts): void
{
    $schools  = $profile['userProfileSchool'] ?? [];
    $existing = [];
    $stmts['schSelect']->execute([$mvpId]);
    foreach ($stmts['schSelect']->fetchAll() as $row) {
        $existing[$row['school_id']] = $row['id'];
    }
    $seen = [];
    foreach ($schools as $s) {
        $sid    = $s['schoolId'] ?? null;
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

function syncAddEnrichHandle(CurlMultiHandle $mh, array &$pool, array $row): void
{
    $url = SYNC_ENRICH_BASE . '/mvp/UserProfiles/public/' . urlencode($row['user_profile_identifier']);
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => SYNC_ENRICH_TIMEOUT,
        CURLOPT_USERAGENT      => SYNC_UA,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    curl_multi_add_handle($mh, $ch);
    $pool[(int)$ch] = ['row' => $row, 'ch' => $ch];
}

function syncBuildEnrichEntry(array $row, array $data, string $nowIso, DateTimeImmutable $now): array
{
    $p        = $data['userProfile'] ?? [];
    $yearsApi = isset($p['yearsInProgram']) ? (int)$p['yearsInProgram'] : null;
    // Always recompute: scan sets ticks date without yearsInProgram validation.
    // Enrich is the authoritative step because we now have yearsInProgram.
    $ticksDate = extractTicksDate($row['picture_url'] ?? null, $yearsApi, $now);
    $entry     = computeEntryDate($row['first_awarded_date'] ?: null, $now, $yearsApi, $ticksDate);
    return [
        'id'                    => $row['id'],
        'years_in_program_api'  => $yearsApi,
        'award_category'        => json_encode($p['awardCategory'] ?? [], JSON_UNESCAPED_UNICODE),
        'technology_focus_area' => json_encode($p['technologyFocusArea'] ?? [], JSON_UNESCAPED_UNICODE),
        'functional_roles'      => json_encode($p['functionalRoles'] ?? [], JSON_UNESCAPED_UNICODE),
        'company_name'          => $p['companyName'] ?? null,
        'company_role'          => $p['companyRole'] ?? null,
        'program_entry_date'    => $entry,
        'enriched_at'           => $nowIso,
    ];
}

function syncFlushEnrichBatch(PDO $pdo, PDOStatement $stmt, array &$batch): void
{
    if (!$batch) return;
    $pdo->beginTransaction();
    try {
        foreach ($batch as $upd) {
            $stmt->execute([
                $upd['years_in_program_api'],
                $upd['award_category'],
                $upd['technology_focus_area'],
                $upd['functional_roles'],
                $upd['company_name'],
                $upd['company_role'],
                $upd['program_entry_date'],
                $upd['enriched_at'],
                $upd['id'],
            ]);
        }
        $pdo->commit();
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
    $batch = [];
}
