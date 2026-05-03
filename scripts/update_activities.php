<?php
declare(strict_types=1);
/**
 * MVP Activities Updater — PHP CLI script.
 *
 * Fetches Featured Contributions and Events for enriched MVPs.
 * Designed to be scheduled independently of enrich.php.
 *
 * Without --force, processes MVPs whose activities_updated_at is older than
 * 7 days (or have never been fetched).
 *
 * Usage:
 *   php scripts/update_activities.php              # update stale MVPs (7+ days)
 *   php scripts/update_activities.php --force      # re-fetch all enriched MVPs
 *   php scripts/update_activities.php --limit 500  # cap at 500 MVPs per run
 */

require_once __DIR__ . '/../src/db.php';
require_once __DIR__ . '/../src/helpers.php';

define('ACT_API_BASE',    'https://mavenapi-prod.azurewebsites.net/api');
define('ACT_MAX_WORKERS', 8);
define('ACT_TIMEOUT',     20);
define('ACT_MAX_RETRIES', 3);
define('ACT_BATCH_SIZE',  50);
define('ACT_UA',          'Mozilla/5.0 MVP-Extract/1.0');

$force    = in_array('--force', $argv ?? [], true);
$limitIdx = array_search('--limit', $argv ?? []);
$limit    = ($limitIdx !== false && isset($argv[$limitIdx + 1])) ? (int)$argv[$limitIdx + 1] : 0;

$pdo = initDb();

$now    = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$nowIso = $now->format('Y-m-d\TH:i:sP');

if ($force) {
    $rows = $pdo->query(
        "SELECT id, user_profile_identifier
         FROM mvps WHERE is_active = 1 AND enriched_at IS NOT NULL
         ORDER BY activities_updated_at ASC NULLS FIRST"
    )->fetchAll();
} else {
    $cutoff = $now->modify('-7 days')->format('Y-m-d\TH:i:sP');
    $rows = $pdo->query(
        "SELECT id, user_profile_identifier
         FROM mvps
         WHERE is_active = 1
           AND enriched_at IS NOT NULL
           AND (activities_updated_at IS NULL OR activities_updated_at < '$cutoff')
         ORDER BY activities_updated_at ASC NULLS FIRST"
    )->fetchAll();
}

if ($limit > 0) {
    $rows = array_slice($rows, 0, $limit);
}

$total = count($rows);
fwrite(STDERR, sprintf("[INFO] Updating activities for %d MVPs (force=%s, limit=%s)...\n",
    $total, $force ? 'true' : 'false', $limit > 0 ? (string)$limit : 'none'));

if ($total === 0) {
    fwrite(STDERR, "[INFO] Nothing to update. Use --force to refresh all.\n");
    exit(0);
}

// ---------- Statements (prepared once) ----------

