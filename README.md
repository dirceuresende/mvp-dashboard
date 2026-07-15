# MVP Extract — Dashboard

Scrapes Microsoft MVP profiles from the Maven API, stores them in SQLite with
full change history, and serves a web dashboard with analytics, filters, and a
world map.

Stack: **PHP 8.5 · SQLite · Apache (or PHP built-in server)**

## Requirements

- PHP 8.5+ with extensions: `curl`, `sqlite3`, `mbstring`, `xml`, `opcache`
- Apache with `mod_rewrite` enabled (or use the PHP built-in server for local dev)
- A Linux server (Debian/Ubuntu) for production — see [`install.txt`](install.txt)

## Quick start (local dev)

```bash
# Clone the repo and start the built-in PHP server
php -S localhost:8080 public/index.php
```

Open <http://localhost:8080>. The setup page is shown until the first scan runs.

## Data collection scripts

All scripts are PHP CLI and live in `scripts/`. The database file is created
automatically at `data/mvps.sqlite` on first run.

### `sync.php` — recommended: scan + enrich in one run

```bash
php scripts/sync.php                   # scan + enrich new/unenriched MVPs
php scripts/sync.php --force-enrich    # scan + re-enrich ALL active MVPs
php scripts/sync.php --no-enrich       # scan only, skip enrichment
php scripts/sync.php --workers 20      # override parallel workers (default: 10)
php scripts/sync.php --force           # bypass the suspicious-left safety guard
```

### `scan.php` — scan only

```bash
php scripts/scan.php
```

Fetches all MVP profiles from the search API and upserts them into SQLite.

What each scan does:
- Inserts new MVPs (with a computed `program_entry_date`)
- Detects updated fields and writes rows to `mvp_history`
- Marks MVPs that disappeared as `left_at = today`, `is_active = 0`
- Re-marks previously-left MVPs as `returned` if they reappear

Safety guards abort the run if the API returns fewer than 500 profiles, or if
more than 10 % of active MVPs appear to have left in a single scan.

### `enrich.php` — profile enrichment

```bash
php scripts/enrich.php            # enrich only un-enriched MVPs
php scripts/enrich.php --force    # re-enrich all active MVPs
```

Fetches extended profile data (award category, technology focus areas,
functional roles, company) from the individual profile API using `curl_multi`
with up to 10 parallel requests.

If a profile returns **HTTP 404** from the enrichment endpoint, it is skipped
(logged as a warning) — the profile may simply have no enrichment data available.
Marking a profile as inactive is handled exclusively by `scan.php` when the
profile disappears from the main MVP list.

### `update_activities.php` — contributions & events

```bash
php scripts/update_activities.php                # update stale MVPs (7+ days old)
php scripts/update_activities.php --force        # re-fetch all enriched MVPs
php scripts/update_activities.php --limit 500    # cap at 500 MVPs per run
```

Fetches Featured Contributions and Events for each enriched MVP, storing them in
`mvp_contributions` and `mvp_events`.

### `backfill_ticks.php` — utility

Re-derives `program_entry_date` from picture-URL ticks, validated against
`years_in_program_api`. Reverts invalid ticks dates to the July-1 formula
fallback. Run once after bulk imports or data corrections.

```bash
php scripts/backfill_ticks.php
```

## Suggested cron schedule

```cron
# Dias 3–31: scan sem enrich às 07h UTC (evita custo do enrich diariamente)
0 7 3-31 * * cd /var/www/html && php -d max_execution_time=0 scripts/sync.php --no-enrich >> logs/scan.log 2>&1

# Dias 1–2 do mês: scan todo hora às HH:00 UTC (captura entradas/saídas do início do mês)
0 * 1,2 * * cd /var/www/html && php -d max_execution_time=0 scripts/sync.php --no-enrich >> logs/scan.log 2>&1

# Dia 15 de julho: mesmo scan horário mais leve (data de corte adicional)
0 * 15 7 * cd /var/www/html && php -d max_execution_time=0 scripts/sync.php --no-enrich >> logs/scan.log 2>&1

# Todo domingo às 00h UTC: re-enrich completo + update de atividades/eventos em seguida
# (update_activities.php --force é chamado automaticamente ao final do --force-enrich)
0 0 * * 0 cd /var/www/html && php -d max_execution_time=0 scripts/sync.php --force-enrich >> logs/enrich.log 2>&1
```

> **Nota:** `sync.php --force-enrich` chama `update_activities.php --force` automaticamente ao terminar,
> então não é necessário um job separado para atividades/eventos.

## Production installation (Linux)

See [`install.txt`](install.txt) for the full step-by-step guide covering:
- System dependencies
- PHP 8.5 from sury.org repository
- Apache configuration with `AllowOverride All`
- File permissions (`dirceu:www-data`)

## Schema

See [`app/schema.sql`](app/schema.sql). Key tables:

| table                  | purpose                                          |
|------------------------|--------------------------------------------------|
| `mvps`                 | current state of each MVP                        |
| `mvp_social_networks`  | one row per social link per MVP                  |
| `mvp_schools`          | education entries                                |
| `mvp_contributions`    | featured contributions (from activities API)     |
| `mvp_events`           | events (from activities API)                     |
| `mvp_history`          | audit trail (`created`/`updated`/`left`/`returned`) |
| `scans`                | one row per scan execution                       |
| `country_geo`          | lat/lng per country (for the world map)          |

## API endpoints

All endpoints are served from `/api/` by `public/index.php`.

| method | path                  | description                              |
|--------|-----------------------|------------------------------------------|
| GET    | `/api/stats`          | active MVP count, countries, last scan   |
| GET    | `/api/filters`        | available filter values                  |
| GET    | `/api/aggregations`   | grouped counts (country, level, gender…) |
| GET    | `/api/mvps`           | paginated MVP list with filters (supports `q` for name, headline, bio, and **profile ID**) |
| GET    | `/api/mvps/{id}`      | single MVP detail                        |
| GET    | `/api/activities`     | contributions & events for an MVP        |
| GET    | `/api/scans`          | scan history                             |
| GET    | `/api/setup`          | setup status                             |

## Entry-date logic for new MVPs

When a profile first appears and `program_entry_date` must be computed:

1. **Real date** — API returns a valid `firstAwardedDate` (year > 2000) → use it.
2. **Ticks date** — profile picture URL contains .NET ticks → decode and validate
   against `years_in_program_api`; use if plausible.
3. **Formula** — `years_in_program_api >= 1` → `July 1 of (current_year − years)`.
4. **Fallback** — first day of the discovery month.

## Notes

- The search endpoint (`/api/UserProfiles/search/`) returns all ~5 k profiles in
  one call (~15 MB). No pagination is needed.
- Award category and technology focus areas are not part of the search payload;
  `enrich.php` retrieves them from the individual profile API.

## Dashboard features

- **Charts tab** — world map, top countries, time-in-program, and other aggregated charts.
- **Full List tab** — paginated, sortable table with all active/left/all MVPs.
- **New MVPs / Leaving MVPs tabs** — focused views for recent joins and departures.
- **Active filters bar** — a yellow notice bar appears below the filter row whenever
  any filter is active, listing each applied filter as a chip. Disappears when all
  filters are reset.
- **Search** — the `q` field searches name, headline, biography, and **profile ID** (GUID).
- **MVP detail modal** — shows profile ID with a copy-to-clipboard button, program
  dates, social networks, education, activity history, and change log.
- **i18n** — English, Portuguese (Brazil), and Spanish; all labels update live on
  language switch.
