<?php

require_once __DIR__ . '/../bootstrap-unit.php';
require_once dirname(__DIR__, 2) . '/includes/dashboard.php';

describe('dashboard card catalogues', function (): void {
	it('lists the overview cards in default order', function (): void {
		expect(topx_overview_available_cards())->toBe(['summary', 'distribution', 'uptime', 'types']);
	});

	it('lists the drilldown cards in default order', function (): void {
		expect(topx_drilldown_available_cards())->toBe(['rankings', 'chart', 'tiles', 'gauges', 'distribution']);
	});
});

describe('topx_dashboard_layout persistence', function (): void {
	beforeEach(function (): void {
		$GLOBALS['__test_user_settings'] = [];
	});

	it('returns the default order when nothing is saved', function (): void {
		$layout = topx_dashboard_layout('overview', topx_overview_available_cards());

		expect($layout['order'])->toBe(topx_overview_available_cards());
		expect($layout['expanded'])->toBe([]);
	});

	it('round-trips a stored order and expanded state', function (): void {
		expect(topx_dashboard_layout_store('overview', ['types', 'summary'], ['types' => true]))->toBeTrue();

		$layout = topx_dashboard_layout('overview', topx_overview_available_cards());

		expect($layout['order'])->toBe(['types', 'summary']);
		expect($layout['expanded'])->toBe(['types' => true]);
	});

	it('drops unknown or duplicate keys from a stored order', function (): void {
		topx_dashboard_layout_store('drill', ['rankings', 'rankings', 'bogus', 'chart'], ['bogus' => true]);

		$layout = topx_dashboard_layout('drill', topx_drilldown_available_cards());

		expect($layout['order'])->toBe(['rankings', 'chart']);
		expect($layout['expanded'])->toBe([]);
	});
});

describe('topx_dashboard_chart', function (): void {
	it('emits a billboard placeholder carrying the labels, values and colour range', function (): void {
		$html = topx_dashboard_chart(['eth0', 'eth1'], [10, 20], 'bits', true, 5.0, ['#111111', '#222222']);

		expect($html)->toContain('topx-bb-chart');
		expect($html)->toContain('data-chart=');
		expect($html)->toContain('barColors');
		expect($html)->toContain('#111111');
		expect($html)->toContain('eth0');
	});

	it('returns an empty-state message when there is nothing to chart', function (): void {
		expect(topx_dashboard_chart([], [], 'bits', true))->toContain('topxDashEmpty');
	});
});

describe('topx_overview_card_body uptime', function (): void {
	it('renders the red-to-green uptime range chart when hosts are found', function (): void {
		$GLOBALS['__test_db_fetch_assoc'] = function ($sql) {
			// 8640000000 timeticks -> 1000 days -> the ">6 months" bucket.
			return [['val' => 8640000000]];
		};

		$body = topx_overview_card_body('uptime');

		expect($body)->toContain('topx-bb-chart');
		expect($body)->toContain('#3c9a5f');

		unset($GLOBALS['__test_db_fetch_assoc']);
	});

	it('shows an empty state when no uptime data sources exist', function (): void {
		$GLOBALS['__test_db_fetch_assoc'] = function ($sql) {
			return [];
		};

		expect(topx_overview_card_body('uptime'))->toContain('topxDashEmpty');

		unset($GLOBALS['__test_db_fetch_assoc']);
	});
});

describe('topx_dashboard_layout_save', function (): void {
	it('rejects a non-POST request', function (): void {
		$GLOBALS['__test_user_settings'] = [];
		$_SERVER['REQUEST_METHOD']       = 'GET';

		$out = json_decode(topx_dashboard_layout_save('overview', topx_overview_available_cards()), true);

		expect($out)->toHaveKey('error');
	});

	it('stores a valid posted layout', function (): void {
		$GLOBALS['__test_user_settings']   = [];
		$_SERVER['REQUEST_METHOD']         = 'POST';
		$layout_json                       = json_encode(['order' => ['summary', 'types'], 'expanded' => ['types' => true]]);
		$GLOBALS['__test_request']         = ['layout' => $layout_json];
		$GLOBALS['__test_nfilter_request'] = ['layout' => $layout_json];

		$out = json_decode(topx_dashboard_layout_save('overview', topx_overview_available_cards()), true);

		expect($out)->toHaveKey('ok');

		$layout = topx_dashboard_layout('overview', topx_overview_available_cards());
		expect($layout['order'])->toBe(['summary', 'types']);

		$_SERVER['REQUEST_METHOD']         = 'GET';
		$GLOBALS['__test_request']         = [];
		$GLOBALS['__test_nfilter_request'] = [];
	});
});
