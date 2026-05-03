<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/helpers.php';

$pdo = initDb();

$q        = strtolower(getParam('q'));
$countries  = array_values(array_filter((array)($_GET['country'] ?? [])));
$level    = getParam('level');
$gender   = getParam('gender');
$languages  = array_values(array_filter((array)($_GET['language'] ?? [])));
$awardCat = getParam('award_category');
$status   = getParam('status', 'active');
$socialNetwork = getParam('social_network');
$tenant   = getParam('tenant');
$yearsBucket = getParam('years_bucket');
$techFocusArea = getParam('tech_focus_area');
$titleFilter = getParam('title_filter');
$companyFilter = getParam('company');
$joinYear = getParam('join_year');
$joinedMonths = max(0, (int)($_GET['joined_months'] ?? 0));
$leftMonths   = max(0, (int)($_GET['left_months'] ?? 0));
$eventsBucket = getParam('events_bucket');
$activitiesBucket = getParam('activities_bucket');
$page     = max(1, getIntParam('page', 1));
$pageSize = min(500, max(1, getIntParam('pageSize', 50)));
$sort     = getParam('sort', 'name');

$where  = [];
$params = [];

if ($status === 'active')   { $where[] = 'is_active = 1'; }
elseif ($status === 'left') { $where[] = 'is_active = 0'; }

if ($countries) {
    $ph = implode(',', array_fill(0, count($countries), '?'));
    $where[] = "country IN ({$ph})";
    array_push($params, ...$countries);
}
if ($level)    { $where[] = 'level_name = ?';        $params[] = $level; }
if ($gender)   { $where[] = 'gender = ?';            $params[] = $gender; }
if ($languages) {
    $lc = array_fill(0, count($languages), 'languages LIKE ?');
    $where[] = '(' . implode(' OR ', $lc) . ')';
    foreach ($languages as $l) $params[] = '%"' . $l . '"%';
}
if ($awardCat) { $where[] = 'award_category LIKE ?'; $params[] = '%"' . $awardCat . '"%'; }
if ($socialNetwork) { $where[] = 'id IN (SELECT mvp_id FROM mvp_social_networks WHERE network_name = ?)'; $params[] = $socialNetwork; }
if ($tenant) { $where[] = 'tenants LIKE ?'; $params[] = '%"' . $tenant . '"%'; }
if ($yearsBucket) {
    $yExpr = "(julianday('now') - julianday(program_entry_date)) / 365.25";
    $bucketSql = "CASE WHEN {$yExpr} < 1 THEN '<1 year' WHEN {$yExpr} < 3 THEN '1-3 years' WHEN {$yExpr} < 5 THEN '3-5 years' WHEN {$yExpr} < 10 THEN '5-10 years' WHEN {$yExpr} < 20 THEN '10-20 years' ELSE '20+ years' END";
    $where[] = "({$bucketSql}) = ? AND program_entry_date IS NOT NULL";
    $params[] = $yearsBucket;
}
if ($techFocusArea) { $where[] = 'technology_focus_area LIKE ?'; $params[] = '%"' . $techFocusArea . '"%'; }
if ($titleFilter)   { $where[] = 'title = ?';        $params[] = $titleFilter; }
if ($companyFilter) { $where[] = 'company_name = ?'; $params[] = $companyFilter; }
if ($joinYear)      { $where[] = "substr(program_entry_date, 1, 4) = ? AND program_entry_date IS NOT NULL"; $params[] = $joinYear; }
if ($joinedMonths > 0) { $where[] = "program_entry_date >= date('now', '-{$joinedMonths} months') AND program_entry_date IS NOT NULL"; }
if ($leftMonths   > 0) { $where[] = "left_at >= date('now', '-{$leftMonths} months') AND left_at IS NOT NULL"; }
if ($eventsBucket !== '') {
    if ($eventsBucket === '0') {
        $where[] = 'id NOT IN (SELECT DISTINCT mvp_id FROM mvp_events WHERE removed_at IS NULL)';
    } else {
        [$lo, $hi] = match($eventsBucket) {
            '1-5'  => [1, 5],
            '6-15' => [6, 15],
            '16-30'=> [16, 30],
            '30+'  => [31, null],
            default=> null,
        };
        if ([$lo, $hi] !== null) {
            $having = $hi === null
                ? "COUNT(*) >= $lo"
                : "COUNT(*) >= $lo AND COUNT(*) <= $hi";
            $where[] = "id IN (SELECT mvp_id FROM mvp_events WHERE removed_at IS NULL GROUP BY mvp_id HAVING $having)";
        }
    }
}
if ($activitiesBucket !== '') {
    if ($activitiesBucket === '0') {
        $where[] = 'id NOT IN (SELECT DISTINCT mvp_id FROM mvp_contributions WHERE removed_at IS NULL)';
    } else {
        [$lo, $hi] = match($activitiesBucket) {
            '1-5'  => [1, 5],
            '6-15' => [6, 15],
            '16-30'=> [16, 30],
            '30+'  => [31, null],
            default=> null,
        };
        if ([$lo, $hi] !== null) {
            $having = $hi === null
                ? "COUNT(*) >= $lo"
                : "COUNT(*) >= $lo AND COUNT(*) <= $hi";
            $where[] = "id IN (SELECT mvp_id FROM mvp_contributions WHERE removed_at IS NULL GROUP BY mvp_id HAVING $having)";
        }
    }
}
if ($q) {
    $where[] = '(LOWER(first_name) LIKE ? OR LOWER(last_name) LIKE ? OR LOWER(first_name || \' \' || last_name) LIKE ? OR LOWER(headline) LIKE ? OR LOWER(biography) LIKE ?)';
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like, $like, $like);
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$orderMap = [
    'name'      => 'first_name COLLATE NOCASE, last_name COLLATE NOCASE',
    'country'   => 'country COLLATE NOCASE, first_name COLLATE NOCASE, last_name COLLATE NOCASE',
    'entry'     => 'program_entry_date DESC',
    'entry_asc' => 'program_entry_date ASC',
    'first_seen'=> 'first_seen_at DESC',
];

