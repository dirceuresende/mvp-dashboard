<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/helpers.php';

$pdo = initDb();

$active = (int)$pdo->query("SELECT COUNT(*) FROM mvps WHERE is_active=1")->fetchColumn();
$left   = (int)$pdo->query("SELECT COUNT(*) FROM mvps WHERE is_active=0")->fetchColumn();
$countries = (int)$pdo->query(
    "SELECT COUNT(DISTINCT country) FROM mvps WHERE is_active=1 AND country IS NOT NULL"
)->fetchColumn();

$lastScan = $pdo->query("SELECT * FROM scans ORDER BY id DESC LIMIT 1")->fetch();

jsonOut([
    'active_mvps' => $active,
    'left_mvps'   => $left,
    'countries'   => $countries,
    'last_scan'   => $lastScan ?: null,
]);
