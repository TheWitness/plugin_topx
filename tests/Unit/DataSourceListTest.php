<?php

require_once __DIR__ . '/../bootstrap-unit.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';

describe('topx_build_ds_list', function (): void {
	it('keys supported sources by the negative data template id', function (): void {
		$list = topx_build_ds_list(
			[['dsname' => 'CPU', 'dsid' => '7', 'dscount' => '3', 'sup' => 'true']],
			[]
		);

		expect($list)->toHaveKey(-7);
		expect($list[-7]['name'])->toBe('CPU');
		expect($list[-7]['supported'])->toBe('true');
	});

	it('keys unsupported sources by the positive data template id', function (): void {
		$list = topx_build_ds_list(
			[],
			[['dsname' => 'unsupported - RAM', 'dsid' => '4', 'dscount' => '1', 'sup' => 'false']]
		);

		expect($list)->toHaveKey(4);
		expect($list[4]['supported'])->toBe('false');
	});

	it('keeps supported and unsupported entries from colliding', function (): void {
		$list = topx_build_ds_list(
			[['dsname' => 'CPU', 'dsid' => '5', 'dscount' => '2', 'sup' => 'true']],
			[['dsname' => 'unsupported - CPU', 'dsid' => '5', 'dscount' => '2', 'sup' => 'false']]
		);

		expect($list)->toHaveKey(-5);
		expect($list)->toHaveKey(5);
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
