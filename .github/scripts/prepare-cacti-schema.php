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

function topx_prepare_cacti_schema(string $path) : string {
	if (!is_file($path) || !is_readable($path)) {
		throw new RuntimeException('Cannot read Cacti schema: ' . $path);
	}

	$sql = @file_get_contents($path);

	if ($sql === false || trim($sql) === '') {
		throw new RuntimeException('Cannot read a non-empty Cacti schema: ' . $path);
	}

	if (preg_match('/^\h*DELIMITER\b/im', $sql) !== 1) {
		return $sql;
	}

	// Only Cacti/cacti@72f77e3480105b2185f567c536b301b274c34a81 is known
	// to have a stray directive. Do not reinterpret a future routine's SQL.
	if (hash('sha256', $sql) !== '2ddde392541a74b08cad5ad95023c0b1a2c110b275e98bc905550ef98bb1606b') {
		throw new RuntimeException('Unexpected DELIMITER in Cacti schema; review the upstream SQL before updating the workaround.');
	}

	return str_replace("\nDELIMITER //\n", "\n", $sql);
}
