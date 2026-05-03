<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/helpers.php';

$pdo = initDb();
$mvpId = (int)($_GET['id'] ?? 0);
if (!$mvpId) {
    jsonOut(['error' => 'not found'], 404);
}

$stmt = $pdo->prepare("SELECT * FROM mvps WHERE id = ?");
$stmt->execute([$mvpId]);
$row = $stmt->fetch();

if (!$row) {
    jsonOut(['error' => 'not found'], 404);
}

$mvp = rowToArray($row);
$computed = isset($mvp['program_entry_date']) ? yearsBetween($mvp['program_entry_date']) : null;
$mvp['years_in_program']          = $computed ?? $mvp['years_in_program_api'];
$mvp['years_in_program_computed'] = $computed;

$snStmt = $pdo->prepare(
    "SELECT network_name, handle, url FROM mvp_social_networks WHERE mvp_id = ? ORDER BY network_name"
);
$snStmt->execute([$mvpId]);
$mvp['social_networks'] = $snStmt->fetchAll();

$schStmt = $pdo->prepare("SELECT * FROM mvp_schools WHERE mvp_id = ?");
$schStmt->execute([$mvpId]);
$mvp['schools'] = $schStmt->fetchAll();

$histStmt = $pdo->prepare(
    "SELECT changed_at, change_type, field_name, old_value, new_value
     FROM mvp_history WHERE mvp_id = ? ORDER BY changed_at DESC, id DESC"
);
$histStmt->execute([$mvpId]);
$mvp['history'] = $histStmt->fetchAll();

jsonOut($mvp);
