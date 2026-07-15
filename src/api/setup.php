<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/db.php';

$method     = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$baseDir    = dirname(__DIR__, 2);
$lockFile   = $baseDir . '/data/setup.lock';
$logFile    = $baseDir . '/data/setup.log';
$syncScript = $baseDir . '/scripts/sync.php';

// PHP_BINARY is empty when PHP runs as an Apache module (mod_php).
// Fall back to searching for 'php' or 'php8' in PATH.
$phpBin = PHP_BINARY;
if (empty($phpBin) || !is_executable($phpBin)) {
    foreach (['php8.4', 'php8.3', 'php8.2', 'php8.1', 'php8', 'php'] as $candidate) {
        $found = shell_exec('command -v ' . escapeshellarg($candidate) . ' 2>/dev/null');
        if ($found) { $phpBin = trim($found); break; }
    }
}

// ── Cron registration ─────────────────────────────────────────────────────────

function setupRegisterCrons(string $baseDir, string $phpBin): array
{
    $cronFlag = $baseDir . '/data/crons.installed';
    if (file_exists($cronFlag)) {
        return ['skipped' => true];
    }

    $logDir = $baseDir . '/logs';
    @mkdir($logDir, 0755, true);

    if (PHP_OS_FAMILY === 'Windows') {
        $result = setupCronsWindows($baseDir, $phpBin, $logDir);
    } else {
        $result = setupCronsLinux($baseDir, $phpBin, $logDir);
    }

    if (!empty($result['ok'])) {
        file_put_contents($cronFlag, date('c'));
    }
    return $result;
}

function setupCronsLinux(string $baseDir, string $phpBin, string $logDir): array
{
    $php  = escapeshellarg($phpBin);
    $dir  = escapeshellarg($baseDir);
    $sync = escapeshellarg($baseDir . '/scripts/sync.php');
    $slog = $logDir . '/scan.log';
    $elog = $logDir . '/enrich.log';

    $jobs = [
        "0 7 3-31 * * cd {$dir} && {$php} -d max_execution_time=0 {$sync} --no-enrich >> {$slog} 2>&1",
        "0 * 1,2 * * cd {$dir} && {$php} -d max_execution_time=0 {$sync} --no-enrich >> {$slog} 2>&1",
        "0 * 15 7 * cd {$dir} && {$php} -d max_execution_time=0 {$sync} --no-enrich >> {$slog} 2>&1",
        "0 0 * * 0   cd {$dir} && {$php} -d max_execution_time=0 {$sync} --force-enrich >> {$elog} 2>&1",
    ];

    $existing = shell_exec('crontab -l 2>/dev/null') ?? '';
    $lines    = array_filter(explode("\n", rtrim($existing)));

    $added = 0;
    foreach ($jobs as $job) {
        if (!in_array($job, $lines, true)) {
            $lines[] = $job;
            $added++;
        }
    }

    $tmp = tempnam(sys_get_temp_dir(), 'mvpcron_');
    file_put_contents($tmp, implode("\n", $lines) . "\n");
    exec('crontab ' . escapeshellarg($tmp) . ' 2>&1', $out, $code);
    @unlink($tmp);

    if ($code !== 0) {
        return ['ok' => false, 'error' => implode(' ', $out)];
    }
    return ['ok' => true, 'jobs_added' => $added];
}

function setupCronsWindows(string $baseDir, string $phpBin, string $logDir): array
{
    // schtasks /Create requires elevated privileges — attempt and report result
    $bd   = str_replace('/', '\\', $baseDir);
    $php  = str_replace('/', '\\', $phpBin);
    $slog = str_replace('/', '\\', $logDir . '\\scan.log');
    $elog = str_replace('/', '\\', $logDir . '\\enrich.log');
    $sync = $bd . '\\scripts\\sync.php';

    // Build PowerShell command strings (double-escaped for /TR argument)
    // Light-scan window: days 1-2 of any month, plus July 15th.
    $scanTr = sprintf(
        'powershell -NonInteractive -Command "$d=[int](Get-Date -Format \'dd\'); $m=[int](Get-Date -Format \'MM\'); if (-not ($d -le 2 -or ($d -eq 15 -and $m -eq 7))) { Set-Location \'%s\'; & \'%s\' -d max_execution_time=0 \'%s\' --no-enrich >> \'%s\' 2>&1 }"',
        $bd, $php, $sync, $slog
    );
    $scan12Tr = sprintf(
        'powershell -NonInteractive -Command "$d=[int](Get-Date -Format \'dd\'); $m=[int](Get-Date -Format \'MM\'); if ($d -le 2 -or ($d -eq 15 -and $m -eq 7)) { Set-Location \'%s\'; & \'%s\' -d max_execution_time=0 \'%s\' --no-enrich >> \'%s\' 2>&1 }"',
        $bd, $php, $sync, $slog
    );
    $enrichTr = sprintf(
        'powershell -NonInteractive -Command "Set-Location \'%s\'; & \'%s\' -d max_execution_time=0 \'%s\' --force-enrich >> \'%s\' 2>&1"',
        $bd, $php, $sync, $elog
    );

    $cmds = [
        'schtasks /Create /TN "MVP-Extract\\ScanDiario"           /SC DAILY  /ST 07:00        /F /RL HIGHEST /TR ' . escapeshellarg($scanTr),
        'schtasks /Create /TN "MVP-Extract\\ScanHorarioDias01e02" /SC HOURLY                  /F /RL HIGHEST /TR ' . escapeshellarg($scan12Tr),
        'schtasks /Create /TN "MVP-Extract\\EnrichSemanal"        /SC WEEKLY /D SUN /ST 00:00 /F /RL HIGHEST /TR ' . escapeshellarg($enrichTr),
    ];

    $errors = [];
    foreach ($cmds as $cmd) {
        exec($cmd . ' 2>&1', $out, $code);
        if ($code !== 0) {
            $errors[] = trim(implode(' ', $out));
        }
    }

    if (empty($errors)) {
        return ['ok' => true, 'jobs_added' => 3];
    }
    // Likely a permissions issue — not fatal, user can run manually
    return ['ok' => false, 'error' => $errors[0]];
}

