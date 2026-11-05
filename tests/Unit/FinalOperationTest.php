<?php

require_once __DIR__ . '/../bootstrap-unit.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';

describe('topx_final_operation', function (): void {
	it('rounds and appends the unit for the strip operation', function (): void {
		expect(topx_final_operation(12.345, 'strip', '%', '1'))->toBe('12.3 %');
	});

	it('rounds to the configured precision', function (): void {
		expect(topx_final_operation(2.5, 'strip', 'load', '0'))->toBe('3 load');
	});

	it('scales to the largest divider whose value exceeds one', function (): void {
		expect(topx_final_operation(5000, '/', 'bit/Kbit/Mbit', '1/1000/1000000'))->toBe('5 Kbit');
	});

	it('keeps the base unit when no larger divider applies', function (): void {
		expect(topx_final_operation(42, '/', 'bit/Kbit/Mbit', '1/1000/1000000'))->toBe('42 bit');
	});

	it('returns the value as a string for an unknown operation', function (): void {
		expect(topx_final_operation(7, '', '', ''))->toBe('7');
	});

	it('never divides by a zero divider', function (): void {
		expect(topx_final_operation(10, '/', 'a/b', '0/5'))->toBeString();
	});
});
