<?php

require_once __DIR__ . '/../bootstrap-unit.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';

describe('topx_human_readable', function (): void {
	it('returns 0 for empty input', function (): void {
		expect(topx_human_readable(0))->toBe('0');
		expect(topx_human_readable(null))->toBe('0');
	});

	it('scales using binary factors by default', function (): void {
		expect(topx_human_readable(2048))->toBe('2 K');
	});

	it('scales using decimal factors when requested', function (): void {
		expect(topx_human_readable(2000, true))->toBe('2 K');
	});

	it('handles sub-unit values with fractional suffixes', function (): void {
		expect(topx_human_readable(0.5, true))->toBeString();
	});
});
