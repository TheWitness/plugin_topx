<?php

require_once __DIR__ . '/../bootstrap-unit.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__, 2) . '/includes/arrays.php';
require_once dirname(__DIR__, 2) . '/setup.php';

describe('topx_filter_arrays', function (): void {
	it('exposes the age, records, sort and what option sets', function (): void {
		$filters = topx_filter_arrays();

		expect($filters)->toHaveKeys(['age', 'records', 'sort', 'what']);
		expect($filters['age'])->toHaveKeys(['hour', 'day', 'week', 'month', 'year']);
		expect($filters['records'])->toHaveKeys(['5', '10', '20', '50', '0']);
		expect($filters['sort'])->toHaveKeys(['asc', 'desc']);
		expect($filters['what'])->toHaveKeys(['average', 'peak']);
	});
});

describe('topx_config_arrays', function (): void {
	it('publishes the filter arrays as a global', function (): void {
		// Keep the upgrade trigger a no-op off relevant pages so this unit test
		// stays focused on the published arrays.
		test_set_current_page('graph_view.php');

		unset($GLOBALS['topx_filter_arrays']);

		topx_config_arrays();

		expect($GLOBALS['topx_filter_arrays'])->toBeArray();
		expect($GLOBALS['topx_filter_arrays'])->toHaveKey('age');
	});
});
