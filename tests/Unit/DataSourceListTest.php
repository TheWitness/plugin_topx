<?php

require_once __DIR__ . '/../bootstrap-unit.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';

describe('topx_build_ds_list', function (): void {
	it('keys supported definitions by the negative definition id and carries the template id', function (): void {
		$list = topx_build_ds_list(
			[['dsname' => 'CPU', 'dsid' => '7', 'tplid' => '12', 'dscount' => '3', 'sup' => 'true']],
			[]
		);

		expect($list)->toHaveKey(-7);
		expect($list[-7]['name'])->toBe('CPU');
		expect($list[-7]['supported'])->toBe('true');
		expect($list[-7]['template_id'])->toBe(12);
	});

	it('gives a template with several definitions one entry per definition', function (): void {
		$list = topx_build_ds_list(
			[
				['dsname' => 'Traffic In',  'dsid' => '7', 'tplid' => '12', 'dscount' => '3', 'sup' => 'true'],
				['dsname' => 'Traffic Out', 'dsid' => '8', 'tplid' => '12', 'dscount' => '3', 'sup' => 'true'],
			],
			[]
		);

		expect($list)->toHaveKey(-7);
		expect($list)->toHaveKey(-8);
		expect($list[-7]['template_id'])->toBe(12);
		expect($list[-8]['template_id'])->toBe(12);
		expect(count($list))->toBe(2);
	});

	it('keys unsupported sources by the positive data template id', function (): void {
		$list = topx_build_ds_list(
			[],
			[['dsname' => 'unsupported - RAM', 'dsid' => '4', 'dscount' => '1', 'sup' => 'false']]
		);

		expect($list)->toHaveKey(4);
		expect($list[4]['supported'])->toBe('false');
		expect($list[4]['template_id'])->toBe(4);
	});

	it('keeps supported and unsupported entries from colliding', function (): void {
		$list = topx_build_ds_list(
			[['dsname' => 'CPU', 'dsid' => '5', 'tplid' => '5', 'dscount' => '2', 'sup' => 'true']],
			[['dsname' => 'unsupported - CPU', 'dsid' => '6', 'dscount' => '2', 'sup' => 'false']]
		);

		expect($list)->toHaveKey(-5);
		expect($list)->toHaveKey(6);
		expect(count($list))->toBe(2);
	});
});

describe('topx_average_rows', function (): void {
	it('returns the mean of the xvalue column', function (): void {
		expect(topx_average_rows([['xvalue' => 2], ['xvalue' => 4]]))->toBe(3.0);
	});

	it('returns 0 for an empty set', function (): void {
		expect(topx_average_rows([]))->toBe(0.0);
	});
});

describe('topx_operation_divisor', function (): void {
	it('returns 1 when no divisor is present', function (): void {
		expect(topx_operation_divisor('cpu=cpu'))->toBe(1.0);
		expect(topx_operation_divisor('traffic_in+traffic_out'))->toBe(1.0);
		expect(topx_operation_divisor('hdd_total%hdd_used'))->toBe(1.0);
	});

	it('parses a trailing divisor from the field/N form', function (): void {
		expect(topx_operation_divisor('uptime/100'))->toBe(100.0);
	});

	it('parses a trailing divisor from the field=field/N form', function (): void {
		expect(topx_operation_divisor('uptime=uptime/8640000'))->toBe(8640000.0);
	});

	it('parses a decimal divisor', function (): void {
		expect(topx_operation_divisor('load=load/1.5'))->toBe(1.5);
	});

	it('never returns zero', function (): void {
		expect(topx_operation_divisor('x=x/0'))->toBe(1.0);
	});
});
