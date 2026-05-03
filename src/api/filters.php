<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/helpers.php';

$pdo = initDb();

$countries = $pdo->query(
    "SELECT DISTINCT country FROM mvps WHERE is_active=1 AND country IS NOT NULL ORDER BY country COLLATE NOCASE"
)->fetchAll(PDO::FETCH_COLUMN);

$levels = $pdo->query(
    "SELECT DISTINCT level_name FROM mvps WHERE is_active=1 AND level_name IS NOT NULL ORDER BY level_name"
)->fetchAll(PDO::FETCH_COLUMN);

$genders = $pdo->query(
    "SELECT DISTINCT gender FROM mvps WHERE is_active=1 AND gender IS NOT NULL ORDER BY gender"
)->fetchAll(PDO::FETCH_COLUMN);

// Languages - flatten JSON arrays
$langSet = [];
foreach ($pdo->query("SELECT languages FROM mvps WHERE is_active=1")->fetchAll(PDO::FETCH_COLUMN) as $json) {
    $arr = json_decode($json ?? '[]', true) ?: [];
    foreach ($arr as $lang) {
        $langSet[$lang] = true;
    }
}
$languages = array_keys($langSet);
sort($languages);

// Award categories - flatten JSON arrays
$awardSet = [];
foreach ($pdo->query("SELECT award_category FROM mvps WHERE is_active=1 AND award_category IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN) as $json) {
    $arr = json_decode($json ?? '[]', true) ?: [];
    foreach ($arr as $cat) {
        $awardSet[$cat] = true;
    }
}
$awardCategories = array_keys($awardSet);
sort($awardCategories);

jsonOut([
    'countries'        => $countries,
    'levels'           => $levels,
    'genders'          => $genders,
    'languages'        => $languages,
    'award_categories' => $awardCategories,
]);
