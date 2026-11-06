<?php
declare(strict_types=1);

/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 |                                                                         |
 | This program is free software; you can redistribute it and/or           |
 | modify it under the terms of the GNU General Public License             |
 | as published by the Free Software Foundation; either version 2          |
 | of the License, or (at your option) any later version.                  |
 |                                                                         |
 | This program is distributed in the hope that it will be useful,         |
 | but WITHOUT ANY WARRANTY; without even the implied warranty of          |
 | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the           |
 | GNU General Public License for more details.                            |
 +-------------------------------------------------------------------------+
*/

use Symfony\Component\Process\Process;

require_once dirname(__DIR__, 2) . '/.github/scripts/prepare-cacti-schema.php';

// Unmodified upstream SQL, including its license, compressed without a timestamp.
// https://github.com/Cacti/cacti/blob/72f77e3480105b2185f567c536b301b274c34a81/cacti.sql
function topx_test_cacti_schema() : string {
	$sql = gzdecode(file_get_contents(__DIR__ . '/../fixtures/cacti-72f77e3.sql.gz'));
	expect($sql)->toBeString()->not->toBe('');

	return $sql;
}

describe('Cacti schema preparation', function () : void {
	beforeEach(function () : void {
		$this->schema = tempnam(sys_get_temp_dir(), 'topx-schema-');
	});

	afterEach(function () : void {
		if (is_file($this->schema)) {
			unlink($this->schema);
		}
	});

	it('removes only the known stray directive and preserves the input file', function () : void {
		$sql = topx_test_cacti_schema();
		file_put_contents($this->schema, $sql);
		// The original directive occupies line 28. Keep every other byte.
		$lines = explode("\n", $sql);
		expect($lines[27])->toBe('DELIMITER //');
		array_splice($lines, 27, 1);
		$expected = implode("\n", $lines);

		expect(topx_prepare_cacti_schema($this->schema))->toBe($expected);
		expect(file_get_contents($this->schema))->toBe($sql);
	});

	it('passes corrected and ordinary SQL through byte for byte', function (string $sql) : void {
		file_put_contents($this->schema, $sql);
		expect(topx_prepare_cacti_schema($this->schema))->toBe($sql);
	})->with([
		'ordinary SQL' => ["CREATE TABLE example (id INT);\nINSERT INTO example VALUES (1);\n"],
		'CRLF' => ["-- DELIMITER in a comment\r\nSELECT 'DELIMITER //';\r\n"],
		'no final newline' => ["SELECT 'http://example.test/';"],
		'corrected upstream' => fn () : string => str_replace("DELIMITER //\n", '', topx_test_cacti_schema()),
	]);

	it('rejects unexpected delimiter use without emitting SQL', function (string $sql) : void {
		file_put_contents($this->schema, $sql);
		expect(fn () => topx_prepare_cacti_schema($this->schema))->toThrow(RuntimeException::class, 'Unexpected DELIMITER');
		expect(file_get_contents($this->schema))->toBe($sql);
	})->with([
		'different delimiter' => ["DELIMITER $$\nCREATE PROCEDURE p() SELECT 1$$\nDELIMITER ;\n"],
		'legitimate slash delimiter' => ["DELIMITER //\nCREATE PROCEDURE p() BEGIN SELECT 1; END//\nDELIMITER ;\n"],
		'lowercase' => ["delimiter //\nSELECT 1//\n"],
		'leading whitespace' => [" \tdElImItEr //\nSELECT 1//\n"],
		'single delimiter with real terminator' => ["DELIMITER //\nSELECT 1//\n"],
		'changed upstream' => fn () : string => topx_test_cacti_schema() . "SELECT 1;\n",
		'truncated upstream' => fn () : string => substr(topx_test_cacti_schema(), 0, -32),
		'duplicate directive' => fn () : string => topx_test_cacti_schema() . "DELIMITER //\n",
	]);

	it('rejects empty input', function (string $sql) : void {
		file_put_contents($this->schema, $sql);
		expect(fn () => topx_prepare_cacti_schema($this->schema))->toThrow(RuntimeException::class, 'non-empty Cacti schema');
	})->with(['empty' => [''], 'whitespace' => [" \r\n\t"]]);

	it('rejects a missing input file', function () : void {
		unlink($this->schema);
		expect(fn () => topx_prepare_cacti_schema($this->schema))->toThrow(RuntimeException::class, 'Cannot read');
	});
});

