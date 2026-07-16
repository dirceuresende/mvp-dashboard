<?php
declare(strict_types=1);
/**
 * Prune mvp_snapshots — reclaims disk space from historical raw-JSON
 * snapshots that scan.php/sync.php write on every new/updated/returned MVP.
 *
 * Keeps, per MVP: the single most recent snapshot, plus any snapshot newer
 * than --keep-days (default 90). Everything else is deleted. Run VACUUM
 * afterwards to actually shrink the .sqlite file on disk.
 *
 * Usage:
 *   php scripts/prune_snapshots.php                 # dry run, keep-days=90
 *   php scripts/prune_snapshots.php --apply          # actually delete
 *   php scripts/prune_snapshots.php --apply --keep-days 30
 */

require_once __DIR__ . '/../src/db.php';

$args      = $argv ?? [];
$apply     = in_array('--apply', $args, true);
$daysIdx   = array_search('--keep-days', $args, true);
$keepDays  = ($daysIdx !== false && isset($args[$daysIdx + 1])) ? max(0, (int)$args[$daysIdx + 1]) : 90;

$pdo = initDb();

$cutoff = (new DateTimeImmutable("-{$keepDays} days", new DateTimeZone('UTC')))->format('Y-m-d\TH:i:sP');

// A snapshot is deletable if it is NOT the most recent one for its mvp_id
// AND it is older than the retention cutoff.
$countStmt = $pdo->prepare("
    SELECT COUNT(*) FROM mvp_snapshots s
    WHERE s.captured_at < ?
      AND s.id <> (
          SELECT s2.id FROM mvp_snapshots s2
          WHERE s2.mvp_id = s.mvp_id
          ORDER BY s2.captured_at DESC, s2.id DESC
          LIMIT 1
      )
");
$countStmt->execute([$cutoff]);
$toDelete = (int)$countStmt->fetchColumn();
$countStmt->closeCursor();
unset($countStmt);

$total = (int)$pdo->query('SELECT COUNT(*) FROM mvp_snapshots')->fetchColumn();

fwrite(STDERR, sprintf(
    "[PRUNE] %d/%d snapshot rows are older than %s and not each MVP's latest.\n",
    $toDelete, $total, $cutoff
));

if (!$apply) {
    fwrite(STDERR, "[PRUNE] Dry run only — pass --apply to actually delete, then VACUUM.\n");
    exit(0);
}

$deleteStmt = $pdo->prepare("
    DELETE FROM mvp_snapshots
    WHERE captured_at < ?
      AND id <> (
          SELECT s2.id FROM mvp_snapshots s2
          WHERE s2.mvp_id = mvp_snapshots.mvp_id
          ORDER BY s2.captured_at DESC, s2.id DESC
          LIMIT 1
      )
");
$deleteStmt->execute([$cutoff]);
$deleted = $deleteStmt->rowCount();
$deleteStmt->closeCursor();
unset($deleteStmt);
fwrite(STDERR, sprintf("[PRUNE] Deleted %d rows.\n", $deleted));

$sizeBefore = filesize(DB_PATH);

$freeBytes = disk_free_space(dirname(DB_PATH));
if ($freeBytes !== false && $freeBytes < $sizeBefore) {
    fwrite(STDERR, sprintf(
        "[PRUNE] ABORTED — not enough free disk space for VACUUM. Need ~%.1f MB, only %.1f MB free.\n",
        $sizeBefore / 1048576, $freeBytes / 1048576
    ));
    exit(1);
}

fwrite(STDERR, sprintf("[PRUNE] File size before VACUUM: %.1f MB. Running VACUUM (needs ~that much free disk space)...\n", $sizeBefore / 1048576));

// VACUUM builds a full temporary copy of the database before swapping it in.
// The connection defaults to PRAGMA temp_store=MEMORY (see src/db.php), which
// would force that multi-GB temp copy into RAM and risk an OOM kill on small
// VMs — switch to disk-backed temp storage for this operation only.
$pdo->exec('PRAGMA temp_store = FILE');
$pdo->exec('VACUUM');

clearstatcache(true, DB_PATH);
$sizeAfter = filesize(DB_PATH);
fwrite(STDERR, sprintf(
    "[PRUNE] Done. File size after VACUUM: %.1f MB (freed %.1f MB).\n",
    $sizeAfter / 1048576, ($sizeBefore - $sizeAfter) / 1048576
));
