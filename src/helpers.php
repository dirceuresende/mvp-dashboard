<?php
declare(strict_types=1);

const JSON_ARRAY_FIELDS = [
    'languages', 'tenants', 'award_category',
    'technology_focus_area', 'functional_roles',
];

/**
 * Decode JSON array fields in a DB row.
 */
function rowToArray(array $row): array
{
    foreach (JSON_ARRAY_FIELDS as $field) {
        if (isset($row[$field]) && is_string($row[$field]) && $row[$field] !== '') {
            $decoded = json_decode($row[$field], true);
            if ($decoded !== null) {
                $row[$field] = $decoded;
            }
        }
    }
    return $row;
}

/**
 * Compute years between an ISO date string and today (or a given end date).
 */
function yearsBetween(?string $startIso, ?DateTimeImmutable $end = null): ?float
{
    if (!$startIso) {
        return null;
    }
    try {
        $start = new DateTimeImmutable(substr($startIso, 0, 10));
    } catch (Exception) {
        return null;
    }
    $end ??= new DateTimeImmutable('today');
    $diff = $start->diff($end);
    return (float) ceil($diff->days / 365.25);
}

/**
 * Compute program entry date for a new MVP (mirrors Python compute_entry_date).
 *
 * Rules:
 *   1. If first_awarded is a real date -> use it.
 *   2. Else if years_in_program > 1 -> 01/07 of (current_year - years).
 *   3. Else -> day-01 of discovery month.
 */
function computeEntryDate(?string $firstAwarded, DateTimeImmutable $now, ?int $yearsInProgram = null, ?string $ticksDate = null): string
{
    if ($firstAwarded) {
        return $firstAwarded;
    }
    if ($ticksDate) {
        return $ticksDate;
    }
    if ($yearsInProgram && $yearsInProgram >= 1) {
        // July 1 is the annual renewal date; use it as the best estimate anchor.
        $currentYear = (int)$now->format('Y');
        $formula     = sprintf('%04d-07-01', $currentYear - $yearsInProgram);
        // Cap at current month — entry date can never be in the future.
        $cap = $now->format('Y-m-01');
        return $formula <= $cap ? $formula : $cap;
    }
    return $now->format('Y-m-01');
}

/**
 * Extract the date from the .NET ticks in a profile picture URL.
 *
 * Picture URLs have the form:
 *   https://images.mvp.microsoft.com/{guid}?{ticks}
 *
 * The ticks are 100-nanosecond intervals since 0001-01-01 UTC (.NET DateTime).
 * They represent when the profile picture was uploaded, which for most new MVPs
 * coincides (within a few days) with their actual award date.
 *
 * Returns null if the URL has no ticks, the decoded date is before 2010, or the date
 * is inconsistent with $yearsInProgram (indicating the photo was changed after entry).
 *
 * Validation (when $yearsInProgram and $now are provided):
 *   yearsInProgram = ceil(days_since_entry / 365.25), so the ticks date should be
 *   between (yearsInProgram - 1) * 365 and yearsInProgram * 365 days ago.
 *   A ±180-day tolerance covers photo upload delays and rounding edge cases.
 *   If the ticks date is too recent (person updated photo), it is discarded.
 */
function extractTicksDate(?string $pictureUrl, ?int $yearsInProgram = null, ?DateTimeImmutable $now = null): ?string
{
    if (!$pictureUrl) {
        return null;
    }
    if (!preg_match('/\?(\d{15,})$/', $pictureUrl, $m)) {
        return null;
    }
    $ticks = (int)$m[1];
    // .NET ticks to Unix timestamp (seconds): offset = 621355968000000000 ticks
    $unixSeconds = ($ticks - 621355968000000000) / 10_000_000;
    if ($unixSeconds < 0) {
        return null;
    }
    $ticksDt = (new DateTimeImmutable())->setTimestamp((int)$unixSeconds);
    $date    = $ticksDt->format('Y-m-d');
    if ((int)substr($date, 0, 4) < 2010) {
        return null;
    }
    // Validate against yearsInProgram when available
    if ($yearsInProgram !== null && $yearsInProgram >= 1 && $now !== null) {
        $daysAgo = (int)(($now->getTimestamp() - $ticksDt->getTimestamp()) / 86400);
        $minDays = max(0, ($yearsInProgram - 1) * 365 - 180);
        $maxDays = $yearsInProgram * 365 + 180;
        if ($daysAgo < $minDays || $daysAgo > $maxDays) {
            return null; // ticks date inconsistent with yearsInProgram — photo was updated
        }
    }
    return $date;
}

/**
 * Parse firstAwardedDate from API: returns ISO string only for real dates (year > 2000).
 */
function parseFirstAwarded(?string $value): ?string
{
    if (!$value) {
        return null;
    }
    $ts = strtotime(str_replace('Z', '+00:00', $value));
    if ($ts === false) {
        return null;
    }
    if ((int)date('Y', $ts) < 2000) {
        return null;
    }
    return date('Y-m-d', $ts);
}

/**
 * Emit JSON response and exit.
 */
function jsonOut(mixed $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function getParam(string $key, string $default = ''): string
{
    return trim((string)($_GET[$key] ?? $default));
}

function getIntParam(string $key, int $default = 1): int
{
    return max(1, (int)($_GET[$key] ?? $default));
}
