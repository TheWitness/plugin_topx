<?php

require_once __DIR__ . '/../bootstrap-unit.php';
require_once dirname(__DIR__, 2) . '/setup.php';

describe('plugin_topx_install', function (): void {
	beforeEach(function (): void {
		$GLOBALS['__test_registered_hooks']  = [];
		$GLOBALS['__test_registered_realms'] = [];
		$GLOBALS['__test_db_calls']          = [];
	});

	it('registers the config_arrays and tab hooks', function (): void {
		plugin_topx_install();

		$hooks = array_column($GLOBALS['__test_registered_hooks'], 'hook');

		expect($hooks)->toContain('config_arrays');
		expect($hooks)->toContain('top_header_tabs');
		expect($hooks)->toContain('top_graph_header_tabs');
	});

	it('registers the view and management realms', function (): void {
		plugin_topx_install();

		expect($GLOBALS['__test_registered_realms'])->toHaveCount(2);

		$files = array_column($GLOBALS['__test_registered_realms'], 'file');

		expect($files)->toContain('topx.php');
		expect($files)->toContain('topx_sources.php');
	});

	it('provisions both plugin tables idempotently', function (): void {
		plugin_topx_install();

		$tables = array_values(array_filter(array_map(function ($call) {
			return $call['fn'] === 'api_plugin_db_table_create' ? $call['table'] : null;
		}, $GLOBALS['__test_db_calls'])));

		expect($tables)->toContain('plugin_topx_source');
		expect($tables)->toContain('plugin_topx_average');
	});

	it('defines plugin_topx_source with an id primary key', function (): void {
		plugin_topx_install();

		$create = null;

		foreach ($GLOBALS['__test_db_calls'] as $call) {
			if (($call['fn'] ?? '') === 'api_plugin_db_table_create' && ($call['table'] ?? '') === 'plugin_topx_source') {
				$create = $call['data'];
			}
		}

		expect($create)->not->toBeNull();
		expect($create['primary'])->toBe('id');

		$names = array_column($create['columns'], 'name');
		expect($names)->toContain('id');
	});

	it('backfills the id column when a legacy table lacks it', function (): void {
		$GLOBALS['__test_db_calls'] = [];

		// bootstrap db_column_exists() returns false, i.e. a pre-UI table.
		plugin_topx_install();

		$alters = array_values(array_filter($GLOBALS['__test_db_calls'], function ($call) {
			return ($call['fn'] ?? '') === 'db_execute'
				&& strpos($call['sql'] ?? '', 'ADD COLUMN id') !== false
				&& strpos($call['sql'] ?? '', 'auto_increment') !== false;
		}));

		expect($alters)->toHaveCount(1);
	});

	it('migrates the decimal places out of final_number on first upgrade', function (): void {
		$GLOBALS['__test_db_calls'] = [];

		// The table already exists (an upgrade), final_decimals is absent on the
		// pre-check (so a migration is needed) and present afterwards (added by
		// the schema refresh); every other column already exists so the id
		// backfill is skipped.
		$GLOBALS['__test_table_exists'] = ['plugin_topx_source' => true];

		$seen = 0;
		$GLOBALS['__test_db_column_exists'] = function ($t, $c) use (&$seen) {
			if ($c === 'final_decimals') {
				$seen++;

				return $seen > 1;
			}

			return true;
		};

		// Sources already exist, so seeding is skipped.
		$GLOBALS['__test_db_fetch_cell'] = function ($sql) {
			return 9;
		};

		topx_setup_database();

		$migrations = array_values(array_filter($GLOBALS['__test_db_calls'], function ($call) {
			return ($call['fn'] ?? '') === 'db_execute'
				&& strpos($call['sql'] ?? '', 'final_decimals = final_number') !== false;
		}));

		expect($migrations)->toHaveCount(1);

		unset($GLOBALS['__test_db_column_exists'], $GLOBALS['__test_db_fetch_cell'], $GLOBALS['__test_table_exists']);
	});
});

