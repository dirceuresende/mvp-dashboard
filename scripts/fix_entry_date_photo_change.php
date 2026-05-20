<?php
declare(strict_types=1);
/**
 * fix_entry_date_photo_change.php
 *
 * Corrects program_entry_date for MVPs whose date was overwritten with the
 * ticks from a profile picture change instead of the original award date.
 *
 * Strategy:
 *   For every MVP that has a picture_url change in mvp_history, retrieve the
 *   ORIGINAL picture_url (old_value of the earliest change) and recompute the
 *   entry date from those ticks. If the result is EARLIER than the currently
 *   stored date, update it — the current date was likely set from a newer photo.
 *
 * Usage:
 *   php scripts/fix_entry_date_photo_change.php          # dry-run (shows changes)
 *   php scripts/fix_entry_date_photo_change.php --apply  # write to DB
 */

require_once __DIR__ . '/../src/db.php';
require_once __DIR__ . '/../src/helpers.php';

$apply   = in_array('--apply', $argv ?? [], true);
$verbose = !in_array('--quiet', $argv ?? [], true);

$pdo = getConnection();

$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

// ── Fetch affected MVPs ────────────────────────────────────────────────────
// For each MVP with at least one picture_url change, also pull the original URL
// (the old_value of the earliest picture_url history entry = what the URL was
// before any photo change happened).
$rows = $pdo->query("
    SELECT
        m.id,
        m.first_name,
        m.last_name,
        m.first_awarded_date,
        m.years_in_program_api,
        m.program_entry_date           AS current_entry_date,
        m.picture_url                  AS current_picture_url,
        (
            SELECT h.old_value
            FROM   mvp_history h
            WHERE  h.mvp_id     = m.id
              AND  h.field_name  = 'picture_url'
              AND  h.change_type = 'updated'
            ORDER  BY h.changed_at ASC
            LIMIT  1
        )                              AS original_picture_url,
        (
            SELECT h.changed_at
            FROM   mvp_history h
            WHERE  h.mvp_id     = m.id
              AND  h.field_name  = 'picture_url'
              AND  h.change_type = 'updated'
            ORDER  BY h.changed_at ASC
            LIMIT  1
        )                              AS first_photo_change_at
    FROM mvps m
    WHERE m.is_active = 1
      AND EXISTS (
            SELECT 1 FROM mvp_history h2
            WHERE  h2.mvp_id     = m.id
              AND  h2.field_name  = 'picture_url'
              AND  h2.change_type = 'updated'
          )
    ORDER BY m.id
")->fetchAll(PDO::FETCH_ASSOC);

$total    = count($rows);
$toUpdate = 0;
$skipped  = 0;
$updates  = [];

foreach ($rows as $row) {
    $firstAwarded    = $row['first_awarded_date'] ?: null;
    $yearsApi        = $row['years_in_program_api'] !== null ? (int)$row['years_in_program_api'] : null;
    $currentEntry    = $row['current_entry_date'];
    $originalUrl     = $row['original_picture_url'];

    // ── Compute the correct entry date ──────────────────────────────────────
    if ($firstAwarded) {
        // Most authoritative: API-provided award date — always correct.
        $correctDate = $firstAwarded;
        $source      = 'first_awarded_date';
    } else {
        // Try ticks from the ORIGINAL picture URL (before any photo change).
        $ticksDate = $originalUrl ? extractTicksDate($originalUrl, $yearsApi, $now) : null;

        if ($ticksDate) {
            $correctDate = $ticksDate;
            $source      = 'original_photo_ticks';
        } elseif ($yearsApi !== null && $yearsApi >= 1) {
            // Fall back to July-1 formula using years_in_program_api.
            $correctDate = computeEntryDate(null, $now, $yearsApi, null);
            $source      = 'years_formula';
        } else {
            // Cannot determine a better date — skip.
            $skipped++;
            if ($verbose) {
                fwrite(STDERR, sprintf(
                    "[SKIP] id=%-6d %s %s — no reliable source (years_api=%s, original_url=%s)\n",
                    $row['id'],
                    $row['first_name'] ?? '?',
                    $row['last_name']  ?? '?',
                    $yearsApi ?? 'null',
                    $originalUrl ? 'present' : 'null'
                ));
            }
            continue;
        }
    }

    // Only fix if the new date is EARLIER than what is stored.
    // (A more recent computed date means the current value is already better.)
    if ($correctDate >= $currentEntry) {
        $skipped++;
        if ($verbose && $correctDate !== $currentEntry) {
            fwrite(STDERR, sprintf(
                "[SKIP] id=%-6d %s %s — computed %s >= current %s (source: %s)\n",
                $row['id'],
                $row['first_name'] ?? '?',
                $row['last_name']  ?? '?',
                $correctDate,
                $currentEntry ?? 'null',
                $source
            ));
        }
        continue;
    }

    $toUpdate++;
    $updates[] = [
        'id'           => $row['id'],
        'first_name'   => $row['first_name'],
        'last_name'    => $row['last_name'],
        'old_date'     => $currentEntry,
        'new_date'     => $correctDate,
        'source'       => $source,
        'photo_change' => $row['first_photo_change_at'],
    ];
}

// ── Report ─────────────────────────────────────────────────────────────────
echo sprintf(
    "Affected MVPs (photo changed):  %d\n" .
    "Need correction:                %d\n" .
    "Skipped (already correct):      %d\n",
    $total, $toUpdate, $skipped
);

if ($toUpdate === 0) {
    echo "Nothing to fix.\n";
    exit(0);
}

echo "\n";
printf("%-8s %-25s %-12s %-12s %-8s %s\n", 'ID', 'Name', 'Old date', 'New date', 'Source', 'First photo change');
echo str_repeat('-', 90) . "\n";
foreach ($updates as $u) {
    printf(
        "%-8d %-25s %-12s %-12s %-22s %s\n",
        $u['id'],
        substr(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? ''), 0, 24),
        $u['old_date'] ?? 'null',
        $u['new_date'],
        $u['source'],
        $u['photo_change'] ?? ''
    );
}
echo "\n";

if (!$apply) {
    echo "Dry-run — no changes written. Re-run with --apply to update the database.\n";
    exit(0);
}

// ── Apply ──────────────────────────────────────────────────────────────────
$stmt = $pdo->prepare("UPDATE mvps SET program_entry_date = ? WHERE id = ?");

$pdo->beginTransaction();
try {
    foreach ($updates as $u) {
        $stmt->execute([$u['new_date'], $u['id']]);
    }
    $pdo->commit();
} catch (Exception $e) {
    $pdo->rollBack();
    fwrite(STDERR, "ERROR: " . $e->getMessage() . "\n");
    exit(1);
}

echo sprintf("Done. Updated %d record(s).\n", $toUpdate);