// ── POST — start sync ─────────────────────────────────────────────────────────
if ($method === 'POST') {
    $pdo = initDb();

    // Already done?
    $mvpCount = (int)$pdo->query("SELECT COUNT(*) FROM mvps WHERE is_active=1")->fetchColumn();
    $doneScan = (int)$pdo->query("SELECT COUNT(*) FROM scans WHERE finished_at IS NOT NULL AND total_mvps > 0")->fetchColumn();
    if ($mvpCount > 0 && $doneScan > 0) {
        echo json_encode(['started' => false, 'already_ready' => true]);
        exit;
    }

    // Already running?
    if (file_exists($lockFile)) {
        echo json_encode(['started' => false, 'already_running' => true]);
        exit;
    }

    // Register cron jobs (idempotent — skips if already done)
    $cronResult = setupRegisterCrons($baseDir, $phpBin);

    // Create lock file with current timestamp
    file_put_contents($lockFile, (string)time());

    // Launch sync.php in background (cross-platform)
    if (empty($phpBin)) {
        echo json_encode(['error' => 'PHP binary not found. Set PHP_BINARY or ensure php is in PATH.']);
        exit;
    }
    if (PHP_OS_FAMILY === 'Windows') {
        $cmd = 'start "" /B '
            . escapeshellarg($phpBin)
            . ' -d max_execution_time=0 '
            . escapeshellarg($syncScript)
            . ' > ' . escapeshellarg($logFile) . ' 2>&1';
        pclose(popen($cmd, 'r'));
    } else {
        $cmd = sprintf(
            'nohup %s -d max_execution_time=0 %s > %s 2>&1 &',
            escapeshellarg($phpBin),
            escapeshellarg($syncScript),
            escapeshellarg($logFile)
        );
        exec($cmd);
    }

    echo json_encode(['started' => true, 'crons' => $cronResult]);
    exit;
}

// ── GET — status ──────────────────────────────────────────────────────────────
$pdo = initDb();

$mvpCount     = (int)$pdo->query("SELECT COUNT(*) FROM mvps WHERE is_active=1")->fetchColumn();
$doneScan     = (int)$pdo->query("SELECT COUNT(*) FROM scans WHERE finished_at IS NOT NULL AND total_mvps > 0")->fetchColumn();
$inProgress   = (int)$pdo->query("SELECT COUNT(*) FROM scans WHERE finished_at IS NULL")->fetchColumn() > 0;
$scanComplete = $doneScan > 0;

// Clean up stale lock when scan has finished
if ($scanComplete && file_exists($lockFile)) {
    @unlink($lockFile);
}

// Detect stale lock (process likely crashed): lock older than 20 min, no active scan
$failed = false;
if (!$scanComplete && file_exists($lockFile)) {
    $lockAge = time() - (int)file_get_contents($lockFile);
    if ($lockAge > 1200 && !$inProgress) {
        @unlink($lockFile);
        $failed = true;
    }
}

$ready   = $scanComplete && $mvpCount > 0;
$elapsed = file_exists($lockFile) ? (time() - (int)file_get_contents($lockFile)) : 0;

$progressFile = $baseDir . '/data/setup.progress';
$progressData = [];
if (file_exists($progressFile)) {
    $raw = file_get_contents($progressFile);
    // Support both old plain-int format and new JSON format
    $progressData = (is_numeric(trim($raw)))
        ? ['phase' => 'scan', 'total' => (int)$raw]
        : (json_decode($raw, true) ?: []);
}
$progressPhase = $progressData['phase'] ?? null;
$totalExpected = $progressData['total'] ?? 0;
$enrichDone    = $progressData['done']  ?? 0;

// Clean up progress file when done
if ($ready && file_exists($progressFile)) {
    @unlink($progressFile);
}

echo json_encode([
    'ready'           => $ready,
    'mvp_count'       => $mvpCount,
    'phase'           => $progressPhase,          // 'scan' | 'enrich' | null
    'total_expected'  => $totalExpected,           // total profiles (scan phase)
    'enrich_done'     => $enrichDone,              // enriched so far (enrich phase)
    'enrich_total'    => $progressPhase === 'enrich' ? $totalExpected : 0,
    'scanning'        => !$failed && ($inProgress || file_exists($lockFile)),
    'failed'          => $failed,
    'elapsed_seconds' => $elapsed,
]);
