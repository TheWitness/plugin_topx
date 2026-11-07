# Changelog

All notable changes to the TopX plugin are documented in this file.

## 1.2

* feature: Add a **Console → Management → TopX Data Sources** interface to manage supported definitions (Search / Supported / Has-Data-Sources filters, add, edit, delete, and XML import/export keyed on the Data Template hash) with no PHP editing.
* feature: Show each template's support state as a **Supported / Not Supported** pill, and open the editor pre-pointed at a template when you click an unconfigured one.
* feature: Flag definitions whose stored hash matches no installed template as "(template not installed)" instead of showing a blank template.
* feature: Allow a single Data Template to carry multiple independent definitions, each its own grid row and dashboard entry.
* feature: Give the Data Source editor clickable token helpers for the Metric Expression fields and the Chart Label Pattern (host and `|query_<field>|` tokens).
* feature: Add an **Edit Rule / Add Rule** link on the drilldown toolbar and a live preview of the definition's cards in the editor.
* feature: Extend the `plugin_topx_source` schema with `range_type`, `range_normal`/`warning`/`alert` thresholds, a declarative `chart_label`, and an `id` primary key, all added idempotently on upgrade.
* feature: Name chart items with Cacti "suggested value" `|token|` substitution (e.g. `|host_description| - |query_ifName|`), falling back to a derived short label.
* feature: Apply a trailing `/N` divisor in the Metric Expression (e.g. `uptime=uptime/8640000`) to the average, ranking and chart axis.
* feature: Default the Metric Expression to `field=field` for single-field templates and validate range threshold ordering on save.
* feature: Clarify the editor's display formatting with **Display Format** (Static Unit / Division by Unit Ladder), a dedicated **Decimal Places** dropdown, and a **Display Unit Ladder** shown only for the Division format.
* feature: Compose the **Overview** tab from individual supported Data Source cards via the Add card dropdown, with a shared **TopX** record-limit selector that governs only the data-source cards.
* feature: Rework the UI into draggable card dashboards (Overview + per-source Drilldown) on the shared Cacti card engine, with per-card tools, a timespan selector, auto-refresh and per-user layout persistence.
* feature: Show the Ranking Chart average and peak on each bar as a bullet-style bar with vivid, theme-appropriate health colours, a green band that runs up to the Warning threshold, and a Peak/Avg legend.
* feature: Render a plain, theme-legible Peak/Average bullet for sources with no health range instead of painting every bar as an alert.
* feature: Improve Ranking Chart readability with short axis labels (full name on hover), the source's own unit on the axis, locale-formatted values, and a height that grows with the record count.
* feature: Use an ordered red→green range for the Uptime-by-Host chart and theme-friendly categorical colours elsewhere.
* feature: Only list and count data sources that are actually in use, excluding unused templates.
* feature: Replace the fixed "age" filter with Cacti's standard timespan selector while keeping the data source, records, order and "what" filters.
* feature: Extract the ranking computation into `topx_compute_ranking()` with timespan-to-bucket and uptime-bucketing helpers and unit coverage.
* feature: Render the TopX tab pages with `general_header()` so they show as a tab without the Console left-hand menu.
* feature: Replace every string-concatenated SQL query with prepared statements and allowlisted dynamic identifiers.
* feature: Validate all request input through Cacti request helpers, escape all output, and CSRF-protect every save, bulk-action and import.
* feature: Restructure the plugin API into a strongly typed, PHPDoc-documented `includes/` directory.
* feature: Make `setup.php` register the `config_arrays` hook, provision its schema idempotently, seed defaults only when empty, and grant the management realm on upgrade so in-place upgrades avoid a 401.
* feature: Internationalize every user-facing string with the `topx` text domain and add the `locales/` gettext tree.
* feature: Consolidate per-theme CSS into a single theme-neutral `css/topx.css` and migrate charts from the bundled Chart.js to Cacti's billboard.js, rendered from CSP-nonced JSON data islands.
* feature: Add a `manifest.json` with upgrade-time file pruning, a Pest test suite, and the shared CI tooling, workflows and templates.

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
