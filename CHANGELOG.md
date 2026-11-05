# Changelog

All notable changes to the TopX plugin are documented in this file.

## 1.1

- Ranking Chart readability: short interface/alias axis labels (full data source
  name on hover), the data source's own unit on the value axis (e.g. Bits,
  Utilization), locale-formatted tooltip and axis values instead of long raw
  floats, and a chart height that grows with the record count so Top 10/20/50
  stay legible.
- Chart colours: replaced the single flat grey bar fill (invisible on dark
  themes) with theme-friendly, green-forward per-bar colours. The Ranking Chart
  uses a readable categorical palette; the Uptime-by-Host chart uses an ordered
  red -> green range so longer uptime reads as "good", matching Cacti's theme
  severity conventions.
- Reworked the UI into draggable card dashboards using the shared Cacti plugin
  card engine (as in apcupsd/monitor/syslog): per-card tools (expand, maximize,
  refresh, remove), an "Add card" catalogue, drag/keyboard reorder, a Cacti
  timespan selector, an auto-refresh interval on the right and server-side
  layout persistence per user.
- Two dashboards: an all-data-source **Overview** (catalogue summary, source
  distribution, an Uptime-by-Host bucketed bar chart and a sortable Data Sources
  table with drilldown links) and a per-data-source **Drilldown** (rankings
  table, billboard ranking chart, summary tiles, average/peak gauges and a value
  distribution bar).
- Replaced the fixed "age" filter with Cacti's standard timespan selector; kept
  the data source, records, order and "what" filters.
- Added `includes/dashboard.php` (card engine + cards), `js/topx_dashboard.js`
  and `css/topx_dashboard.css`, all CSP-nonced and CSRF-protected; charts render
  from JSON data islands via billboard.js so they survive in-place AJAX refresh.
- Extracted the ranking computation into `topx_compute_ranking()` and added
  timespan-to-bucket and uptime-bucketing helpers, with unit coverage.

## 1.0

Modernization for Cacti 1.2.32 standards:

- Security: replaced every string-concatenated SQL query with prepared
  statements, binding the data template id and whitelisting all dynamic
  identifiers (dsstats table, order column, sort direction, record limit).
- Security: all request input is now validated against an allowlist through
  Cacti request helpers (`get_nfilter_request_var()`), and all output is run
  through `html_escape()`/`__esc()`. The filter form uses Cacti's standard
  `html_start_box()`/`get` form so it inherits Cacti's CSRF protection.
- Restructured the plugin API into an `includes/` directory
  (`includes/functions.php`, `includes/arrays.php`) with strongly typed,
  PHPDoc-documented functions.
- `setup.php` now registers the `config_arrays` hook, provisions its schema
  idempotently through `api_plugin_db_table_create()`, seeds default sources
  only when empty, and adds an install/upgrade/`check_config` lifecycle with
  manifest-driven file pruning.
- Internationalization: every user-facing string is wrapped in `__()`/`__esc()`
  with the `topx` text domain. Added the `locales/` tree with
  `build_gettext.sh`, `po/cacti.pot`, and the standard per-language catalogs.
- CSS/JS organization: consolidated the per-theme `themes/*.css` into a single
  theme-neutral `css/topx.css` (CSS custom properties + `currentColor`); moved
  the inline chart initialization into `js/topx.js` fed by a JSON data island,
  migrated the chart from the bundled Chart.js to Cacti's own billboard.js, and
  dropped the vendored `js/chartjs-plugin-annotation.min.js`.
- CSP: added the standard `plugin_topx_csp_nonce()` helper and applied the
  per-request nonce to the inline chart data island; eliminated all other inline
  executable JavaScript (the filter helper now lives in `js/topx.js`).
- Added a `manifest.json` (with a `themes/` tombstone) plus a Pest test suite
  and the shared CI tooling, GitHub workflows, Copilot instructions, and issue
  and pull request templates used across the Cacti plugin fleet.

## 0.7

- Limit display graph for more than 50 records.
- Add sort by peak.

## 0.6

- Add arithmetical operation again.
- Bug fixes.
- Graph display optimization.

## 0.5

- Add theme support.
- Remove custom data source poller code, using native dsstats.
- Remove arithmetical operation (dsstats did not support this).

## 0.4

- Add Chart.js graphs.
- Add human readable values.
- Fix empty source.

## 0.3

- Rewrite for Cacti 1.x.

## 0.2

- Fixed error - logging too much.

## 0.1

- Beginning.