describe('topx_seed_sources', function (): void {
	it('skips seeding when sources already exist', function (): void {
		$GLOBALS['__test_db_calls']     = [];
		$GLOBALS['__test_db_fetch_cell'] = function ($sql) {
			return 9;
		};

		topx_seed_sources();

		$inserts = array_filter($GLOBALS['__test_db_calls'], function ($call) {
			return $call['fn'] === 'db_execute_prepared';
		});

		expect($inserts)->toBeEmpty();

		unset($GLOBALS['__test_db_fetch_cell']);
	});

	it('seeds the default sources when the table is empty', function (): void {
		$GLOBALS['__test_db_calls']      = [];
		$GLOBALS['__test_db_fetch_cell'] = function ($sql) {
			return 0;
		};

		topx_seed_sources();

		$inserts = array_filter($GLOBALS['__test_db_calls'], function ($call) {
			return $call['fn'] === 'db_execute_prepared';
		});

		expect(count($inserts))->toBe(9);

		unset($GLOBALS['__test_db_fetch_cell']);
	});
});

describe('plugin_topx_version', function (): void {
	it('reads the version from the INFO file', function (): void {
		$tmp = sys_get_temp_dir() . '/topx_info_' . uniqid();
		mkdir($tmp . '/plugins/topx', 0777, true);
		copy(dirname(__DIR__, 2) . '/INFO', $tmp . '/plugins/topx/INFO');

		$GLOBALS['config']['base_path'] = $tmp;

		$info = plugin_topx_version();

		expect($info)->toHaveKey('version');
		expect($info['name'])->toBe('topx');

		unlink($tmp . '/plugins/topx/INFO');
		rmdir($tmp . '/plugins/topx');
		rmdir($tmp . '/plugins');
		rmdir($tmp);
	});
});

describe('plugin_topx_check_config', function (): void {
	it('returns true and short-circuits the upgrade off relevant pages', function (): void {
		test_set_current_page('graph_view.php');

		expect(plugin_topx_check_config())->toBeTrue();
	});
});

describe('plugin_topx_upgrade', function (): void {
	it('registers the management realm and nav hook catch-ups on a relevant page', function (): void {
		$tmp = sys_get_temp_dir() . '/topx_upg_' . uniqid();
		mkdir($tmp . '/plugins/topx', 0777, true);
		copy(dirname(__DIR__, 2) . '/INFO', $tmp . '/plugins/topx/INFO');

		$GLOBALS['config']['base_path']      = $tmp;
		$GLOBALS['__test_registered_hooks']  = [];
		$GLOBALS['__test_registered_realms'] = [];

		// Falsy db_fetch_cell => the hook/realm catch-up guards both fire.
		$GLOBALS['__test_db_fetch_cell'] = function ($sql) {
			return '';
		};

		test_set_current_page('index.php');

		expect(plugin_topx_upgrade())->toBeFalse();

		$realm_files = array_column($GLOBALS['__test_registered_realms'], 'file');
		expect($realm_files)->toContain('topx_sources.php');

		unset($GLOBALS['__test_db_fetch_cell']);

		unlink($tmp . '/plugins/topx/INFO');
		rmdir($tmp . '/plugins/topx');
		rmdir($tmp . '/plugins');
		rmdir($tmp);
	});
});

describe('topx_draw_navigation_text', function (): void {
	it('registers the management page breadcrumb entries', function (): void {
		$nav = topx_draw_navigation_text([]);

		expect($nav)->toHaveKey('topx_sources.php:');
		expect($nav)->toHaveKey('topx_sources.php:edit');
		expect($nav)->toHaveKey('topx_sources.php:import');
		expect($nav)->toHaveKey('topx_sources.php:actions');
		expect($nav['topx_sources.php:']['url'])->toBe('topx_sources.php');
	});
});

describe('topx_show_tab', function (): void {
	afterEach(function (): void {
		unset($GLOBALS['__test_is_realm_allowed'], $GLOBALS['__test_api_user_realm_auth']);
	});

	it('renders the TopX console tab link', function (): void {
		$_SERVER['PHP_SELF'] = '/plugins/topx/topx.php';

		ob_start();
		topx_show_tab();
		$html = (string) ob_get_clean();

		expect($html)->toContain('plugins/topx/topx.php');
	});

	it('renders nothing for a user without the realm', function (): void {
		$GLOBALS['__test_is_realm_allowed']    = function ($r) { return false; };
		$GLOBALS['__test_api_user_realm_auth'] = function ($f) { return false; };

		ob_start();
		topx_show_tab();
		$html = (string) ob_get_clean();

		expect($html)->toBe('');
	});
});

