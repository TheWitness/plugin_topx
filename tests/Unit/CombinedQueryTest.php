<?php

require_once __DIR__ . '/../bootstrap-unit.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';

describe('topx_get_ds_list', function (): void {
	it('merges the supported and unsupported template queries', function (): void {
		$GLOBALS['__test_db_fetch_assoc'] = function ($sql) {
			if (strpos($sql, 'plugin_topx_source') !== false) {
				return [['dsname' => 'CPU', 'dsid' => '3', 'dscount' => '2', 'sup' => 'true']];
			}

			return [['dsname' => 'unsupported - RAM', 'dsid' => '9', 'dscount' => '1', 'sup' => 'false']];
		};

		$list = topx_get_ds_list();

		expect($list)->toHaveKey(-3);
		expect($list)->toHaveKey(9);

		unset($GLOBALS['__test_db_fetch_assoc']);
	});
});

describe('topx_combined_sum_average', function (): void {
	it('binds the rrd names and template id in order and averages the rows', function (): void {
		$captured = null;

		$GLOBALS['__test_db_fetch_assoc_prepared'] = function ($sql, $params) use (&$captured) {
			$captured = ['sql' => $sql, 'params' => $params];

			return [['xvalue' => 10], ['xvalue' => 20]];
		};

		$avg = topx_combined_sum_average('data_source_stats_hourly', 42, 'discards_in', 'errors_in');

		expect($avg)->toBe(15.0);
		expect($captured['params'])->toBe(['discards_in', 42, 'errors_in']);
		expect($captured['sql'])->toContain('data_source_stats_hourly');

		unset($GLOBALS['__test_db_fetch_assoc_prepared']);
	});
});

describe('topx_combined_result', function (): void {
	it('builds a whitelisted ordered query with the scaling multiplier', function (): void {
		$captured = null;

		$GLOBALS['__test_db_fetch_assoc_prepared'] = function ($sql, $params) use (&$captured) {
			$captured = ['sql' => $sql, 'params' => $params];

			return [['name' => 'eth0', 'ldid' => 1, 'xvalue' => 5, 'xpeak' => 9]];
		};

		$rows = topx_combined_result('data_source_stats_daily', 7, 'traffic_in', 'traffic_out', 8, 'xvalue', 'DESC', ' LIMIT 5');

		expect($rows)->toHaveCount(1);
		expect($captured['params'])->toBe(['traffic_in', 'traffic_in', 7, 'traffic_out']);
		expect($captured['sql'])->toContain('8 * average');
		expect($captured['sql'])->toContain('ORDER BY xvalue DESC LIMIT 5');

		unset($GLOBALS['__test_db_fetch_assoc_prepared']);
	});
});
