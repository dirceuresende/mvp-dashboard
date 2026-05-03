<?php
declare(strict_types=1);

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$uri = rtrim($uri ?: '/', '/') ?: '/';

// ---------- Static files (for php -S built-in server) ----------
if (preg_match('#^/static/(.+)$#', $uri, $m)) {
    $rel  = $m[1];
    // Prevent path traversal
    $real = realpath(__DIR__ . '/../app/static/' . $rel);
    $base = realpath(__DIR__ . '/../app/static');
    if ($real && $base && str_starts_with($real, $base)) {
        $ext  = strtolower(pathinfo($real, PATHINFO_EXTENSION));
        $mime = match ($ext) {
            'css'  => 'text/css; charset=utf-8',
            'js'   => 'application/javascript; charset=utf-8',
            'png'  => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'svg'  => 'image/svg+xml',
            'ico'  => 'image/x-icon',
            default => 'application/octet-stream',
        };
        header('Content-Type: ' . $mime);
        readfile($real);
        exit;
    }
    http_response_code(404);
    exit;
}

// ---------- API routes ----------
if (str_starts_with($uri, '/api')) {
    header('Content-Type: application/json; charset=utf-8');
    $apiPath = substr($uri, 4); // strip /api

    // /api/activities?identifier={guid}
    if ($apiPath === '/activities') {
        require __DIR__ . '/../src/api/mvp_activities.php';
    // /api/mvps/{id}  (numeric id)
    } elseif (preg_match('#^/mvps/(\d+)$#', $apiPath, $m)) {
        $_GET['id'] = $m[1];
        require __DIR__ . '/../src/api/mvp_detail.php';
    } elseif ($apiPath === '/mvps' || $apiPath === '') {
        // /api/mvps  OR just /api (shouldn't happen)
        if ($apiPath === '/mvps') {
            require __DIR__ . '/../src/api/mvps.php';
        } else {
            http_response_code(404);
            echo json_encode(['error' => 'not found']);
        }
    } else {
        $map = [
            '/stats'        => __DIR__ . '/../src/api/stats.php',
            '/aggregations' => __DIR__ . '/../src/api/aggregations.php',
            '/filters'      => __DIR__ . '/../src/api/filters.php',
            '/scans'        => __DIR__ . '/../src/api/scans.php',
            '/setup'        => __DIR__ . '/../src/api/setup.php',
        ];
        if (isset($map[$apiPath])) {
            require $map[$apiPath];
        } else {
            http_response_code(404);
            echo json_encode(['error' => 'not found']);
        }
    }
    exit;
}

// ---------- Dashboard ----------
require_once __DIR__ . '/../src/db.php';
$pdo      = initDb();
$mvpCount = (int)$pdo->query("SELECT COUNT(*) FROM mvps WHERE is_active=1")->fetchColumn();
if ($mvpCount === 0) {
    include __DIR__ . '/../app/templates/setup.html';
} else {
    include __DIR__ . '/../app/templates/dashboard.html';
}
