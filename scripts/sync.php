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
 *   php scripts/sync.php --force-enrich       # scan + re-enrich ALL active MVPs + update activities/events
 *   php scripts/sync.php --no-enrich          # scan only, skip enrichment
 *   php scripts/sync.php --workers 20         # override parallel workers
 *   php scripts/sync.php --force              # bypass suspicious-left safety guard
 */

require_once __DIR__ . '/../src/db.php';
require_once __DIR__ . '/../src/helpers.php';

// ── Configurable constants ──────────────────────────────────────────────────
// NOTE (2026-07-24): mavenapi-prod.azurewebsites.net was retired/blocked by
// Microsoft (403 at the Azure edge). The legacy GET /api/UserProfiles/search/
// endpoint still returns 200 on the new mavenapi-prod.microsoft.com domain,
// but it's STALE/INCOMPLETE compared to what the live site actually uses
// (confirmed 2026-07-24: it was missing 62 genuinely-active MVPs and
// wrongly included 48 others vs. the live site's own search) — using it
// caused real active MVPs (e.g. Juliana Maria Lopes) to be falsely marked
// as "left". The live site itself calls POST /api/CommunityLeaders/search/
// instead (paginated, no numeric `id` in the list — only
// userProfileIdentifier). The numeric id + full profile fields (biography,
// gender, languages, title, award category, etc.) are only available from
// the per-profile detail endpoint now, so new MVPs get a detail fetch at
// creation time and existing MVPs keep their fields fresh via the enrich
// pass (Phase 2), same as before. Do NOT switch back to the legacy bulk
// endpoint even though it's simpler — it silently produces wrong data.
define('SYNC_API_BASE',       'https://mavenapi-prod.microsoft.com/api');
define('SYNC_SEARCH_URL',     SYNC_API_BASE . '/CommunityLeaders/search/');
define('SYNC_SEARCH_PAGE_SIZE', 100);
define('SYNC_ENRICH_BASE',    SYNC_API_BASE);
define('SYNC_UA',             'Mozilla/5.0 MVP-Extract/1.0');
define('SYNC_SCAN_TIMEOUT',   60);    // seconds per search page fetch
define('SYNC_ENRICH_TIMEOUT', 20);    // seconds per individual profile fetch
define('SYNC_MAX_RETRIES',    3);     // total attempts per page / profile (1 parallel + 2 sequential)
define('SYNC_WORKERS',        10);    // ← parallel enrichment workers — change this freely
define('SYNC_FLUSH_EVERY',    50);    // write enriched rows to DB in batches of this size
define('SYNC_MIN_PROFILES',   500);   // safety: abort if API returns fewer MVPs than this
define('SYNC_MAX_LEFT_PCT',   40);    // safety: abort if >N% of active MVPs appear to have left

// ── CLI arguments ───────────────────────────────────────────────────────────
$args        = $argv ?? [];
$forceGuard  = in_array('--force',        $args, true);
$forceEnrich = in_array('--force-enrich', $args, true);
$noEnrich    = in_array('--no-enrich',    $args, true);
$workersIdx  = array_search('--workers',  $args);
$workers     = ($workersIdx !== false && isset($args[$workersIdx + 1]))
    ? max(1, (int)$args[$workersIdx + 1])
    : SYNC_WORKERS;

