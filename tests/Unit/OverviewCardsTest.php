<?php

require_once __DIR__ . '/../bootstrap-unit.php';
require_once dirname(__DIR__, 2) . '/includes/arrays.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__, 2) . '/includes/sources.php';
require_once dirname(__DIR__, 2) . '/includes/dashboard.php';

describe('topx_overview composite card keys', function (): void {
	it('builds and parses a composite data-source card key', function (): void {
		expect(topx_overview_composite_key(-5, 'chart'))->toBe('ds-5:chart');
		expect(topx_overview_parse_composite_key('ds-5:chart'))->toBe(['ds_key' => -5, 'card' => 'chart']);
	});

	it('returns null for a plain aggregate card key', function (): void {
		expect(topx_overview_parse_composite_key('summary'))->toBeNull();
		expect(topx_overview_parse_composite_key('uptime'))->toBeNull();
	});
});

describe('topx_overview_datasource_cards', function (): void {
	afterEach(function (): void {
		unset($GLOBALS['__test_db_fetch_assoc']);
	});

	it('lists each supported source card and omits unsupported sources', function (): void {
		$GLOBALS['__test_db_fetch_assoc'] = function ($sql) {
			if (strpos($sql, 'plugin_topx_source') !== false) {
				return [['dsname' => 'Device - Uptime', 'dsid' => '3', 'tplid' => '12', 'sorting' => 'asc', 'dscount' => '2', 'sup' => 'true']];
			}

			return [['dsname' => 'unsupported - X', 'dsid' => '9', 'dscount' => '1', 'sup' => 'false']];
		};

		$cards = topx_overview_datasource_cards();

		// Supported source keyed by the negative of its definition id (-3), with
		// one composite per drilldown card type and a "<name> : <card>" title.
		expect($cards)->toHaveKey('ds-3:rankings');
		expect($cards['ds-3:rankings']['title'])->toBe('Device - Uptime : Rankings');
		expect($cards['ds-3:chart']['title'])->toBe('Device - Uptime : Ranking Chart');
		expect($cards['ds-3:chart']['ds_key'])->toBe(-3);
		expect($cards['ds-3:chart']['card'])->toBe('chart');

		// The unsupported template (positive key 9) contributes no cards.
		expect($cards)->not->toHaveKey('ds9:rankings');
		expect(cacti_count($cards))->toBe(cacti_count(topx_drilldown_available_cards()));
	});
});

describe('topx_overview_card_catalog and available cards', function (): void {
	afterEach(function (): void {
		unset($GLOBALS['__test_db_fetch_assoc']);
	});

	it('keeps the aggregate cards first, then appends composite cards', function (): void {
		$GLOBALS['__test_db_fetch_assoc'] = function ($sql) {
			if (strpos($sql, 'plugin_topx_source') !== false) {
				return [['dsname' => 'CPU', 'dsid' => '7', 'tplid' => '12', 'sorting' => 'desc', 'dscount' => '2', 'sup' => 'true']];
			}

			return [];
		};

		$keys = topx_overview_available_cards();

		expect(array_slice($keys, 0, 4))->toBe(['summary', 'distribution', 'uptime', 'types']);
		expect($keys)->toContain('ds-7:tiles');
		expect($keys)->toContain('ds-7:rankings');
	});
});

describe('topx_overview_records', function (): void {
	beforeEach(function (): void {
		$_SESSION = [];
		$GLOBALS['__test_request']         = [];
		$GLOBALS['__test_nfilter_request'] = [];
	});

	it('defaults to Top 5 when nothing is stored', function (): void {
		expect(topx_overview_records())->toBe(5);
	});

	it('persists a validated request value', function (): void {
		$GLOBALS['__test_request']         = ['records' => '20'];
		$GLOBALS['__test_nfilter_request'] = ['records' => '20'];

		expect(topx_overview_records())->toBe(20);
		expect($_SESSION['sess_topx_overview_records'])->toBe('20');
	});

	it('ignores an invalid request value', function (): void {
		$GLOBALS['__test_request']         = ['records' => '999'];
		$GLOBALS['__test_nfilter_request'] = ['records' => '999'];

		expect(topx_overview_records())->toBe(5);
	});
});

describe('topx_overview_card_filter', function (): void {
	beforeEach(function (): void {
		$GLOBALS['__test_request']         = [];
		$GLOBALS['__test_nfilter_request'] = [];
	});

	it('takes the record limit from the overview selector and the sort from the source', function (): void {
		$_SESSION = ['sess_topx_overview_records' => '10'];

		expect(topx_overview_card_filter(['sorting' => 'asc']))->toBe(['records' => 10, 'sort' => 'asc', 'what' => 'average']);
	});

	it('defaults the sort direction to desc', function (): void {
		$_SESSION = ['sess_topx_overview_records' => '5'];

		expect(topx_overview_card_filter([])['sort'])->toBe('desc');
	});
});

describe('topx_dashboard_toolbar_records', function (): void {
	it('prints a TopX record-limit selector sharing the drilldown records id', function (): void {
		ob_start();
		topx_dashboard_toolbar_records(10);
		$html = (string) ob_get_clean();

		expect($html)->toContain('topx_dashboard_records');
		expect($html)->toContain('TopX');
		expect($html)->toContain('Top 10');
	});
});

