<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/helpers.php';

$pdo = initDb();

$qText   = strtolower(getParam('q'));
$countries = array_values(array_filter((array)($_GET['country'] ?? [])));
$level   = getParam('level');
$gender  = getParam('gender');
$languages = array_values(array_filter((array)($_GET['language'] ?? [])));
$awardCat = getParam('award_category');
$status  = getParam('status', 'active');
$socialNetwork = getParam('social_network');
$tenant  = getParam('tenant');
$yearsBucket = getParam('years_bucket');

$conds  = [];
$params = [];

if ($status === 'active')    { $conds[] = 'is_active = 1'; }
elseif ($status === 'left')  { $conds[] = 'is_active = 0'; }

if ($countries) {
    $ph = implode(',', array_fill(0, count($countries), '?'));
    $conds[] = "country IN ({$ph})";
    array_push($params, ...$countries);
}
if ($level)    { $conds[] = 'level_name = ?';        $params[] = $level; }
if ($gender)   { $conds[] = 'gender = ?';            $params[] = $gender; }
if ($languages) {
    $lc = array_fill(0, count($languages), 'languages LIKE ?');
    $conds[] = '(' . implode(' OR ', $lc) . ')';
    foreach ($languages as $l) $params[] = '%"' . $l . '"%';
}
if ($awardCat) { $conds[] = 'award_category LIKE ?'; $params[] = '%"' . $awardCat . '"%'; }
if ($socialNetwork) { $conds[] = 'id IN (SELECT mvp_id FROM mvp_social_networks WHERE network_name = ?)'; $params[] = $socialNetwork; }
if ($tenant) { $conds[] = 'tenants LIKE ?'; $params[] = '%"' . $tenant . '"%'; }
if ($yearsBucket) {
    $yExpr = "(julianday('now') - julianday(program_entry_date)) / 365.25";
    $bucketSql = "CASE WHEN {$yExpr} < 1 THEN '<1 year' WHEN {$yExpr} < 3 THEN '1-3 years' WHEN {$yExpr} < 5 THEN '3-5 years' WHEN {$yExpr} < 10 THEN '5-10 years' WHEN {$yExpr} < 20 THEN '10-20 years' ELSE '20+ years' END";
    $conds[] = "({$bucketSql}) = ? AND program_entry_date IS NOT NULL";
    $params[] = $yearsBucket;
}
if ($qText) {
    $conds[] = '(LOWER(first_name) LIKE ? OR LOWER(last_name) LIKE ? OR LOWER(first_name || \' \' || last_name) LIKE ? OR LOWER(headline) LIKE ? OR LOWER(biography) LIKE ?)';
    $like = '%' . $qText . '%';
    array_push($params, $like, $like, $like, $like, $like);
}

$baseWhere = $conds ? 'WHERE ' . implode(' AND ', $conds) : '';
$cte = "WITH filtered AS (SELECT * FROM mvps {$baseWhere})";

// Helper: run a query against the filtered CTE
$runQuery = function (string $sql, array $extra = []) use ($pdo, $cte, $params): array {
    $stmt = $pdo->prepare("{$cte} {$sql}");
    $stmt->execute(array_merge($params, $extra));
    return $stmt->fetchAll();
};

