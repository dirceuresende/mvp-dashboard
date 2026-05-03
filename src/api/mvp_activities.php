<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/db.php';

$identifier = $_GET['identifier'] ?? '';
if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $identifier)) {
    http_response_code(400);
    echo json_encode(['error' => 'invalid identifier']);
    exit;
}

$pdo = initDb();

// Look up numeric mvp_id from identifier
$mvpId = $pdo->prepare('SELECT id FROM mvps WHERE user_profile_identifier = ?');
$mvpId->execute([$identifier]);
$mvpId = $mvpId->fetchColumn();

if (!$mvpId) {
    echo json_encode(['contributions' => [], 'events' => []]);
    exit;
}

// Check if we have data stored in DB (enriched)
$contribCount = $pdo->prepare('SELECT COUNT(*) FROM mvp_contributions WHERE mvp_id = ?');
$contribCount->execute([$mvpId]);
$hasStored = (int)$contribCount->fetchColumn() > 0;

// Check event table too
if (!$hasStored) {
    $eventCount = $pdo->prepare('SELECT COUNT(*) FROM mvp_events WHERE mvp_id = ?');
    $eventCount->execute([$mvpId]);
    $hasStored = (int)$eventCount->fetchColumn() > 0;
}

if ($hasStored) {
    // Serve from DB
    $contribs = $pdo->prepare(
        'SELECT id, title, description, date, image_url AS imageUrl, url, type_name AS typeName,
                category_name AS categoryName, first_seen_at, last_seen_at, removed_at
         FROM mvp_contributions WHERE mvp_id = ? ORDER BY date DESC, id DESC'
    );
    $contribs->execute([$mvpId]);

    $events = $pdo->prepare(
        'SELECT id, title, description, date_start AS dateStart, date_end AS dateEnd,
                event_uri AS eventUri, first_seen_at, last_seen_at, removed_at
         FROM mvp_events WHERE mvp_id = ? ORDER BY date_start DESC, id DESC'
    );
    $events->execute([$mvpId]);

    echo json_encode([
        'source'        => 'db',
        'contributions' => $contribs->fetchAll(PDO::FETCH_ASSOC),
        'events'        => $events->fetchAll(PDO::FETCH_ASSOC),
    ]);
    exit;
}

// Fallback: fetch live from Microsoft API (not yet enriched)
$base   = 'https://mavenapi-prod.azurewebsites.net/api';
$tenant = 'MVP';

function fetchRemote(string $url): ?array {
    $ctx = stream_context_create(['http' => [
        'timeout'        => 8,
        'ignore_errors'  => true,
        'user_agent'     => 'Mozilla/5.0 (compatible; mvp-extract/1.0)',
    ]]);
    $body = @file_get_contents($url, false, $ctx);
    if ($body === false) return null;
    $json = json_decode($body, true);
    return is_array($json) ? $json : null;
}

$contrib = fetchRemote("{$base}/Contributions/HighImpact/{$identifier}/{$tenant}");
$events  = fetchRemote("{$base}/Events/HighImpact/{$identifier}/{$tenant}");

echo json_encode([
    'source'        => 'live',
    'contributions' => $contrib['contributions'] ?? [],
    'events'        => $events['events']          ?? [],
]);