describe('Cacti database workflow pipeline', function () : void {
	it('propagates preparation and database errors through the actual workflow step', function (string $input, int $database_status, bool $success) : void {
		$root = tempnam(sys_get_temp_dir(), 'topx-import-');
		unlink($root);
		mkdir($root);
		mkdir($root . '/cacti');
		mkdir($root . '/cacti/plugins');
		symlink(dirname(__DIR__, 2), $root . '/cacti/plugins/topx');
		$sql = $input === 'known' ? topx_test_cacti_schema() : "SELECT 1;\n";
		if ($input === 'unexpected') {
			$sql = "DELIMITER //\nSELECT 1//\n";
		}
		if ($input !== 'missing') {
			file_put_contents($root . '/cacti/cacti.sql', $sql);
		}

		try {
			$workflow = file_get_contents(dirname(__DIR__, 2) . '/.github/workflows/plugin-ci-workflow.yml');
			$steps = explode('    - name: ', $workflow);
			$step = array_values(array_filter($steps, static fn (string $step) : bool => str_starts_with($step, "Initialize Cacti Database\n")));
			expect($step)->toHaveCount(1);
			$shell = str_contains($step[0], "      shell: bash\n")
				? ['bash', '--noprofile', '--norc', '-e', '-o', 'pipefail']
				: ['bash', '-e'];
			$body = explode("      run: |\n", $step[0], 2)[1];
			$script = '';
			foreach (explode("\n", $body) as $line) {
				if (str_starts_with($line, '        ')) {
					$script .= substr($line, 8) . "\n";
				} elseif (trim($line) !== '') {
					break;
				}
			}
			$script = str_replace('${{ github.workspace }}', $root, $script);
			// Model the mysql process boundary only; execute the shipped producer
			// and workflow commands with GitHub's explicit bash shell options.
			$mysql = <<<'BASH'
mysql() {
  if [ "${2-}" = '-e' ]; then
    case "${3-}" in
      'INSERT INTO settings'*) printf reached > "$CAPTURE/continued" ;;
    esac
    return 0
  fi
  cat > "$CAPTURE/import.sql"
  return "$DATABASE_STATUS"
}
BASH;
			$process = new Process(array_merge($shell, ['-c', $mysql . "\n" . $script]), null, [
				'CAPTURE' => $root,
				'DATABASE_STATUS' => (string) $database_status,
				'PATH' => dirname(PHP_BINARY) . PATH_SEPARATOR . getenv('PATH'),
			]);
			$process->run();
			expect($process->isSuccessful())->toBe($success);
			expect(is_file($root . '/continued'))->toBe($success);
			$expected = in_array($input, ['missing', 'unexpected'], true) ? '' : str_replace("DELIMITER //\n", '', $sql);
			expect(file_get_contents($root . '/import.sql'))->toBe($expected);
			if (!$success && $database_status === 0) {
				expect($process->getErrorOutput())->toContain('RuntimeException');
			}
		} finally {
			foreach (['cacti/cacti.sql', 'import.sql', 'continued', 'cacti/plugins/topx'] as $path) {
				if (file_exists($root . '/' . $path) || is_link($root . '/' . $path)) {
					unlink($root . '/' . $path);
				}
			}
			rmdir($root . '/cacti/plugins');
			rmdir($root . '/cacti');
			rmdir($root);
		}
	})->with([
		'known malformed schema' => ['known', 0, true],
		'already corrected schema' => ['corrected', 0, true],
		'unknown delimiter' => ['unexpected', 0, false],
		'missing input' => ['missing', 0, false],
		'database failure' => ['known', 1, false],
	]);
});