describe('topx_upgrade_management_realm', function (): void {
	afterEach(function (): void {
		unset($GLOBALS['__test_db_fetch_cell']);

		$GLOBALS['__test_db_calls']          = [];
		$GLOBALS['__test_registered_realms'] = [];
	});

	it('is named so Cacti api_plugin_valid_entrypoint permits realm registration', function (): void {
		// Cacti only allows api_plugin_register_realm() when the directly-calling
		// function name matches this regex; renaming the function would make the
		// realm registration silently fail (the cause of the upgrade 401).
		expect(preg_match('/(install|upgrade|setup)/i', 'topx_upgrade_management_realm'))->toBe(1);
	});

	it('registers the management realm when it is missing', function (): void {
		$GLOBALS['__test_registered_realms'] = [];

		// No management realm yet; the view realm exists.
		$GLOBALS['__test_db_fetch_cell'] = function ($sql) {
			if (strpos($sql, "file = 'topx.php'") !== false) {
				return 3;
			}

			return '';
		};

		topx_upgrade_management_realm();

		$files = array_column($GLOBALS['__test_registered_realms'], 'file');
		expect($files)->toContain('topx_sources.php');
	});

	it('grants the management realm to existing view-realm holders', function (): void {
		$GLOBALS['__test_db_calls'] = [];

		$GLOBALS['__test_db_fetch_cell'] = function ($sql) {
			if (strpos($sql, "file = 'topx_sources.php'") !== false) {
				return 5;
			}

			if (strpos($sql, "file = 'topx.php'") !== false) {
				return 3;
			}

			return '';
		};

		topx_upgrade_management_realm();

		$grants = array_values(array_filter($GLOBALS['__test_db_calls'], function ($call) {
			return $call['fn'] === 'db_execute_prepared' && strpos($call['sql'], 'INSERT IGNORE INTO user_auth_realm') !== false;
		}));

		expect($grants)->toHaveCount(1);
		expect($grants[0]['params'])->toBe([105, 103]);
	});

	it('does nothing when the view realm is absent', function (): void {
		$GLOBALS['__test_db_calls'] = [];

		$GLOBALS['__test_db_fetch_cell'] = function ($sql) {
			if (strpos($sql, "file = 'topx_sources.php'") !== false) {
				return 5;
			}

			return '';
		};

		topx_upgrade_management_realm();

		$grants = array_values(array_filter($GLOBALS['__test_db_calls'], function ($call) {
			return $call['fn'] === 'db_execute_prepared' && strpos($call['sql'], 'user_auth_realm') !== false;
		}));

		expect($grants)->toBe([]);
	});
});

describe('plugin_topx_uninstall', function (): void {
	it('clears the version and drops both plugin tables', function (): void {
		$GLOBALS['__test_db_calls']               = [];
		$GLOBALS['__test_set_config_option_calls'] = [];

		plugin_topx_uninstall();

		$drops = array_values(array_filter(array_map(function ($call) {
			return $call['fn'] === 'db_execute' ? $call['sql'] : null;
		}, $GLOBALS['__test_db_calls'])));

		expect($drops)->toContain('DROP TABLE IF EXISTS plugin_topx_source');
		expect($drops)->toContain('DROP TABLE IF EXISTS plugin_topx_average');
		expect($GLOBALS['__test_set_config_option_calls'][0]['name'])->toBe('topx_version');
	});
});

describe('topx_rmtree', function (): void {
	it('recursively removes a directory tree', function (): void {
		$root = sys_get_temp_dir() . '/topx_rmtree_' . uniqid();
		mkdir($root . '/nested', 0777, true);
		file_put_contents($root . '/nested/file.txt', 'x');

		expect(topx_rmtree($root))->toBeTrue();
		expect(is_dir($root))->toBeFalse();
	});
});
