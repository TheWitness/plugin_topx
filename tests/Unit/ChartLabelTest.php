<?php

require_once __DIR__ . '/../bootstrap-unit.php';
require_once dirname(__DIR__, 2) . '/includes/sources.php';

describe('topx_apply_chart_label', function (): void {
	it('returns an empty string for an empty pattern', function (): void {
		expect(topx_apply_chart_label('', ['host_description' => 'R1']))->toBe('');
	});

	it('substitutes a single host token', function (): void {
		expect(topx_apply_chart_label('|host_description|', ['host_description' => 'Router1']))->toBe('Router1');
	});

	it('substitutes tokens case-insensitively', function (): void {
		expect(topx_apply_chart_label('|host_description| - |query_ifName|', [
			'host_description' => 'R1',
			'query_ifName'     => 'eth0',
		]))->toBe('R1 - eth0');
	});

	it('combines literal text with a query token', function (): void {
		expect(topx_apply_chart_label('CPU|query_cpuid|', ['query_cpuid' => '3']))->toBe('CPU3');
	});

	it('degrades cleanly when a trailing token is unresolved', function (): void {
		expect(topx_apply_chart_label('|host_description| - |query_ifName|', ['host_description' => 'R1']))->toBe('R1');
	});

	it('returns an empty string when every token is unresolved', function (): void {
		expect(topx_apply_chart_label('|host_description| - |query_ifName|', []))->toBe('');
	});

	it('drops a dangling separator from an empty leading token', function (): void {
		expect(topx_apply_chart_label('|missing|/|query_part|', ['query_part' => 'x']))->toBe('x');
	});
});