// Only fields still present in the lightweight bulk search response are
// tracked for change-detection on every scan. title/biography/gender/
// languages/level/first_awarded_date/is_private are no longer returned by
// the bulk endpoint — they're refreshed only via the enrich pass now.
$TRACKED_FIELDS = [
    'first_name', 'last_name', 'localized_first_name', 'localized_last_name',
    'headline', 'country', 'tenants', 'picture_url',
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

// Fetch all pages via the new paginated POST search endpoint (with per-page retry)
try {
    $profiles = syncFetchAllProfiles();
    fwrite(STDERR, sprintf("[SCAN] API returned %d MVPs\n", count($profiles)));
} catch (Throwable $e) {
    $msg = 'fetchAll failed: ' . $e->getMessage();
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
    'selectMvpByIdent' => $pdo->prepare("SELECT * FROM mvps WHERE user_profile_identifier = ?"),
    'updateMvpSeen'   => $pdo->prepare("UPDATE mvps SET last_seen_at=?, last_seen_scan_id=? WHERE id=?"),
    'updateMvpReturn' => $pdo->prepare("UPDATE mvps SET left_at=NULL, is_active=1, enriched_at=NULL WHERE id=?"),
    'updateMvpLite'   => $pdo->prepare("
        UPDATE mvps SET
            first_name=?, last_name=?, localized_first_name=?, localized_last_name=?,
            headline=?, country=?, tenants=?, picture_url=?,
            last_seen_at=?, last_seen_scan_id=?
        WHERE id=?
    "),
    'insertMvpFull'   => $pdo->prepare("
        INSERT INTO mvps(
            id, user_profile_identifier, first_name, last_name,
            localized_first_name, localized_last_name, title, headline,
            biography, country, country_loc_key, gender, gender_pronoun,
            level_id, level_name, languages, tenants, picture_url,
            first_awarded_date, program_entry_date, is_private,
            years_in_program_api, award_category, technology_focus_area, functional_roles,
            company_name, company_role, enriched_at,
            first_seen_at, first_seen_scan_id, last_seen_at, last_seen_scan_id, is_active
        ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,1)
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
$detailQueue  = []; // rows ready for Phase 2 (mode: 'create' or 'enrich')

// Pre-compute seen identifiers (no DB writes yet).
// The bulk endpoint no longer returns a numeric `id`, only userProfileIdentifier,
// so all "have we seen this MVP in this scan" matching is done by GUID now.
$seenIdentifiers = [];
foreach ($profiles as $profile) {
    $ident = $profile['userProfileIdentifier'] ?? null;
    if ($ident) {
        $seenIdentifiers[$ident] = true;
    }
}

// Safety guard 2: check BEFORE any writes.
if (!$forceGuard && !$isBootstrap) {
    $activeBefore = (int)$pdo->query("SELECT COUNT(*) FROM mvps WHERE is_active=1")->fetchColumn();
    if ($activeBefore > 0) {
        $guardPh        = implode(',', array_fill(0, count($seenIdentifiers), '?'));
        $wouldLeaveStmt = $pdo->prepare(
            "SELECT COUNT(*) FROM mvps WHERE is_active=1 AND user_profile_identifier NOT IN ({$guardPh})"
        );
        $wouldLeaveStmt->execute(array_keys($seenIdentifiers));
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

// ── Process each profile ────────────────────────────────────────────────────
// Matching is done by user_profile_identifier (GUID) now, since the bulk
// endpoint no longer returns a numeric `id`. Brand-new MVPs are queued for a
// detail fetch in Phase 2 (that's the only place the numeric id is available).
foreach ($profiles as $profile) {
    $mapped     = syncMapProfileLite($profile);
    $identifier = $mapped['user_profile_identifier'];
    if (!$identifier) {
        $errorCount++;
        fwrite(STDERR, "[WARN] Profile missing userProfileIdentifier — skipped\n");
        continue;
    }

    try {
        $stmts['selectMvpByIdent']->execute([$identifier]);
        $existing = $stmts['selectMvpByIdent']->fetch() ?: null;

        if ($existing === null) {
            // Brand-new MVP: id + full fields only available via detail fetch (Phase 2)
            $detailQueue[] = [
                'mode'                    => 'create',
                'user_profile_identifier' => $identifier,
                'lite'                    => $mapped,
            ];
            continue;
        }

        $mvpId = (int)$existing['id'];
        $pdo->beginTransaction();

        $changes = [];
        foreach ($TRACKED_FIELDS as $f) {
            if ($existing[$f] !== $mapped[$f]) {
                $changes[] = [$f, $existing[$f], $mapped[$f]];
            }
        }

        $shouldSnapshot = false;
        if ($existing['left_at'] !== null) {
            $stmts['updateMvpReturn']->execute([$mvpId]);
            $stmts['insertHistory']->execute([$mvpId, $scanId, $nowIso, 'returned', null, null, null]);
            $shouldSnapshot = true;
        }

        if ($changes) {
            $stmts['updateMvpLite']->execute([
                $mapped['first_name'], $mapped['last_name'],
                $mapped['localized_first_name'], $mapped['localized_last_name'],
                $mapped['headline'], $mapped['country'], $mapped['tenants'], $mapped['picture_url'],
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

        if ($shouldSnapshot) {
            $stmts['insertSnapshot']->execute([
                $mvpId, $scanId, $nowIso,
                json_encode($profile, JSON_UNESCAPED_UNICODE),
            ]);
        }

        $pdo->commit();

        if ($forceEnrich || $existing['enriched_at'] === null) {
            $detailQueue[] = [
                'mode'                    => 'enrich',
                'id'                      => $mvpId,
                'user_profile_identifier' => $identifier,
                'picture_url'             => $mapped['picture_url'],
                'program_entry_date'      => $existing['program_entry_date'],
                'first_awarded_date'      => $existing['first_awarded_date'],
            ];
        }
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $errorCount++;
        fwrite(STDERR, sprintf("[WARN] Profile %s skipped: %s\n", $identifier, $e->getMessage()));
    }
}

// ── Mark MVPs that left (separate transaction) ─────────────────────────────
$leftCount = 0;
try {
    $seenPh   = implode(',', array_fill(0, count($seenIdentifiers), '?'));
    $leftStmt = $pdo->prepare("SELECT id FROM mvps WHERE is_active=1 AND user_profile_identifier NOT IN ({$seenPh})");
    $leftStmt->execute(array_keys($seenIdentifiers));
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
    "[SCAN] Done. Updated: %d  Left: %d  Errors: %d  Pending detail fetch (new+enrich): %d\n",
    $updatedCount, $leftCount, $errorCount, count($detailQueue)
));


// Record scan stats so far (new_count is finalised after Phase 2, since new
// MVPs are only actually inserted once their detail fetch succeeds)
$pdo->prepare("UPDATE scans SET total_fetched=?, total_mvps=?, updated_count=?, left_count=?, notes=? WHERE id=?")
    ->execute([
        count($profiles), count($profiles),
        $updatedCount, $leftCount,
        $errorCount > 0 ? "Skipped {$errorCount} profile(s) due to errors" : null,
        $scanId,
    ]);


// ╔══════════════════════════════════════════════════════════════════════════╗
// ║  PHASE 2 — DETAIL FETCH (parallel rolling pool)                         ║
// ║  Creates brand-new MVPs (mode=create) and enriches stale ones           ║
// ║  (mode=enrich). 'create' entries always run, even with --no-enrich —    ║
// ║  that's the only way a new MVP's numeric id can be obtained.            ║
// ╚══════════════════════════════════════════════════════════════════════════╝

if ($noEnrich) {
    $detailQueue = array_values(array_filter($detailQueue, fn($r) => $r['mode'] === 'create'));
}

if (count($detailQueue) === 0) {
    fwrite(STDERR, "[ENRICH] Nothing to process.\n");
    $pdo->prepare("UPDATE scans SET finished_at=? WHERE id=?")->execute([$nowIso, $scanId]);
    if (file_exists($progressFile)) { @unlink($progressFile); }
    exit(0);
}

fwrite(STDERR, sprintf(
    "[ENRICH] Starting detail fetch for %d profiles (%d parallel workers)...\n",
    count($detailQueue), $workers
));

// Switch progress file to enrich phase
$enrichTotal = count($detailQueue);
file_put_contents($progressFile, json_encode(['phase' => 'enrich', 'done' => 0, 'total' => $enrichTotal]));
$enrichFlushedDone = 0; // tracks how many have been written to progress file

$enrichNow    = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$enrichNowIso = $enrichNow->format('Y-m-d\TH:i:sP');

$stmtEnrich = $pdo->prepare("
    UPDATE mvps SET
        title                  = ?,
        biography              = ?,
        gender                 = ?,
        gender_pronoun         = ?,
        languages              = ?,
        is_private             = ?,
        years_in_program_api   = ?,
        award_category         = ?,
        technology_focus_area  = ?,
        functional_roles       = ?,
        company_name           = ?,
        company_role           = ?,
        program_entry_date     = ?,
        enriched_at            = ?
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
while ($queueIdx < count($detailQueue) && count($pool) < $workers) {
    syncAddEnrichHandle($mh, $pool, $detailQueue[$queueIdx++]);
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
            if ($row['mode'] === 'create') {
                if (syncCreateFromDetail($pdo, $stmts, $row, $data, $scanId, $enrichNow, $enrichNowIso)) {
                    $newCount++;
                } else {
                    $enrichErrors++;
                }
            } else {
                $enrichBatch[] = syncBuildEnrichEntry($row, $data, $enrichNowIso, $enrichNow);
                syncUpsertSocialNetworks($pdo, $row['id'], $data['userProfile'] ?? [], $stmts);
                syncUpsertSchools($pdo, $row['id'], $data['userProfile'] ?? [], $stmts);
            }
            $enrichDone++;
        } elseif ($httpCode === 404) {
            fwrite(STDERR, sprintf("[ENRICH] 404 for %s\n", $row['user_profile_identifier']));
            $enrichErrors++;
        } else {
            // Non-404 failure: queue for sequential retry
            fwrite(STDERR, sprintf("[ENRICH] WARN: HTTP %d for %s — will retry\n", $httpCode, $row['user_profile_identifier']));
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
                $enrichDone, count($detailQueue), $enrichErrors, count($retryList)));
        }

        // Add the next queued item to keep the pool at capacity
        if ($queueIdx < count($detailQueue)) {
            syncAddEnrichHandle($mh, $pool, $detailQueue[$queueIdx++]);
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
        fwrite(STDERR, sprintf("[ENRICH] Retry %d/%d %s HTTP %d\n", $a, SYNC_MAX_RETRIES, $row['user_profile_identifier'], $httpCode));
    }
    if ($data) {
        if ($row['mode'] === 'create') {
            if (syncCreateFromDetail($pdo, $stmts, $row, $data, $scanId, $enrichNow, $enrichNowIso)) {
                $newCount++;
            } else {
                $enrichErrors++;
            }
        } else {
            $enrichBatch[] = syncBuildEnrichEntry($row, $data, $enrichNowIso, $enrichNow);
            syncUpsertSocialNetworks($pdo, $row['id'], $data['userProfile'] ?? [], $stmts);
            syncUpsertSchools($pdo, $row['id'], $data['userProfile'] ?? [], $stmts);
        }
        $enrichDone++;
    } else {
        fwrite(STDERR, sprintf("[ENRICH] ERROR: giving up on %s\n", $row['user_profile_identifier']));
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

// Record the final new_count now that Phase 2 has actually created the new rows
$pdo->prepare("UPDATE scans SET new_count=? WHERE id=?")->execute([$newCount, $scanId]);

// Mark scan as fully complete (Phase 1 + Phase 2 done)
$finalNowIso = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:sP');
$pdo->prepare("UPDATE scans SET finished_at=? WHERE id=?")->execute([$finalNowIso, $scanId]);
if (file_exists($progressFile)) { @unlink($progressFile); }

fwrite(STDERR, sprintf("[ENRICH] Complete. New: %d  Updated: %d  Errors: %d\n", $newCount, $enrichDone, $enrichErrors));

// When --force-enrich was used, run update_activities.php immediately after
if ($forceEnrich) {
    fwrite(STDERR, "[ACTIVITIES] Starting activities/events update...\n");
    $actScript = __DIR__ . '/update_activities.php';
    passthru(PHP_BINARY . ' -d max_execution_time=0 ' . escapeshellarg($actScript) . ' --force', $actExitCode);
    if ($actExitCode !== 0) {
        fwrite(STDERR, "[ACTIVITIES] Finished with errors (exit {$actExitCode}).\n");
    }
}


// ╔══════════════════════════════════════════════════════════════════════════╗
// ║  HELPERS                                                                ║
// ╚══════════════════════════════════════════════════════════════════════════╝

function syncFetchAllProfiles(): array
{
    $all       = [];
    $pageIndex = 1;
    $pageSize  = SYNC_SEARCH_PAGE_SIZE;
    $maxPages  = 500; // safety cap (covers up to 50k profiles at pageSize=100)

    while ($pageIndex <= $maxPages) {
        $payload = json_encode([
            'searchKey' => '', 'academicInstitution' => '', 'program' => ['MVP'],
            'countryRegionList' => [], 'stateProvinceList' => [], 'languagesList' => [],
            'milestonesList' => [], 'academicCountryRegionList' => [], 'technologyFocusAreaList' => [],
            'industryFocusList' => [], 'technicalExpertiseList' => [], 'technologyFocusAreaGroupList' => [],
            'pageIndex' => $pageIndex, 'pageSize' => $pageSize,
        ], JSON_UNESCAPED_UNICODE);

        $page    = null;
        $lastErr = 'unknown';
        for ($attempt = 1; $attempt <= SYNC_MAX_RETRIES; $attempt++) {
            $ch = curl_init(SYNC_SEARCH_URL);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => SYNC_SCAN_TIMEOUT,
                CURLOPT_USERAGENT      => SYNC_UA,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $payload,
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            ]);
            $body     = curl_exec($ch);
            $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErr  = curl_error($ch);
            curl_close($ch);

            if ($httpCode === 200 && $body) {
                $page = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
                break;
            }
            $lastErr = $curlErr ?: "HTTP {$httpCode}";
            if ($attempt < SYNC_MAX_RETRIES) {
                sleep(2 ** ($attempt - 1));
            }
        }

        if ($page === null) {
            throw new RuntimeException("page {$pageIndex} failed after " . SYNC_MAX_RETRIES . " attempts: {$lastErr}");
        }

        $chunk = $page['communityLeaderProfiles'] ?? [];
        foreach ($chunk as $item) {
            $all[] = $item;
        }

        if (count($chunk) < $pageSize) {
            break; // last page reached
        }
        $pageIndex++;
    }

    return array_values(array_filter($all, fn($p) => in_array('MVP', $p['tenants'] ?? [], true)));
}

function syncMapProfileLite(array $profile): array
{
    $ident = $profile['userProfileIdentifier'] ?? null;
    return [
        'user_profile_identifier' => $ident,
        'first_name'              => $profile['firstName'] ?? null,
        'last_name'               => $profile['lastName'] ?? null,
        'localized_first_name'    => $profile['localizedFirstName'] ?? null,
        'localized_last_name'     => $profile['localizedLastName'] ?? null,
        'headline'                => $profile['headline'] ?? null,
        'country'                 => $profile['addressCountryOrRegionName'] ?? null,
        'country_loc_key'         => $profile['addressCountryOrRegionNameLocKey'] ?? null,
        'tenants'                 => json_encode($profile['tenants'] ?? [], JSON_UNESCAPED_UNICODE),
        'picture_url'             => !empty($profile['profilePictureUrl'])
            ? $profile['profilePictureUrl']
            : ($ident ? 'https://images.mvp.microsoft.com/' . $ident : null),
    ];
}

/**
 * Maps the /api/mvp/UserProfiles/public/{identifier} detail response into a
 * full mvps-table row shape (used only when creating a brand-new MVP, since
 * that's the only place the numeric id is available now).
 */
function syncMapDetailToFullRow(array $p, ?string $identifierFallback): array
{
    $ident = $p['userProfileIdentifier'] ?? $identifierFallback;
    return [
        'id'                      => isset($p['id']) ? (int)$p['id'] : null,
        'user_profile_identifier' => $ident,
        'first_name'              => $p['firstName'] ?? null,
        'last_name'               => $p['lastName'] ?? null,
        'localized_first_name'    => $p['localizedFirstName'] ?? null,
        'localized_last_name'     => $p['localizedLastName'] ?? null,
        'title'                   => $p['titleName'] ?? null,
        'headline'                => $p['headline'] ?? null,
        'biography'               => $p['biography'] ?? null,
        'country'                 => $p['addressCountryOrRegionName'] ?? null,
        'country_loc_key'         => $p['addressCountryOrRegionNameLocKey'] ?? null,
        'gender'                  => $p['genderName'] ?? null,
        'gender_pronoun'          => $p['genderPronounPreferenceName'] ?? null,
        'level_id'                => null, // levelStatus no longer provided by the API
        'level_name'              => null,
        'languages'               => json_encode($p['languages'] ?? [], JSON_UNESCAPED_UNICODE),
        'tenants'                 => json_encode($p['tenants'] ?? [], JSON_UNESCAPED_UNICODE),
        'picture_url'             => !empty($p['profilePictureUrl'])
            ? $p['profilePictureUrl']
            : ($ident ? 'https://images.mvp.microsoft.com/' . $ident : null),
        'first_awarded_date'      => null, // no longer provided; computeEntryDate() falls back to yearsInProgram/ticks
        'is_private'              => empty($p['isPrivate']) ? 0 : 1,
        'years_in_program_api'    => isset($p['yearsInProgram']) ? (int)$p['yearsInProgram'] : null,
        'award_category'          => json_encode($p['awardCategory'] ?? [], JSON_UNESCAPED_UNICODE),
        'technology_focus_area'   => json_encode($p['technologyFocusArea'] ?? [], JSON_UNESCAPED_UNICODE),
        'functional_roles'        => json_encode($p['functionalRoles'] ?? [], JSON_UNESCAPED_UNICODE),
        'company_name'            => $p['companyName'] ?? null,
        'company_role'            => $p['companyRole'] ?? null,
    ];
}

/**
 * Inserts a brand-new MVP from a successful detail-endpoint response.
 * Returns false (without throwing) on failure so the caller can count it as
 * an error and move on — the MVP will simply be picked up again next scan.
 */
function syncCreateFromDetail(PDO $pdo, array $stmts, array $row, array $data, int $scanId, DateTimeImmutable $now, string $nowIso): bool
{
    $p = $data['userProfile'] ?? [];
    if (empty($p['id'])) {
        fwrite(STDERR, sprintf("[WARN] Detail response missing id for %s — skipping\n", $row['user_profile_identifier']));
        return false;
    }

    $full  = syncMapDetailToFullRow($p, $row['user_profile_identifier']);
    $mvpId = $full['id'];

    $ticksDate = extractTicksDate($full['picture_url'] ?? null, $full['years_in_program_api'], $now);
    $entryDate = computeEntryDate($full['first_awarded_date'], $now, $full['years_in_program_api'], $ticksDate);

    try {
        $pdo->beginTransaction();
        $stmts['insertMvpFull']->execute([
            $full['id'], $full['user_profile_identifier'],
            $full['first_name'], $full['last_name'],
            $full['localized_first_name'], $full['localized_last_name'],
            $full['title'], $full['headline'], $full['biography'],
            $full['country'], $full['country_loc_key'],
            $full['gender'], $full['gender_pronoun'],
            $full['level_id'], $full['level_name'],
            $full['languages'], $full['tenants'], $full['picture_url'],
            $full['first_awarded_date'], $entryDate, $full['is_private'],
            $full['years_in_program_api'], $full['award_category'], $full['technology_focus_area'], $full['functional_roles'],
            $full['company_name'], $full['company_role'], $nowIso,
            $nowIso, $scanId, $nowIso, $scanId,
        ]);
        $stmts['insertHistory']->execute([$mvpId, $scanId, $nowIso, 'created', null, null, null]);
        $stmts['insertSnapshot']->execute([$mvpId, $scanId, $nowIso, json_encode($p, JSON_UNESCAPED_UNICODE)]);
        syncUpsertSocialNetworks($pdo, $mvpId, $p, $stmts);
        syncUpsertSchools($pdo, $mvpId, $p, $stmts);
        $pdo->commit();
        return true;
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        fwrite(STDERR, sprintf("[WARN] Create failed for %s: %s\n", $row['user_profile_identifier'], $e->getMessage()));
        return false;
    }
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
    $firstAwarded  = $row['first_awarded_date'] ?: null;
    $existingEntry = $row['program_entry_date'] ?: null;

    // Enrich is the authoritative step because we now have yearsInProgram for ticks validation.
    // However, discard ticks that are newer than the established entry date — they likely reflect
    // a profile picture change rather than the original award date.
    $ticksDate = extractTicksDate($row['picture_url'] ?? null, $yearsApi, $now);
    if ($ticksDate !== null && $existingEntry !== null && $ticksDate > $existingEntry) {
        $ticksDate = null;
    }

    $entry = computeEntryDate($firstAwarded, $now, $yearsApi, $ticksDate);

    // Never move program_entry_date forward based on incomplete data.
    // If the recomputed date is later than an already-established date (and no authoritative
    // first_awarded_date exists), preserve the established one.
    if (!$firstAwarded && $existingEntry !== null && $entry > $existingEntry) {
        $entry = $existingEntry;
    }
    return [
        'id'                    => $row['id'],
        'title'                 => $p['titleName'] ?? null,
        'biography'             => $p['biography'] ?? null,
        'gender'                => $p['genderName'] ?? null,
        'gender_pronoun'        => $p['genderPronounPreferenceName'] ?? null,
        'languages'             => json_encode($p['languages'] ?? [], JSON_UNESCAPED_UNICODE),
        'is_private'            => empty($p['isPrivate']) ? 0 : 1,
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
                $upd['title'],
                $upd['biography'],
                $upd['gender'],
                $upd['gender_pronoun'],
                $upd['languages'],
                $upd['is_private'],
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
