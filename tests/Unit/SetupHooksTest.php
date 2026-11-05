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

	it('registers a single view realm for topx.php', function (): void {
		plugin_topx_install();

		expect($GLOBALS['__test_registered_realms'])->toHaveCount(1);
		expect($GLOBALS['__test_registered_realms'][0]['file'])->toBe('topx.php');
	});

	it('provisions both plugin tables idempotently', function (): void {
		plugin_topx_install();

		$tables = array_values(array_filter(array_map(function ($call) {
			return $call['fn'] === 'api_plugin_db_table_create' ? $call['table'] : null;
		}, $GLOBALS['__test_db_calls'])));

		expect($tables)->toContain('plugin_topx_source');
		expect($tables)->toContain('plugin_topx_average');
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
