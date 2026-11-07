# GitHub Copilot Instructions

## Priority Guidelines

When generating code for this repository:

1. **Version Compatibility**: This is a Cacti plugin (`topx`, version 1.0) targeting Cacti 1.2.32+
2. **Context Files**: Prioritize patterns and standards defined in this file (`.github/copilot-instructions.md`)
3. **Codebase Patterns**: When context files don't provide specific guidance, scan the codebase for established patterns
4. **Architectural Consistency**: Maintain plugin-based architecture extending Cacti core
5. **Code Quality**: Prioritize security, maintainability, and compatibility in all generated code

## Technology Stack

### Core Technologies
- **PHP**: 8.1+ (write for the lowest PHP version the CI matrix tests; do not use language features beyond what CI tests)
- **Platform**: Cacti Plugin Architecture (Cacti 1.2.32+), "TopX" highest/lowest data source reporting
- **Database**: MySQL/MariaDB, through Cacti's dsstats rollup tables
- **Frontend**: jQuery-based UI (`js/`), billboard.js horizontal bar chart (bundled by Cacti)

### Key Dependencies
- Cacti core framework (`api_plugin_*`, `db_*_prepared()`, `cacti_log()`, Cacti's dsstats tables)
- `includes/functions.php` for the plugin's query/formatting helpers
- `includes/arrays.php` for the translated filter option arrays
- Optional: `gettext` for internationalization

## Project Structure

```
topx/                                  # Repository root (install to plugins/topx/ in Cacti)
├── css/                               # Theme-neutral styling (css/topx.css)
├── images/                            # Console tab images
├── includes/                          # Plugin API: functions.php, arrays.php
├── js/                                # topx.js chart init (billboard.js, bundled by Cacti core)
├── locales/                           # gettext tree (build_gettext.sh, po/, LC_MESSAGES/)
├── screenshots/                       # README imagery
├── tests/                             # Pest suite (phpunit.xml, bootstrap, Unit, bin tooling)
├── topx.php                           # Main TopX view page
├── INFO                               # Plugin metadata (name, version, compat)
├── manifest.json                      # Shipped-file manifest + tombstones for upgrade pruning
├── README.md / CHANGELOG.md
└── setup.php                          # Plugin install/uninstall/upgrade hooks
```

## Naming Conventions

### Function Names
- Plugin lifecycle functions use the `plugin_topx_` prefix (`plugin_topx_install()`,
  `plugin_topx_uninstall()`, `plugin_topx_upgrade()`, `plugin_topx_version()`,
  `plugin_topx_check_config()`).
- Hook callbacks and helpers use the `topx_` prefix directly (`topx_config_arrays()`,
  `topx_show_tab()`, `topx_setup_database()`, `topx_final_operation()`, `topx_age_table()`).
- Match the existing prefix used by the function you are editing; do not introduce a new scheme.

### Variables and Constants
- Use snake_case for variables; access Cacti configuration via `$config['base_path']` /
  `$config['url_path']`.

## Code Style

### Indentation and Formatting
- **Tabs**: Use tabs (not spaces) for indentation throughout all PHP files.
- **Braces**: Opening brace on the same line for functions and control structures.
- **Spacing**: Space after control structure keywords (`if`, `foreach`, `while`).
- Prefer single-quoted strings and short arrays (`[]`).

### File Headers
ALL PHP/JS/CSS files MUST include the standard GPL v2 license header used throughout this
repository (see `setup.php`), crediting "Petr Macek" (original author) and "The Cacti Group".

## Security Standards

### SQL Query Security
**ALWAYS use prepared statements** (`db_*_prepared()`) — never concatenate request input into SQL.
A table name, column name or sort direction can never be bound as a parameter, so validate those
against a strict allowlist first. This plugin's allowlists are `topx_age_table()`,
`topx_order_column()`, `topx_sort_direction()` and `topx_record_limit()` in `includes/functions.php`;
route every dynamic identifier through them.

### Output Escaping
Use `html_escape()` / `__esc()` for anything printed into HTML. Encode chart/JSON data with
`json_encode()` and the `JSON_HEX_*` flags, never by string concatenation.

### Input Validation
Use `get_request_var()` / `get_nfilter_request_var()` for request input; never read `$_GET`/`$_POST`
directly. Validate every externally controlled value against an allowlist (the filter arrays in
`includes/arrays.php`) before use.

### Logging
Use `cacti_log()` with a `TOPX` severity tag, matching the existing message style.

## Database Operations
Use Cacti's `db_*_prepared()` functions. Provision schema with the idempotent
`api_plugin_db_table_create()` / `api_plugin_db_add_column()` helpers from both the install and
upgrade paths; never emit raw `CREATE TABLE`/`ALTER TABLE`.

## Internationalization
Wrap every user-facing string in `__()`/`__esc()`/`__n()` with the `'topx'` text domain. Translatable
strings are managed with GNU gettext via `locales/build_gettext.sh`; `locales/po/cacti.pot` is the
source template. When a change adds or alters a translatable string, regenerate `cacti.pot` and
commit it.

## Plugin Architecture

### Plugin Hooks
Register hooks and the realm in `setup.php`:

```php
api_plugin_register_hook('topx', 'config_arrays',         'topx_config_arrays', 'setup.php');
api_plugin_register_hook('topx', 'top_header_tabs',       'topx_show_tab',      'setup.php');
api_plugin_register_hook('topx', 'top_graph_header_tabs', 'topx_show_tab',      'setup.php');

api_plugin_register_realm('topx', 'topx.php', 'Plugin TopX - View', 1);
```

## Best Practices
1. Keep the plugin API in `includes/`; keep the web entry point (`topx.php`) thin and push pure,
   testable logic (formatting, query building, whitelisting) into `includes/functions.php`.
2. Reuse existing escaping/logging/config-path helpers rather than introducing new ones.
3. Match existing frontend patterns in `js/` (jQuery, JSON data islands) rather than inlining PHP
   values into executable JavaScript.
4. Keep CSS theme-neutral in `css/topx.css` using CSS custom properties and `currentColor`; do not
   re-introduce per-theme stylesheets.

## CI & Dependency Baselines
- Do not commit a plugin-local `composer.json`/`composer.lock`, `.phpstan.neon` or
  `.php-cs-fixer.php`. The shared CI workflow uses Cacti's own Composer/vendor tree and lint config.
- Prefer Cacti's `cacti_count()`/`cacti_sizeof()` wrappers over raw `count()`/`sizeof()`.

## File manifest & upgrade pruning
The plugin ships a root `manifest.json` with `tombstones` (files/directories older versions shipped
that have since moved or been removed), `expected` (the top-level files/directories shipped today,
directories with a trailing `/`), and `whitelist` (user-data paths that must never be touched). CI
runs `tests/bin/validate-manifest.php`, which fails on drift between `expected` and the real tree
(`tests/`, `phpunit.xml`, `.git*` and whitelisted paths are ignored). `topx_prune_files()` removes
tombstoned paths plus the dev-only `tests/`/`phpunit.xml` on upgrade and refuses any tombstone that
resolves outside the plugin directory. When you move or delete a shipped file, add its old path to
`tombstones` and update `expected` in the same change.

## References
- [Cacti main repo](https://github.com/Cacti/cacti/tree/1.2.x)
- `README.md`, `CHANGELOG.md`
