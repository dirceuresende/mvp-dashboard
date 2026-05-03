# MVP Extract

Scrapes Microsoft MVP profiles from <https://mvp.microsoft.com>, stores them
in SQLite with full change history, and serves a web dashboard with
analytics, filters, and a world map.

## Setup

```powershell
python -m venv .venv
.\.venv\Scripts\Activate.ps1
pip install -r requirements.txt
```

## Run a scan

```powershell
python -m app.scraper
```

The first run creates `data/mvps.sqlite` and inserts every MVP currently
listed on the site. Subsequent runs:

- Insert new MVPs (with computed `program_entry_date`)
- Detect updated fields and write rows to `mvp_history`
- Mark MVPs that disappeared as `left_at = today` and `is_active = 0`
- Re-mark previously-left MVPs as `returned` if they reappear
- Snapshot raw JSON per MVP per scan in `mvp_snapshots`

## Run the dashboard

```powershell
python -m app.app
```

Open <http://127.0.0.1:5000>.

### Google Maps API key

Edit `app/templates/dashboard.html` and replace `YOUR_GOOGLE_MAPS_API_KEY`
with your own key (Maps JavaScript API enabled). Without a key the map
widget will fail silently — the rest of the dashboard works fine.

## Schema

See `app/schema.sql`. Key tables:

| table                   | purpose                                  |
|-------------------------|------------------------------------------|
| `mvps`                  | current state of each MVP                |
| `mvp_social_networks`   | one row per social link                  |
| `mvp_schools`           | education entries                        |
| `mvp_history`           | audit trail (created/updated/left/returned) |
| `mvp_snapshots`         | raw API JSON per MVP per scan            |
| `scans`                 | one row per scan execution               |
| `country_geo`           | lat/lng per country (for map)            |

## Entry-date logic for new MVPs

When a profile first appears in the scan and we have to decide its
`program_entry_date`:

1. If the API returns a real `firstAwardedDate` (year > 2000) — use it.
2. Else (epoch placeholder) — assume "1 year in program" and set
   `day-01 of the discovery month`.
3. If a future enrichment step provides `years_in_program > 1` — the
   helper `compute_entry_date` returns `01/07 of (current_year - years)`.

## Notes

- The site exposes a single search endpoint
  (`/api/UserProfiles/search/`) that returns *all* profiles in one call
  (~5k records, ~15 MB). No pagination is needed.
- Award category and technology areas are not part of that public
  payload; the public profile pages render them client-side. Hooking up
  a Playwright fallback to enrich those fields is left as a follow-up.
