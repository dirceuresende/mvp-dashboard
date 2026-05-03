<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/helpers.php';

$pdo = initDb();
$rows = $pdo->query("SELECT * FROM scans ORDER BY id DESC LIMIT 50")->fetchAll();

jsonOut($rows);
