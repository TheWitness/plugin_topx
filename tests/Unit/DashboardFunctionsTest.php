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
});
