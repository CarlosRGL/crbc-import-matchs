# AGENTS.md — CRBC Import Matchs

## Purpose

WordPress plugin that imports basketball match data for CRBC66 (Canet Roussillon Basket Club) from Excel files (.xlsx). It supports two file types:

1. **Calendrier** — future matches without scores
2. **Résultats** — past matches with scores

The plugin lives under **Outils > Importer les matchs** in the WP admin.

---

## CPT & Taxonomy Structure

### `matchs` (Custom Post Type)

ACF fields:
| Field | Type | Notes |
|---|---|---|
| `date_du_match` | date_time_picker | Format: `Y-m-d H:i:s` |
| `adversaire` | post_object | Returns ID → `equipes-externes` CPT |
| `match_a_lexterieur` | true_false | `1` = away, `0` = home |
| `score_crbc` | text | Plain number string (e.g. `"73"`) |
| `score_adversaire` | text | Plain number string |

Taxonomy: `equipes-crbc` (terms = divisions like NF2, PNF, PNM, etc.)

Post title format:
- Home: `CRBC - NOM_ADVERSAIRE`
- Away: `NOM_ADVERSAIRE - CRBC`

### `equipes-externes` (Custom Post Type)

Only `post_title` matters. Each post represents an opponent team. Created automatically during import if missing.

---

## Excel File Formats

### Calendrier File

- **Sheet**: last sheet whose name starts with `rechercherRencontre`
- **Columns**: `Division | Equipe 1 | Equipe 2 | Date de rencontre | Heure`
- No score columns

### Résultats File

- **Sheet**: first sheet (index 0)
- **Columns**: `Division | N° de match | Equipe 1 | Equipe 2 | Date de rencontre | Heure | Salle | e-Marque V2 | Score 1 | Forfait 1 | Score 2 | Forfait 2`

### Data Quirks

- **Dates** may come as Excel serial numbers (floats > 1000) or strings in `DD/MM/YYYY` format
- **Times** may come as Excel time floats (0.0–1.0, where 0.5 = 12:00) or strings in `HH:MM` format
- Both cases are handled by `Importer::parse_date()`

---

## Architecture: AJAX Batch Processing

The import uses a 2-phase AJAX approach for non-blocking processing with live progress feedback.

### Phase 1 — Parse (`crbc_parse_files`)

1. User uploads files via the admin form
2. JS intercepts the submit and sends files to `crbc_parse_files` AJAX endpoint
3. Endpoint validates .xlsx files, moves to temp dir (`wp-content/uploads/crbc-import-tmp/`)
4. Calls `Importer::parse_file_to_rows()` to extract all rows as normalized arrays
5. Stores rows + stats in a transient (`crbc_import_job_{uuid}`, 30min expiry)
6. Returns `{ job_id, total_cal, total_res }`

### Phase 2 — Batch Process (`crbc_process_batch`)

1. JS loops through batches (default 5 rows each), first calendrier then résultats
2. Each call sends `{ job_id, file_type, offset, batch_size }`
3. Endpoint loads transient, processes the batch via `Importer::process_row_data()`
4. Returns per-row results with `action`, `title`, `matched_as` (fuzzy match indicator)
5. Stats accumulate across batches in the transient
6. When done: temp files cleaned up, transient deleted

### Transient Structure

```json
{
  "calendrier": {
    "rows": [...],
    "total": 42,
    "processed": 0,
    "file_path": "/path/to/tmp/uuid-calendrier.xlsx",
    "stats": { "created": 0, "updated": 0, "skipped": 0, "errors": [] }
  },
  "resultats": { ... }
}
```

---

## Fuzzy Name Matching (equipes-externes)

The `find_or_create_opponent()` method uses a cascade strategy to match opponent names to existing `equipes-externes` posts. All opponents are preloaded into memory once per import run.

### Cascade (stops at first hit):

1. **Exact match** — case-insensitive, trimmed comparison
2. **WP title contained in opponent name** — e.g. "NIMES" matches "NIMES BASKET". Longest match wins.
3. **Opponent name contained in WP title** — reverse containment check
4. **similar_text() > 60%** — PHP's built-in string similarity, highest percentage wins
5. **Create new** — if no match found, creates a new `equipes-externes` post

Fuzzy matches (steps 2–4) are reported in batch results as `"matched_as": "EXISTING_NAME"` for debugging.

Newly created opponents are added to the in-memory cache (`$all_opponents`) to prevent duplicate creation within the same import run.

---

## Import Logic

1. **Skip** rows where Equipe 1 or Equipe 2 = "Exempt" (done at parse time)
2. **Detect home/away**: "CANET RBC" in Equipe 1 → home (`0`), in Equipe 2 → away (`1`)
3. **Opponent** = the team that is NOT "CANET RBC"
4. **Clean opponent name**:
   - Strip leading `IE - ` prefix
   - Strip trailing ` - N (X)` suffix (regex: `/ - \d+ \(\d+\)$/`)
   - Strip trailing ` (X)` suffix (regex: `/ \(\d+\)$/`)
   - Trim whitespace
