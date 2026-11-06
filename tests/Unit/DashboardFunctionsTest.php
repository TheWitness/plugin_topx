<?php

require_once __DIR__ . '/../bootstrap-unit.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';

describe('topx_timespan_bucket', function (): void {
	it('maps short windows to the hourly table', function (): void {
		expect(topx_timespan_bucket(3600))->toBe('data_source_stats_hourly');
	});

	it('maps a day or two to the daily table', function (): void {
		expect(topx_timespan_bucket(86400))->toBe('data_source_stats_daily');
	});

	it('maps a week-ish window to the weekly table', function (): void {
		expect(topx_timespan_bucket(604800))->toBe('data_source_stats_weekly');
	});

	it('maps a month-ish window to the monthly table', function (): void {
		expect(topx_timespan_bucket(2592000))->toBe('data_source_stats_monthly');
	});

	it('maps long windows to the yearly table', function (): void {
		expect(topx_timespan_bucket(31536000))->toBe('data_source_stats_yearly');
	});
});

describe('topx_uptime_bucket_days', function (): void {
	it('counts each value in its range', function (): void {
		$buckets = topx_uptime_bucket_days([0.5, 3, 20, 90, 400]);

		expect($buckets['lt_day'])->toBe(1);
		expect($buckets['day_week'])->toBe(1);
		expect($buckets['week_month'])->toBe(1);
		expect($buckets['month_six'])->toBe(1);
		expect($buckets['gt_six'])->toBe(1);
	});

	it('uses the lower bound inclusively at range edges', function (): void {
		$buckets = topx_uptime_bucket_days([1, 7, 30, 182]);

		expect($buckets['lt_day'])->toBe(0);
		expect($buckets['day_week'])->toBe(1);
		expect($buckets['week_month'])->toBe(1);
		expect($buckets['month_six'])->toBe(1);
		expect($buckets['gt_six'])->toBe(1);
	});

	it('returns all-zero counts for an empty list', function (): void {
		expect(array_sum(topx_uptime_bucket_days([])))->toBe(0);
	});
});

describe('topx_overview_stats', function (): void {
	it('aggregates supported/unsupported types and source counts', function (): void {
		$GLOBALS['__test_db_fetch_assoc'] = function ($sql) {
			if (strpos($sql, 'plugin_topx_source') !== false) {
				return [
					['dsname' => 'CPU', 'dsid' => '1', 'dscount' => '3', 'sup' => 'true'],
					['dsname' => 'Load', 'dsid' => '2', 'dscount' => '2', 'sup' => 'true'],
				];
			}

			return [['dsname' => 'unsupported - RAM', 'dsid' => '9', 'dscount' => '4', 'sup' => 'false']];
		};

		$stats = topx_overview_stats();

		expect($stats['supported_types'])->toBe(2);
		expect($stats['unsupported_types'])->toBe(1);
		expect($stats['total_types'])->toBe(3);
		expect($stats['total_sources'])->toBe(9);

		unset($GLOBALS['__test_db_fetch_assoc']);
	});
});

describe('topx_short_label', function (): void {
	it('uses the interface segment before a trailing rrd field name', function (): void {
		expect(topx_short_label('Device - Traffic - 188.120.193.222 - wan1 - traffic_out'))->toBe('wan1');
	});

	it('uses the final segment when it is not an rrd field name', function (): void {
		expect(topx_short_label('Device - Traffic - 192.168.11.1 - bridge'))->toBe('bridge');
	});

	it('keeps interface names that contain uppercase', function (): void {
		expect(topx_short_label('Device - slo12_TCM'))->toBe('slo12_TCM');
	});

	it('returns the whole name when there is nothing to split', function (): void {
		expect(topx_short_label('single'))->toBe('single');
	});

	it('returns the input when it has no usable segments', function (): void {
		expect(topx_short_label(''))->toBe('');
		expect(topx_short_label('  -   - '))->toBe('  -   - ');
	});
});

