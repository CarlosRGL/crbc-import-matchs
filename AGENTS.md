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

## Import Logic

1. **Skip** rows where Equipe 1 or Equipe 2 = "Exempt"
2. **Detect home/away**: "CANET RBC" in Equipe 1 → home (`0`), in Equipe 2 → away (`1`)
3. **Opponent** = the team that is NOT "CANET RBC"
4. **Clean opponent name**:
   - Strip leading `IE - ` prefix
   - Strip trailing ` - N (X)` suffix (regex: `/ - \d+ \(\d+\)$/`)
   - Strip trailing ` (X)` suffix (regex: `/ \(\d+\)$/`)
   - Trim whitespace
5. **Find/create** `equipes-externes` post (case-insensitive title match)
6. **Find/create** `equipes-crbc` taxonomy term for the division
7. **Duplicate check**: query `matchs` by `date_du_match` + `adversaire` meta
   - Calendrier: duplicate → **skip**
   - Résultats: duplicate → **update scores only**; no duplicate → **create with scores**
8. **Scores** (résultats only): map Score 1/Score 2 based on which Equipe is CANET RBC

---

## File Structure

```
crbc-import-matchs/
├── crbc-import-matchs.php      # Plugin bootstrap, constants, autoloader
├── includes/
│   ├── Admin.php               # Admin menu page, upload handling, file validation
│   └── Importer.php            # Core import logic for both file types
├── views/
│   └── admin-page.php          # Admin page HTML/CSS template
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
| `handle_upload()` | Processes form submission (nonce + capability checks) |
| `validate_file()` | Checks upload errors and `.xlsx` extension |
| `render_page()` | Loads the admin view template |

### `CRBC\ImportMatchs\Importer`

| Method | Purpose |
|---|---|
| `import_calendrier($path)` | Parses calendrier Excel file |
| `import_resultats($path)` | Parses résultats Excel file |
| `process_row()` | Processes one row: clean name, find/create opponent, check duplicates, create/update post |
| `clean_opponent_name()` | Strips IE prefix and trailing suffixes |
| `parse_date()` | Merges date + time, handles Excel serial numbers and floats |
| `find_or_create_opponent()` | Case-insensitive lookup in `equipes-externes`, creates if missing |
| `find_or_create_division()` | Ensures `equipes-crbc` term exists |
| `find_existing_match()` | WP_Query by `date_du_match` + `adversaire` meta |
| `create_match_post()` | Creates `matchs` post with all meta and taxonomy |

---

## Security

- Nonce: `crbc_import_matchs_nonce` / action `crbc_import_matchs_action`
- Capability: `manage_options`
- File validation: only `.xlsx` extension accepted
- All output escaped with `esc_html()`

---

## Extending / Maintaining

- **New Excel columns**: Add to `process_row()` using `$header['Column Name']`
- **New file type**: Add a method like `import_calendrier()` / `import_resultats()` and wire it in `Admin::handle_upload()`
- **Change team name**: Update `Importer::TEAM_NAME` constant
- **Change opponent cleaning rules**: Update `Importer::clean_opponent_name()`
- **Change duplicate logic**: Update `Importer::find_existing_match()` meta_query

### Dependencies

- PHP 7.4+
- PhpSpreadsheet ^2.0 (via Composer)
- ACF (fields stored as standard post_meta)
- CPTs `matchs` and `equipes-externes` registered elsewhere
- Taxonomy `equipes-crbc` registered elsewhere