5. **Find/create** `equipes-externes` post (fuzzy cascade match)
6. **Find/create** `equipes-crbc` taxonomy term for the division
7. **Duplicate check**: query `matchs` by `date_du_match` + `adversaire` meta
   - Calendrier: duplicate → **skip**
   - Résultats: duplicate → **update scores only**; no duplicate → **create with scores**
8. **Scores** (résultats only): map Score 1/Score 2 based on which Equipe is CANET RBC

---

## File Structure

```
crbc-import-matchs/
├── crbc-import-matchs.php      # Plugin bootstrap, constants, autoloader, JS enqueue
├── includes/
│   ├── Admin.php               # Admin menu, AJAX handlers (parse + batch)
│   └── Importer.php            # Core import: parsing, fuzzy matching, row processing
├── views/
│   └── admin-page.php          # Admin page: upload form, progress bars, live log, summary
├── assets/
│   └── js/
│       └── crbc-import.js      # Vanilla JS: AJAX orchestration, progress UI, log rendering
├── composer.json               # PhpSpreadsheet dependency + PSR-4 autoload
├── composer.lock
├── vendor/                     # Composer autoload (gitignored)
├── AGENTS.md                   # This file
└── README.md                   # User-facing readme
```

---

## Key Classes & Methods

### `CRBC\ImportMatchs\Admin`

| Method | Purpose |
|---|---|
| `add_menu_page()` | Registers admin page under Outils |
| `ajax_parse_files()` | Phase 1: upload, validate, parse, store transient |
| `ajax_process_batch()` | Phase 2: process N rows, return results + stats |
| `render_page()` | Loads the admin view template |

### `CRBC\ImportMatchs\Importer`

| Method | Visibility | Purpose |
|---|---|---|
| `parse_file_to_rows($path, $type)` | public | Read Excel → array of normalized row data |
| `ensure_opponents_loaded()` | public | Preload all equipes-externes into memory |
| `process_row_data($entry, $type)` | public | Process one row: fuzzy match, duplicate check, create/update |
| `import_calendrier($path)` | public | Full import of calendrier file (legacy convenience) |
| `import_resultats($path)` | public | Full import of résultats file (legacy convenience) |
| `clean_opponent_name($name)` | private | Strips IE prefix and trailing suffixes |
| `parse_date($date, $time)` | private | Merges date + time, handles Excel serial numbers |
| `find_or_create_opponent($name)` | private | Cascade fuzzy match → create if missing |
| `find_or_create_division($div)` | private | Ensures equipes-crbc term exists |
| `find_existing_match($date, $id)` | private | WP_Query by date_du_match + adversaire meta |
| `create_match_post(...)` | private | Creates matchs post with all meta and taxonomy |

---

## Security

- **Nonce**: `crbc_import_matchs_action` — verified on all AJAX endpoints via `check_ajax_referer()`
- **Capability**: `manage_options` — checked on all AJAX endpoints
- **File validation**: only `.xlsx` extension accepted
- **All output escaped** with `esc_html()`
- **Temp files**: stored in `wp-content/uploads/crbc-import-tmp/`, cleaned up after processing

---

## Admin UI Flow

1. User sees two file input zones (calendrier + résultats)
2. Clicks "Analyser les fichiers" → JS sends files via fetch API
3. Loading spinner shown during parse phase
4. Progress section appears with bars per file type
5. Batches process sequentially, updating bars and appending log items
6. Each log item shows: icon + match title + action badge (created/updated/skipped/error)
7. Fuzzy matches show "(fuzzy: MATCHED_NAME)" in the log
8. Final summary cards display totals per category

---

## Extending / Maintaining

- **New Excel columns**: Add to `parse_file_to_rows()` parsing and `process_row_data()` logic
- **New file type**: Add parsing in `parse_file_to_rows()`, wire in Admin AJAX handlers
- **Change team name**: Update `Importer::TEAM_NAME` constant
- **Change opponent cleaning rules**: Update `Importer::clean_opponent_name()`
- **Change fuzzy match thresholds**: Update `find_or_create_opponent()` cascade logic
- **Change duplicate logic**: Update `Importer::find_existing_match()` meta_query
- **Change batch size**: Update `BATCH_SIZE` constant in `crbc-import.js`

### Dependencies

- PHP 7.4+
- PhpSpreadsheet ^2.0 (via Composer)
- ACF (fields stored as standard post_meta)
- CPTs `matchs` and `equipes-externes` registered elsewhere
- Taxonomy `equipes-crbc` registered elsewhere