describe('topx_compute_ranking', function (): void {
	it('ranks an unsupported source directly from the stats table', function (): void {
		$captured = [];

		$GLOBALS['__test_db_fetch_cell_prepared'] = function ($sql, $p) {
			return 5;
		};

		$GLOBALS['__test_db_fetch_assoc_prepared'] = function ($sql, $p) use (&$captured) {
			$captured[] = ['sql' => $sql, 'params' => $p];

			return [['ldid' => 1, 'name' => 'eth0', 'xvalue' => 10, 'xpeak' => 20]];
		};

		$out = topx_compute_ranking(7, false, 'data_source_stats_hourly', 'average', 'DESC', 5, 'xvalue');

		expect($out['rows'])->toHaveCount(1);
		expect($out['avg'])->toBe(5.0);
		expect($captured[0]['params'])->toBe([7]);
		expect($captured[0]['sql'])->toContain('LIMIT 5');

		unset($GLOBALS['__test_db_fetch_cell_prepared'], $GLOBALS['__test_db_fetch_assoc_prepared']);
	});

	it('resolves a supported definition by its own id, not the template id', function (): void {
		$fetched_source_id = null;

		$GLOBALS['__test_db_fetch_row_prepared'] = function ($sql, $p) use (&$fetched_source_id) {
			if (strpos($sql, 'FROM plugin_topx_source WHERE id') !== false) {
				$fetched_source_id = $p[0];

				return ['id' => 8, 'hash' => 'abc', 'operation' => 'cpu=cpu', 'final_unit' => '%'];
			}

			return [];
		};

		$GLOBALS['__test_db_fetch_cell_prepared'] = function ($sql, $p) {
			// Hash -> data template id resolution, then the AVG() query.
			return strpos($sql, 'FROM data_template WHERE hash') !== false ? 12 : 3;
		};

		$GLOBALS['__test_db_fetch_assoc_prepared'] = function ($sql, $p) {
			return [['ldid' => 1, 'name' => 'cpu', 'xvalue' => 10, 'xpeak' => 20]];
		};

		// ds_key -8 => plugin_topx_source id 8 (a specific definition).
		$out = topx_compute_ranking(-8, true, 'data_source_stats_hourly', 'average', 'DESC', 5, 'xvalue');

		expect($fetched_source_id)->toBe(8);
		expect($out['source']['operation'])->toBe('cpu=cpu');
		expect($out['rows'])->toHaveCount(1);

		unset($GLOBALS['__test_db_fetch_row_prepared'], $GLOBALS['__test_db_fetch_cell_prepared'], $GLOBALS['__test_db_fetch_assoc_prepared']);
	});

	it('applies a trailing divisor from the metric expression to the value queries', function (): void {
		$captured = [];

		$GLOBALS['__test_db_fetch_row_prepared'] = function ($sql, $p) {
			if (strpos($sql, 'FROM plugin_topx_source WHERE id') !== false) {
				return ['id' => 8, 'hash' => 'abc', 'operation' => 'uptime=uptime/8640000'];
			}

			return [];
		};

		$GLOBALS['__test_db_fetch_cell_prepared'] = function ($sql, $p) use (&$captured) {
			if (strpos($sql, 'FROM data_template WHERE hash') !== false) {
				return 12;
			}

			$captured['avg'] = ['sql' => $sql, 'params' => $p];

			return 3;
		};

		$GLOBALS['__test_db_fetch_assoc_prepared'] = function ($sql, $p) use (&$captured) {
			$captured['rows'] = ['sql' => $sql, 'params' => $p];

			return [['ldid' => 1, 'name' => 'uptime', 'xvalue' => 24, 'xpeak' => 25]];
		};

		$out = topx_compute_ranking(-8, true, 'data_source_stats_hourly', 'average', 'DESC', 5, 'xvalue');

		// The divisor (8640000) is bound into the AVG and the per-row value/peak.
		expect($captured['avg']['sql'])->toContain('AVG(average) / ?');
		expect($captured['avg']['params'][0])->toBe(8640000.0);
		expect($captured['rows']['sql'])->toContain('t2.average / ?');
		expect($captured['rows']['sql'])->toContain('t2.peak / ?');
		expect($captured['rows']['params'])->toBe([8640000.0, 8640000.0, 12]);
		expect($out['rows'])->toHaveCount(1);

		unset($GLOBALS['__test_db_fetch_row_prepared'], $GLOBALS['__test_db_fetch_cell_prepared'], $GLOBALS['__test_db_fetch_assoc_prepared']);
	});
});