// By country (with map coordinates)
$countries = $runQuery("
    SELECT f.country, COUNT(*) AS count, g.latitude, g.longitude
    FROM filtered f
    LEFT JOIN country_geo g ON g.country = f.country
    WHERE f.country IS NOT NULL
    GROUP BY f.country ORDER BY count DESC
");

// By level
$levels = $runQuery("
    SELECT level_name, COUNT(*) AS count FROM filtered
    WHERE level_name IS NOT NULL
    GROUP BY level_name ORDER BY count DESC
");

// By gender
$genders = $runQuery("
    SELECT gender, COUNT(*) AS count FROM filtered
    WHERE gender IS NOT NULL
    GROUP BY gender ORDER BY count DESC
");

// Languages (flatten JSON arrays)
$langCounts = [];
foreach ($runQuery("SELECT languages FROM filtered WHERE languages IS NOT NULL") as $row) {
    $arr = json_decode($row['languages'] ?? '[]', true) ?: [];
    foreach ($arr as $lang) {
        $langCounts[$lang] = ($langCounts[$lang] ?? 0) + 1;
    }
}
arsort($langCounts);
$languages = array_map(fn($k, $v) => ['language' => $k, 'count' => $v], array_keys($langCounts), array_values($langCounts));

// Time in program (years buckets)
$bucketOrder = ['<1 year', '1-3 years', '3-5 years', '5-10 years', '10-20 years', '20+ years'];
$yearsBuckets = array_fill_keys($bucketOrder, 0);
foreach ($runQuery("SELECT program_entry_date FROM filtered WHERE program_entry_date IS NOT NULL") as $row) {
    $y = yearsBetween($row['program_entry_date']);
    if ($y === null) continue;
    $bucket = match(true) {
        $y < 1  => '<1 year',
        $y < 3  => '1-3 years',
        $y < 5  => '3-5 years',
        $y < 10 => '5-10 years',
        $y < 20 => '10-20 years',
        default => '20+ years',
    };
    $yearsBuckets[$bucket]++;
}
$timeInProgram = [];
foreach ($bucketOrder as $b) {
    if ($yearsBuckets[$b] > 0) {
        $timeInProgram[] = ['bucket' => $b, 'count' => $yearsBuckets[$b]];
    }
}

// Joins by year
$joinsByYear = $runQuery("
    SELECT substr(program_entry_date,1,4) AS year, COUNT(*) AS count
    FROM filtered WHERE program_entry_date IS NOT NULL
    GROUP BY year ORDER BY year
");

// Social networks
$socialNetworks = $runQuery("
    SELECT network_name, COUNT(*) AS count
    FROM mvp_social_networks sn
    JOIN filtered m ON m.id = sn.mvp_id
    WHERE network_name IS NOT NULL
    GROUP BY network_name ORDER BY count DESC
");

// Award categories (flatten JSON arrays)
$awardCounts = [];
foreach ($runQuery("SELECT award_category FROM filtered WHERE award_category IS NOT NULL") as $row) {
    $arr = json_decode($row['award_category'] ?? '[]', true) ?: [];
    foreach ($arr as $cat) {
        $awardCounts[$cat] = ($awardCounts[$cat] ?? 0) + 1;
    }
}
arsort($awardCounts);
$awardCategories = array_map(fn($k, $v) => ['category' => $k, 'count' => $v], array_keys($awardCounts), array_values($awardCounts));

// Technology focus areas (flatten JSON arrays)
$tfaCounts = [];
foreach ($runQuery("SELECT technology_focus_area FROM filtered WHERE technology_focus_area IS NOT NULL") as $row) {
    $arr = json_decode($row['technology_focus_area'] ?? '[]', true) ?: [];
    foreach ($arr as $area) {
        $tfaCounts[$area] = ($tfaCounts[$area] ?? 0) + 1;
    }
}
arsort($tfaCounts);
$technologyFocusAreas = array_map(fn($k, $v) => ['area' => $k, 'count' => $v], array_keys($tfaCounts), array_values($tfaCounts));

// Tenants (excluding MVP)
$tenantCounts = [];
foreach ($runQuery("SELECT tenants FROM filtered WHERE tenants IS NOT NULL") as $row) {
    $arr = json_decode($row['tenants'] ?? '[]', true) ?: [];
    foreach ($arr as $t) {
        if ($t === 'MVP') continue;
        $tenantCounts[$t] = ($tenantCounts[$t] ?? 0) + 1;
    }
}
arsort($tenantCounts);
$tenants = array_map(fn($k, $v) => ['tenant' => $k, 'count' => $v], array_keys($tenantCounts), array_values($tenantCounts));

// Titles
$titles = $runQuery("
    SELECT title, COUNT(*) AS count FROM filtered
    WHERE title IS NOT NULL AND title != ''
    GROUP BY title ORDER BY count DESC
");

// Top 10 companies
$companies = $runQuery("
    SELECT company_name, COUNT(*) AS count
    FROM filtered
    WHERE company_name IS NOT NULL AND TRIM(company_name) != ''
    GROUP BY company_name ORDER BY count DESC LIMIT 10
");

// Events count buckets (per MVP)
$bucketOrderEvt = ['0', '1-5', '6-15', '16-30', '30+'];
$evtRaw = $runQuery("
    SELECT CASE
        WHEN cnt = 0   THEN '0'
        WHEN cnt <= 5  THEN '1-5'
        WHEN cnt <= 15 THEN '6-15'
        WHEN cnt <= 30 THEN '16-30'
        ELSE '30+'
    END AS bucket, COUNT(*) AS count
    FROM (
        SELECT f.id, COUNT(e.id) AS cnt
        FROM filtered f
        LEFT JOIN mvp_events e ON e.mvp_id = f.id AND e.removed_at IS NULL
        GROUP BY f.id
    ) sub
    GROUP BY bucket
");
$evtMap = array_column($evtRaw, 'count', 'bucket');
$eventsBuckets = [];
foreach ($bucketOrderEvt as $b) {
    $eventsBuckets[] = ['bucket' => $b, 'count' => (int)($evtMap[$b] ?? 0)];
}

// Activities count buckets (per MVP)
$bucketOrderAct = ['0', '1-5', '6-15', '16-30', '30+'];
$actRaw = $runQuery("
    SELECT CASE
        WHEN cnt = 0   THEN '0'
        WHEN cnt <= 5  THEN '1-5'
        WHEN cnt <= 15 THEN '6-15'
        WHEN cnt <= 30 THEN '16-30'
        ELSE '30+'
    END AS bucket, COUNT(*) AS count
    FROM (
        SELECT f.id, COUNT(c.id) AS cnt
        FROM filtered f
        LEFT JOIN mvp_contributions c ON c.mvp_id = f.id AND c.removed_at IS NULL
        GROUP BY f.id
    ) sub
    GROUP BY bucket
");
$actMap = array_column($actRaw, 'count', 'bucket');
$activitiesBuckets = [];
foreach ($bucketOrderAct as $b) {
    $activitiesBuckets[] = ['bucket' => $b, 'count' => (int)($actMap[$b] ?? 0)];
}

jsonOut([
    'countries'            => $countries,
    'levels'               => $levels,
    'genders'              => $genders,
    'languages'            => $languages,
    'time_in_program'      => $timeInProgram,
    'joins_by_year'        => $joinsByYear,
    'social_networks'      => $socialNetworks,
    'tenants'              => $tenants,
    'titles'               => $titles,
    'award_categories'     => $awardCategories,
    'technology_focus_areas' => $technologyFocusAreas,
    'companies'            => $companies,
    'events_buckets'       => $eventsBuckets,
    'activities_buckets'   => $activitiesBuckets,
]);
