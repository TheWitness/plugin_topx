<?php

require_once __DIR__ . '/../bootstrap-unit.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';

describe('request value whitelisting', function (): void {
	it('maps known ages to their dsstats table', function (): void {
		expect(topx_age_table('hour'))->toBe('data_source_stats_hourly');
		expect(topx_age_table('day'))->toBe('data_source_stats_daily');
		expect(topx_age_table('week'))->toBe('data_source_stats_weekly');
		expect(topx_age_table('month'))->toBe('data_source_stats_monthly');
		expect(topx_age_table('year'))->toBe('data_source_stats_yearly');
	});

	it('falls back to the hourly table for an unknown age', function (): void {
		expect(topx_age_table("'; DROP TABLE data_local; --"))->toBe('data_source_stats_hourly');
	});

	it('only ever returns ASC or DESC for the sort direction', function (): void {
		expect(topx_sort_direction('asc'))->toBe('ASC');
		expect(topx_sort_direction('desc'))->toBe('DESC');
		expect(topx_sort_direction('peak DESC; DROP TABLE'))->toBe('DESC');
	});

	it('only ever returns a known statistic column', function (): void {
		expect(topx_order_column('peak'))->toBe('peak');
		expect(topx_order_column('average'))->toBe('average');
		expect(topx_order_column('xvalue); DROP'))->toBe('average');
	});

	it('clamps the record limit to a supported value', function (): void {
		expect(topx_record_limit('10'))->toBe(10);
		expect(topx_record_limit(0))->toBe(0);
		expect(topx_record_limit(99))->toBe(5);
		expect(topx_record_limit('5 UNION SELECT'))->toBe(5);
	});
});
