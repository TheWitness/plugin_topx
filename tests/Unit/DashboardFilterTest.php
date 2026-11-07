<?php

require_once __DIR__ . '/../bootstrap-unit.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__, 2) . '/includes/dashboard.php';

describe('topx_dashboard_drill_filter', function (): void {
	beforeEach(function (): void {
		unset(
			$_SESSION['sess_topx_records'],
			$_SESSION['sess_topx_sort'],
			$_SESSION['sess_topx_what']
		);

		$GLOBALS['__test_request']         = [];
		$GLOBALS['__test_nfilter_request'] = [];
	});

	it('seeds the sort from a decreasing default when the session is empty', function (): void {
		$filter = topx_dashboard_drill_filter('desc');

		expect($filter['sort'])->toBe('desc');
		expect($filter['what'])->toBe('average');
		expect($_SESSION['sess_topx_records'])->toBe(5);
	});

	it('seeds the sort from an increasing default when the session is empty', function (): void {
		$filter = topx_dashboard_drill_filter('asc');

		expect($filter['sort'])->toBe('asc');
	});

	it('lets a validated request value override and persist the sort', function (): void {
		$GLOBALS['__test_request']         = ['sort' => 'asc'];
		$GLOBALS['__test_nfilter_request'] = ['sort' => 'asc'];

		$filter = topx_dashboard_drill_filter('desc');

		expect($filter['sort'])->toBe('asc');
		expect($_SESSION['sess_topx_sort'])->toBe('asc');
	});
});