describe('topx_overview_datasource_card_body', function (): void {
	afterEach(function (): void {
		unset(
			$GLOBALS['__test_db_fetch_assoc'],
			$GLOBALS['__test_db_fetch_row_prepared'],
			$GLOBALS['__test_db_fetch_cell_prepared'],
			$GLOBALS['__test_db_fetch_assoc_prepared']
		);
	});

	it('renders a supported source drilldown card via the composite dispatch', function (): void {
		$_SESSION = [];
		$GLOBALS['__test_request']         = [];
		$GLOBALS['__test_nfilter_request'] = [];

		$GLOBALS['__test_db_fetch_assoc'] = function ($sql) {
			if (strpos($sql, 'plugin_topx_source') !== false) {
				return [['dsname' => 'CPU', 'dsid' => '7', 'tplid' => '12', 'sorting' => 'desc', 'dscount' => '2', 'sup' => 'true']];
			}

			return [];
		};

		$GLOBALS['__test_db_fetch_row_prepared'] = function ($sql, $p) {
			if (strpos($sql, 'plugin_topx_source') !== false) {
				return [
					'id'              => 7,
					'hash'            => 'abc',
					'operation'       => 'cpu=cpu',
					'unit'            => 'Utilization',
					'final_operation' => 'strip',
					'final_unit'      => '%',
					'final_number'    => '1',
					'final_decimals'  => '',
					'system'          => 'decimal',
					'sorting'         => 'desc',
				];
			}

			return false;
		};

		$GLOBALS['__test_db_fetch_cell_prepared']  = function ($sql, $p) { return 20; };
		$GLOBALS['__test_db_fetch_assoc_prepared'] = function ($sql, $p) {
			return [['ldid' => 1, 'name' => 'CPU0', 'xvalue' => 30, 'xpeak' => 40]];
		};

		// Routed through topx_overview_card_body so the composite dispatch runs.
		$body = topx_overview_card_body('ds-7:tiles');

		expect($body)->toContain('topxFleetTiles');
	});

	it('shows an empty state when the source is no longer available', function (): void {
		$GLOBALS['__test_db_fetch_assoc'] = function ($sql) {
			return [];
		};

		expect(topx_overview_datasource_card_body(-7, 'tiles'))->toContain('no longer available');
	});

	it('rejects an unknown drilldown card for a valid source', function (): void {
		$GLOBALS['__test_db_fetch_assoc'] = function ($sql) {
			if (strpos($sql, 'plugin_topx_source') !== false) {
				return [['dsname' => 'CPU', 'dsid' => '7', 'tplid' => '12', 'sorting' => 'desc', 'dscount' => '2', 'sup' => 'true']];
			}

			return [];
		};

		expect(topx_overview_datasource_card_body(-7, 'bogus'))->toContain('Unknown card');
	});
});

describe('topx_overview_render_card', function (): void {
	afterEach(function (): void {
		unset(
			$GLOBALS['__test_db_fetch_assoc'],
			$GLOBALS['__test_db_fetch_row_prepared'],
			$GLOBALS['__test_db_fetch_cell_prepared'],
			$GLOBALS['__test_db_fetch_assoc_prepared']
		);
	});

	it('wraps a composite data-source card in card chrome with its full title', function (): void {
		$_SESSION = [];
		$GLOBALS['__test_request']         = [];
		$GLOBALS['__test_nfilter_request'] = [];

		$GLOBALS['__test_db_fetch_assoc'] = function ($sql) {
			if (strpos($sql, 'plugin_topx_source') !== false) {
				return [['dsname' => 'CPU', 'dsid' => '7', 'tplid' => '12', 'sorting' => 'desc', 'dscount' => '2', 'sup' => 'true']];
			}

			return [];
		};

		$GLOBALS['__test_db_fetch_row_prepared'] = function ($sql, $p) {
			if (strpos($sql, 'plugin_topx_source') !== false) {
				return [
					'id'              => 7,
					'hash'            => 'abc',
					'operation'       => 'cpu=cpu',
					'unit'            => 'Utilization',
					'final_operation' => 'strip',
					'final_unit'      => '%',
					'final_number'    => '1',
					'final_decimals'  => '',
					'system'          => 'decimal',
					'sorting'         => 'desc',
				];
			}

			return false;
		};

		$GLOBALS['__test_db_fetch_cell_prepared']  = function ($sql, $p) { return 20; };
		$GLOBALS['__test_db_fetch_assoc_prepared'] = function ($sql, $p) {
			return [['ldid' => 1, 'name' => 'CPU0', 'xvalue' => 30, 'xpeak' => 40]];
		};

		$html = topx_overview_render_card('ds-7:tiles');

		expect($html)->toContain('topxDashCard');
		expect($html)->toContain('data-card="ds-7:tiles"');
		expect($html)->toContain('CPU : Summary');
	});

	it('returns an empty string for an unknown card key', function (): void {
		$GLOBALS['__test_db_fetch_assoc'] = function ($sql) {
			return [];
		};

		expect(topx_overview_render_card('does-not-exist'))->toBe('');
	});
});
