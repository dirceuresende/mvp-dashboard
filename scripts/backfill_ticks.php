<?php
/**
 * Re-backfill program_entry_date from picture_url ticks with validation
 * against years_in_program_api. Reverts invalid ticks dates (photo updates)
 * to the July-1 formula fallback.
 */
require __DIR__ . '/../src/helpers.php';

$pdo = new PDO('sqlite:' . __DIR__ . '/../data/mvps.sqlite');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$now    = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$nowIso = $now->format('Y-m-d\TH:i:s\Z');

$rows = $pdo->query("
    SELECT id, first_name, last_name, picture_url, first_awarded_date,
           years_in_program_api, program_entry_date
    FROM mvps
    WHERE is_active = 1
")->fetchAll(PDO::FETCH_ASSOC);

$set_ticks   = 0;
$set_formula = 0;
$set_real    = 0;
$unchanged   = 0;

$stmt = $pdo->prepare("UPDATE mvps SET program_entry_date = ? WHERE id = ?");

foreach ($rows as $row) {
    $firstAwarded = $row['first_awarded_date'] ?: null;
    $yearsApi     = $row['years_in_program_api'] !== null ? (int)$row['years_in_program_api'] : null;

    // Priority 1: real first_awarded_date
    if ($firstAwarded) {
        $newDate = $firstAwarded;
        $source  = 'real';
    } else {
        // Priority 2: ticks with validation
        $ticksDate = extractTicksDate($row['picture_url'], $yearsApi, $now);
        if ($ticksDate) {
            $newDate = $ticksDate;
            $source  = 'ticks';
        } elseif ($yearsApi) {
            // Priority 3: July-1 formula
            $newDate = computeEntryDate(null, $now, $yearsApi, null);
            $source  = 'formula';
        } else {
            $newDate = null;
            $source  = 'none';
        }
    }

    if ($newDate === $row['program_entry_date']) {
        $unchanged++;
        continue;
    }

    $stmt->execute([$newDate, $row['id']]);

    if ($source === 'real')    $set_real++;
    elseif ($source === 'ticks')   $set_ticks++;
    elseif ($source === 'formula') $set_formula++;
}

echo "Unchanged:       $unchanged\n";
echo "Updated (real):    $set_real\n";
echo "Updated (ticks):   $set_ticks\n";
echo "Updated (formula): $set_formula\n";
echo "Total active: " . count($rows) . "\n";