// Column map for new multi-sort format (col:dir,col:dir)
// For 'years': sort by years_in_program_api (ASC = fewest years first), NULLs always last.
//   years ASC  -> fewest years first -> no inversion needed
//   years DESC -> most years first
// NULLs last trick: `(years_in_program_api IS NULL) ASC` always sorts NULLs after non-NULLs.
$colMap = [
    'name'       => ['first_name COLLATE NOCASE', 'last_name COLLATE NOCASE'],
    'country'    => ['country COLLATE NOCASE'],
    'level'      => ['level_name COLLATE NOCASE'],
    'headline'   => ['headline COLLATE NOCASE'],
    'years'      => null, // handled specially below
    'status'     => ['is_active'],
    'first_seen' => ['first_seen_at'],
    'entry'      => ['program_entry_date'],
    'left'       => ['left_at'],
];

$orderParts = [];
if (str_contains($sort, ':')) {
    foreach (explode(',', $sort) as $part) {
        $part = trim($part);
        if (!$part) continue;
        [$col, $dir] = array_pad(explode(':', $part, 2), 2, 'asc');
        $dir = strtolower($dir) === 'desc' ? 'DESC' : 'ASC';
        if ($col === 'years') {
            // Sort by actual years value, NULLs always last
            $orderParts[] = "(years_in_program_api IS NULL) ASC";
            $orderParts[] = "years_in_program_api {$dir}";
        } elseif (isset($colMap[$col]) && $colMap[$col] !== null) {
            foreach ($colMap[$col] as $sqlCol) {
                $orderParts[] = $sqlCol . ' ' . $dir;
            }
        }
    }
}
if (!$orderParts) {
    $orderParts = [$orderMap[$sort] ?? $orderMap['name']];
}
$orderSql = 'ORDER BY ' . implode(', ', $orderParts);

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM mvps {$whereSql}");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();

$rowsStmt = $pdo->prepare("
    SELECT m.*,
        (SELECT COUNT(*) FROM mvp_contributions c WHERE c.mvp_id = m.id AND c.removed_at IS NULL) AS activities_count,
        (SELECT COUNT(*) FROM mvp_events     e WHERE e.mvp_id = m.id AND e.removed_at IS NULL) AS events_count
    FROM mvps m {$whereSql} {$orderSql} LIMIT ? OFFSET ?");
$rowsStmt->execute(array_merge($params, [$pageSize, ($page - 1) * $pageSize]));
$rows = $rowsStmt->fetchAll();

$results = [];
foreach ($rows as $row) {
    $d = rowToArray($row);
    $computed = $d['program_entry_date'] ? yearsBetween($d['program_entry_date']) : null;
    $d['years_in_program']          = $computed ?? $d['years_in_program_api'];
    $d['years_in_program_computed'] = $computed;
    $d['activities_count']  = (int)($d['activities_count'] ?? 0);
    $d['events_count']      = (int)($d['events_count']     ?? 0);
    $results[] = $d;
}

jsonOut([
    'total'      => $total,
    'page'       => $page,
    'pageSize'   => $pageSize,
    'totalPages' => (int)ceil($total / $pageSize),
    'results'    => $results,
]);