$stmtContribUpsert = $pdo->prepare("
    INSERT INTO mvp_contributions
        (id, mvp_id, title, description, date, image_url, url, type_name, category_name, first_seen_at, last_seen_at, removed_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL)
    ON CONFLICT(id) DO UPDATE SET
        title         = excluded.title,
        description   = excluded.description,
        date          = excluded.date,
        image_url     = excluded.image_url,
        url           = excluded.url,
        type_name     = excluded.type_name,
        category_name = excluded.category_name,
        last_seen_at  = excluded.last_seen_at,
        removed_at    = NULL
");

$stmtEventUpsert = $pdo->prepare("
    INSERT INTO mvp_events
        (id, mvp_id, title, description, date_start, date_end, event_uri, first_seen_at, last_seen_at, removed_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NULL)
    ON CONFLICT(id) DO UPDATE SET
        title        = excluded.title,
        description  = excluded.description,
        date_start   = excluded.date_start,
        date_end     = excluded.date_end,
        event_uri    = excluded.event_uri,
        last_seen_at = excluded.last_seen_at,
        removed_at   = NULL
");

$stmtMarkUpdated = $pdo->prepare(
    "UPDATE mvps SET activities_updated_at = ? WHERE id = ?"
);

$stmtContribSelect = $pdo->prepare(
    "SELECT id FROM mvp_contributions WHERE mvp_id = ? AND removed_at IS NULL"
);
$stmtContribRemove = $pdo->prepare(
    "UPDATE mvp_contributions SET removed_at = ? WHERE id = ?"
);
$stmtEventSelect = $pdo->prepare(
    "SELECT id FROM mvp_events WHERE mvp_id = ? AND removed_at IS NULL"
);
$stmtEventRemove = $pdo->prepare(
    "UPDATE mvp_events SET removed_at = ? WHERE id = ?"
);

// ---------- Main loop ----------

$done   = 0;
$errors = 0;
$chunks = array_chunk($rows, ACT_MAX_WORKERS);

foreach ($chunks as $chunk) {
    // First pass: parallel fetch (2 URLs per MVP: contrib + events)
    $mh      = curl_multi_init();
    $handles = [];

    foreach ($chunk as $row) {
        $ident = urlencode($row['user_profile_identifier']);
        $rowHandles = [];
        foreach (['contrib' => '/Contributions/HighImpact/' . $ident . '/MVP',
                  'events'  => '/Events/HighImpact/' . $ident . '/MVP'] as $key => $path) {
            $ch = curl_init(ACT_API_BASE . $path);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => ACT_TIMEOUT,
                CURLOPT_USERAGENT      => ACT_UA,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_SSL_VERIFYPEER => true,
            ]);
            curl_multi_add_handle($mh, $ch);
            $rowHandles[$key] = $ch;
        }
        $handles[] = ['row' => $row, 'handles' => $rowHandles];
    }

    $running = 0;
    do {
        curl_multi_exec($mh, $running);
        if ($running) curl_multi_select($mh);
    } while ($running > 0);

    // Collect first-pass results, identify keys that need retry
    $chunkResults = []; // [mvp_id => ['contrib' => data|null, 'events' => data|null]]
    $needRetry    = []; // [mvp_id => [key => url]]

    foreach ($handles as ['row' => $row, 'handles' => $rowHandles]) {
        $mvpId = $row['id'];
        $ident = urlencode($row['user_profile_identifier']);
        $chunkResults[$mvpId] = [];
        foreach ($rowHandles as $key => $ch) {
            $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $body     = curl_multi_getcontent($ch);
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
            if ($httpCode === 200 && $body) {
                $chunkResults[$mvpId][$key] = json_decode($body, true);
            } else {
                $chunkResults[$mvpId][$key] = null;
                $path = $key === 'contrib'
                    ? '/Contributions/HighImpact/' . $ident . '/MVP'
                    : '/Events/HighImpact/' . $ident . '/MVP';
                $needRetry[$mvpId][$key] = ACT_API_BASE . $path;
            }
        }
    }
    curl_multi_close($mh);

    // Sequential retry for failed keys
    foreach ($needRetry as $mvpId => $failedKeys) {
        foreach ($failedKeys as $key => $url) {
            for ($attempt = 2; $attempt <= ACT_MAX_RETRIES; $attempt++) {
                sleep(2 ** ($attempt - 2)); // 1s, 2s
                $ch = curl_init($url);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT        => ACT_TIMEOUT,
                    CURLOPT_USERAGENT      => ACT_UA,
                    CURLOPT_SSL_VERIFYPEER => true,
                ]);
                $body     = curl_exec($ch);
                $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                if ($httpCode === 200 && $body) {
                    $chunkResults[$mvpId][$key] = json_decode($body, true);
                    break;
                }
                fwrite(STDERR, sprintf("[WARN] %s retry %d/%d for mvp_id=%d HTTP %d\n",
                    $key, $attempt, ACT_MAX_RETRIES, $mvpId, $httpCode));
            }
        }
    }

    // Process results and flush to DB
    $pdo->beginTransaction();
    try {
    foreach ($handles as ['row' => $row]) {
        $mvpId   = $row['id'];
        $results = $chunkResults[$mvpId];

        $contribs = $results['contrib']['contributions'] ?? [];
        $events   = $results['events']['events']         ?? [];

        // Track whether either side had a persistent failure
        $contribFailed = ($results['contrib'] === null);
        $eventsFailed  = ($results['events']  === null);

        if ($contribFailed && $eventsFailed) {
            fwrite(STDERR, sprintf("[WARN] both contrib+events failed for mvp_id=%d\n", $mvpId));
            $errors++;
            continue;
        }

        // Upsert contributions
        $contribIds = [];
        foreach ($contribs as $c) {
            if (empty($c['id'])) continue;
            $stmtContribUpsert->execute([
                $c['id'], $mvpId,
                $c['title']        ?? null,
                $c['description']  ?? null,
                $c['date']         ?? null,
                $c['imageUrl']     ?? null,
                $c['url']          ?? null,
                $c['typeName']     ?? null,
                $c['categoryName'] ?? null,
                $nowIso, $nowIso,
            ]);
            $contribIds[] = (int)$c['id'];
        }
        if (!$contribFailed) {
            // Mark removed contributions (in DB but not in current API response)
            $stmtContribSelect->execute([$mvpId]);
            foreach ($stmtContribSelect->fetchAll(PDO::FETCH_COLUMN) as $existId) {
                if (!in_array((int)$existId, $contribIds, true)) {
                    $stmtContribRemove->execute([$nowIso, $existId]);
                }
            }
        }

        // Upsert events
        $eventIds = [];
        foreach ($events as $e) {
            $eid = $e['eventId'] ?? $e['id'] ?? null;
            if (!$eid) continue;
            $stmtEventUpsert->execute([
                $eid, $mvpId,
                $e['title']       ?? null,
                $e['description'] ?? null,
                $e['dateStart']   ?? null,
                $e['dateEnd']     ?? null,
                $e['eventUri']    ?? null,
                $nowIso, $nowIso,
            ]);
            $eventIds[] = (int)$eid;
        }
        if (!$eventsFailed) {
            // Mark removed events
            $stmtEventSelect->execute([$mvpId]);
            foreach ($stmtEventSelect->fetchAll(PDO::FETCH_COLUMN) as $existId) {
                if (!in_array((int)$existId, $eventIds, true)) {
                    $stmtEventRemove->execute([$nowIso, $existId]);
                }
            }
        }

        $stmtMarkUpdated->execute([$nowIso, $mvpId]);
        $done++;
    }
    $pdo->commit();
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        fwrite(STDERR, "[ERROR] Chunk DB flush failed: " . $e->getMessage() . "\n");
        $errors += count($handles);
    }

    $processed = $done + $errors;
    if ($processed % 500 === 0 || $processed === $total) {
        fwrite(STDERR, sprintf("[INFO] Progress: %d/%d done, %d errors\n", $processed, $total, $errors));
    }
}

fwrite(STDERR, sprintf("[INFO] Activities update complete. Updated: %d  Errors: %d\n", $done, $errors));
