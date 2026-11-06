# Changelog

All notable changes to the TopX plugin are documented in this file.

## 1.2

- Clearer display-formatting fields on the Data Source editor: **Display Format**
  (renamed from "Display Scaling") with the options **Static Unit** (default) and
  **Division by Unit Ladder**; a dedicated **Decimal Places** dropdown (Auto, 0-4)
  that rounds in both formats; and the divisor ladder as **Display Unit Ladder**,
  hidden unless the Division format is selected. The old dual-purpose
  "Precision / Divisors" field is split — decimal places now live in their own
  column, migrated automatically on upgrade.
- The Metric Expression now actually applies a trailing `/N` divisor
  (e.g. `uptime=uptime/8640000` to turn SNMP uptime timeticks into days):
  previously the division was ignored and the raw value was charted and ranked.
  The scaled value is now used for the average, ranking and chart axis, not only
  the display formatter.
- The Ranking Chart now shows both the average and the peak on each bar as a
  bullet-style bar (a thin average "measure" bar nested inside a thicker,
  lighter peak band, via billboard's `bar.overlap`), and the good/warning/alert
  colours are a more vivid, theme-appropriate green/orange/red so the "good"
  state actually reads as green on dark themes like Deepness.
- The TopX tab pages (`topx.php`) now render with `general_header()` so they show
  as a tab without the Console left-hand menu.
- New mappings default the **Metric Expression** to `field=field` for a template
  that has a single RRD data source (so it ranks out of the box, as the common
  `users=users` case required), and the editor validates range thresholds on
  save: an increasing range must have Normal < Warning < Alert and a decreasing
  range Normal > Warning > Alert, raising a message when violated.
- The drilldown toolbar gains an **Edit Rule / Add Rule** link (for users with
  the management realm) that jumps to the Data Source editor for the shown
  source, and the editor shows a **live preview** of the definition's cards
  (stacked full-width) below Save/Return, so a change can be saved and seen
  without leaving the page. Ranking Chart labels no longer wrap onto two lines.
- New **Console → Management → TopX Data Sources** interface for managing the
  supported Data Source definitions without editing any PHP files. It lists both
  configured and unconfigured Data Templates with a `cdef.php`-style filter
  (Search, **Supported: All / Yes / No**, Rows), shows how many Data Sources use
  each template (in-use vs unused), and provides add/edit/delete plus XML
  **Import/Export** keyed on the portable Data Template hash, mirroring Cacti's
  own template import/export.
- Clicking an unconfigured ("Supported: No") Data Template in the grid now opens
  the editor pre-pointed at that template, so an administrator can turn any
  template into a supported source in a couple of clicks. Supported definitions
  whose stored hash matches no installed template are flagged "(template not
  installed)" rather than shown with a blank template.
- The grid's Status column renders the support state as a pill ("Supported" /
  "Not Supported", green/red) and the filter bar gains a **Has Data Sources**
  toggle switch (defaulting to Cacti's `default_has` setting, like the Data
  Templates page) to hide templates that no device uses.
- A single Data Template can now carry **multiple independent definitions** (for
  example traffic_in and traffic_out ranked separately): each definition is its
  own row in the grid and its own selectable entry on the dashboard. To rank by
  just one of several data sources, use a single definition with a `field=field`
  metric expression; add further definitions only when you want to rank several
  of a template's fields separately.
- The Data Source editor now offers clickable token helpers: under **Metric
  Expression** the template's RRD data source names, and under **Chart Label
  Pattern** the host replacements (`|host_description|`, ...) plus, for
  data-query templates, the `|query_<field>|` tokens. Clicking a token appends it
  to the field, and the lists refresh when the selected Data Template changes.
- Upgrades now grant the new management realm to everyone who can already view
  TopX (users and groups), and the upgrade runs from the `config_arrays` hook on
  every page, so administrators upgrading in place no longer get a "401
  Permission Denied" on the new management page.
- The `plugin_topx_source` table gains an `id` primary key (added automatically
  on upgrade, backfilled on legacy tables that never had one), which the
  management grid and import/export use to address individual definitions.
- Extended the `plugin_topx_source` schema with range semantics the original
  design lacked: `range_type` (increasing vs decreasing), optional
  `range_normal` / `range_warning` / `range_alert` health thresholds, and a
  declarative `chart_label` naming pattern. New columns are added idempotently on
  upgrade; the existing `operation`/`final_*` metric and display-scaling columns
  are retained.
- Charts can now be named with Cacti "suggested value" style `|token|`
  substitution (`|host_description|`, `|host_hostname|`, `|query_<field>|`, e.g.
  `|host_description| - |query_ifName|`), falling back to the derived short label
  when a pattern is blank or resolves away. When a source defines thresholds, the
  Ranking Chart bars are coloured by health band (normal/warning/alert).
- The "Values by % of Peak" distribution remains computed relative to the live
  peak of the returned set; absolute thresholds are a separate, operator-defined
  health signal.

## 1.1

- Only list data sources that are actually in use: the data source dropdown and
  the Overview tables/counts now exclude data source templates that no device
  uses, and count real data source instances rather than the template master.
- Renamed the drilldown "Value Distribution" card to "Values by % of Peak" and
  added an explanatory caption describing the value bands.
- The Overview "Data Sources" drilldown link now uses Cacti's ajax navigation
  (class `pic`) instead of a full page reload.
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
