<?php
declare(strict_types=1);
/**
 * MVP Enricher — PHP CLI script.
 *
 * Enriches MVP profile fields from /api/mvp/UserProfiles/public/{identifier}.
 * For activities (contributions/events) use scripts/update_activities.php.
 * Uses curl_multi for parallel requests with retry on failure.
 *
 * NOTE (2026-07-24): this script only calls the per-profile detail endpoint
 * (unaffected by the legacy bulk-search staleness issue — see
 * scripts/sync.php's header comment), so it remains safe to use as-is.
 *
 * Usage:
 *   php scripts/enrich.php             # enrich only un-enriched MVPs
 *   php scripts/enrich.php --force     # re-enrich all active MVPs
 */

require_once __DIR__ . '/../src/db.php';
require_once __DIR__ . '/../src/helpers.php';

// NOTE (2026-07-24): mavenapi-prod.azurewebsites.net was retired/blocked by
// Microsoft (403 at the Azure edge). The API is still reachable at the same
// paths under mavenapi-prod.microsoft.com instead — only the domain changed.
define('API_BASE',        'https://mavenapi-prod.microsoft.com/api');
define('MAX_WORKERS',     10);  // parallel profile fetches per chunk
define('REQUEST_TIMEOUT', 20);
define('BATCH_SIZE',      50);
define('MAX_RETRIES',     3);
define('ENRICH_UA',       'Mozilla/5.0 MVP-Extract/1.0');

$force = in_array('--force', $argv ?? [], true);

$pdo = initDb();

if ($force) {
    $rows = $pdo->query(
        "SELECT id, user_profile_identifier, picture_url, program_entry_date, first_awarded_date
         FROM mvps WHERE is_active = 1"
    )->fetchAll();
} else {
    $rows = $pdo->query(
        "SELECT id, user_profile_identifier, picture_url, program_entry_date, first_awarded_date
         FROM mvps WHERE is_active = 1 AND enriched_at IS NULL"
    )->fetchAll();
}

$total = count($rows);
fwrite(STDERR, sprintf("[INFO] Enriching %d MVPs (force=%s)...\n", $total, $force ? 'true' : 'false'));

if ($total === 0) {
    fwrite(STDERR, "[INFO] Nothing to enrich. Use --force to re-enrich all.\n");
    exit(0);
}

// ---------- curl_multi parallel fetch ----------

$now    = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$nowIso = $now->format('Y-m-d\TH:i:sP');

$done   = 0;
$errors = 0;
$batch  = [];

// Process in windows of MAX_WORKERS MVPs (3 requests each)
$chunks = array_chunk($rows, MAX_WORKERS);

foreach ($chunks as $chunk) {
    $mh      = curl_multi_init();
    $handles = [];

    foreach ($chunk as $row) {
        $ident = urlencode($row['user_profile_identifier']);
        $ch    = curl_init(API_BASE . '/mvp/UserProfiles/public/' . $ident);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => REQUEST_TIMEOUT,
            CURLOPT_USERAGENT      => ENRICH_UA,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        curl_multi_add_handle($mh, $ch);
        $handles[] = ['row' => $row, 'ch' => $ch];
    }

    // First pass: parallel fetch
    $running = 0;
    do {
        curl_multi_exec($mh, $running);
        if ($running) curl_multi_select($mh);
    } while ($running > 0);

    $needRetry = [];
    foreach ($handles as ['row' => $row, 'ch' => $ch]) {
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $body     = curl_multi_getcontent($ch);
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);

        $data = ($httpCode === 200 && $body) ? json_decode($body, true) : null;
        if ($data) {
            $batch[] = buildBatchEntry($row, $data, $nowIso, $now);
            $done++;
        } elseif ($httpCode === 404) {
            fwrite(STDERR, sprintf("[WARN] 404 for identifier=%s — skipping enrichment\n", $row['user_profile_identifier']));
            $errors++;
        } else {
            $needRetry[] = $row; // non-404 failure — retry
        }
    }
    curl_multi_close($mh);

    // Sequential retry for failed (non-404) profiles
    foreach ($needRetry as $row) {
        $ident = urlencode($row['user_profile_identifier']);
        $url   = API_BASE . '/mvp/UserProfiles/public/' . $ident;
        $data  = null;
        for ($attempt = 2; $attempt <= MAX_RETRIES; $attempt++) {
            sleep(2 ** ($attempt - 2)); // 1s, 2s
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => REQUEST_TIMEOUT,
                CURLOPT_USERAGENT      => ENRICH_UA,
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
            fwrite(STDERR, sprintf("[WARN] retry %d/%d for %s HTTP %d\n", $attempt, MAX_RETRIES, $row['user_profile_identifier'], $httpCode));
        }
        if ($data) {
            $batch[] = buildBatchEntry($row, $data, $nowIso, $now);
            $done++;
        } else {
            fwrite(STDERR, sprintf("[WARN] profile fetch failed after retries for identifier=%s\n", $row['user_profile_identifier']));
            $errors++;
        }
    }

    // Flush batch
    if (count($batch) >= BATCH_SIZE) {
        flushBatch($pdo, $batch, $nowIso);
    }

    $processed = $done + $errors;
    if ($processed % 500 === 0 || $processed === $total) {
        fwrite(STDERR, sprintf("[INFO] Progress: %d/%d done, %d errors\n", $processed, $total, $errors));
    }
}

flushBatch($pdo, $batch, $nowIso);
fwrite(STDERR, sprintf("[INFO] Enrichment complete. Updated: %d  Errors: %d\n", $done, $errors));

// ---------- Helpers ----------

function buildBatchEntry(array $row, array $data, string $nowIso, DateTimeImmutable $now): array
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

    $entryDate = computeEntryDate($firstAwarded, $now, $yearsApi, $ticksDate);

    // Never move program_entry_date forward based on incomplete data.
    // If the recomputed date is later than an already-established date (and no authoritative
    // first_awarded_date exists), preserve the established one.
    if (!$firstAwarded && $existingEntry !== null && $entryDate > $existingEntry) {
        $entryDate = $existingEntry;
    }

    return [
        'id'                    => $row['id'],
        'years_in_program_api'  => $yearsApi,
        'award_category'        => json_encode($p['awardCategory'] ?? [], JSON_UNESCAPED_UNICODE),
        'technology_focus_area' => json_encode($p['technologyFocusArea'] ?? [], JSON_UNESCAPED_UNICODE),
        'functional_roles'      => json_encode($p['functionalRoles'] ?? [], JSON_UNESCAPED_UNICODE),
        'company_name'          => $p['companyName'] ?? null,
        'company_role'          => $p['companyRole'] ?? null,
        'program_entry_date'    => $entryDate,
        'enriched_at'           => $nowIso,
    ];
}

function flushBatch(PDO $pdo, array &$batch, string $nowIso): void
{
    if (!$batch) return;

    $stmtMvp = $pdo->prepare("
        UPDATE mvps SET
            years_in_program_api = ?,
            award_category = ?,
            technology_focus_area = ?,
            functional_roles = ?,
            company_name = ?,
            company_role = ?,
            program_entry_date = ?,
            enriched_at = ?
        WHERE id = ?
    ");

    $pdo->beginTransaction();
    try {
        foreach ($batch as $upd) {
            $stmtMvp->execute([
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
