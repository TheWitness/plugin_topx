<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2015-2026 Petr Macek                                      |
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
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
 | This code is designed, written, and maintained by the Cacti Group. See  |
 | about.php and/or the AUTHORS file for specific developer information.   |
 +-------------------------------------------------------------------------+
 | https://github.com/xmacan/plugin_topx                                   |
 | http://www.cacti.net/                                                   |
 +-------------------------------------------------------------------------+
*/

/**
 * Returns the Content-Security-Policy nonce attribute for inline <script>
 * tags, safely across Cacti versions. Newer Cacti releases enforce a CSP that
 * requires a per-request nonce on parser-inserted scripts; older releases lack
 * the CactiSecureHeaders class, so this returns an empty string there.
 *
 * @return string The nonce attribute when supported, otherwise an empty string.
 */
function plugin_topx_csp_nonce(): string {
	if (class_exists('CactiSecureHeaders')) {
		return CactiSecureHeaders::getNonceAttribute();
	}

	return '';
}

/**
 * Returns the whitelisted dsstats rollup table name for a requested age
 * bucket. Because a table name can never be bound as a prepared-statement
 * parameter, this allowlist is the only sanctioned way to turn untrusted
 * request input into a table identifier used in a query.
 *
 * @param string $age The requested age bucket (hour/day/week/month/year).
 *
 * @return string The corresponding data_source_stats_* table name, falling
 *                back to the hourly table for any unrecognised input.
 */
function topx_age_table(string $age): string {
	$map = [
		'hour'  => 'data_source_stats_hourly',
		'day'   => 'data_source_stats_daily',
		'week'  => 'data_source_stats_weekly',
		'month' => 'data_source_stats_monthly',
		'year'  => 'data_source_stats_yearly',
	];

	return $map[$age] ?? $map['hour'];
}

/**
 * Returns a whitelisted SQL sort direction for the requested order. Used so
 * the request-controlled sort value can never be concatenated into SQL
 * verbatim.
 *
 * @param string $sort The requested sort token (asc or desc).
 *
 * @return string 'ASC' for 'asc', otherwise 'DESC'.
 */
function topx_sort_direction(string $sort): string {
	return $sort === 'asc' ? 'ASC' : 'DESC';
}

/**
 * Returns the whitelisted statistics column to order by. Used so the
 * request-controlled "what" value can never be concatenated into SQL
 * verbatim.
 *
 * @param string $what The requested statistic token (average or peak).
 *
 * @return string 'peak' for 'peak', otherwise 'average'.
 */
function topx_order_column(string $what): string {
	return $what === 'peak' ? 'peak' : 'average';
}

/**
 * Clamps the requested record limit to one of the supported values so it is
 * safe to interpolate directly into a LIMIT clause.
 *
 * @param int|string $topx The requested record count.
 *
 * @return int One of the supported counts (0, 5, 10, 20, 50); 5 otherwise.
 */
function topx_record_limit($topx): int {
	$allowed = [0, 5, 10, 20, 50];
	$topx    = (int) $topx;

	return in_array($topx, $allowed, true) ? $topx : 5;
}

/**
 * Formats a raw value into a human readable string using SI (decimal) or
 * binary scaling, picking an appropriate unit suffix. Used for the
 * "unsupported" data sources that have no explicit unit definition.
 *
 * @param float|int|null $bytes   The raw value to scale.
 * @param bool           $decimal When true scale by 1000, otherwise by 1024.
 *
 * @return string The scaled value rounded to two decimals with a unit suffix,
 *                or '0' for empty input.
 */
function topx_human_readable($bytes, bool $decimal = false): string {
	$factor = $decimal ? 1000 : 1024;

	if ($bytes === 0 || $bytes === null) {
		return '0';
	} elseif ($bytes < 1) {
		$sizes = [-1 => 'm', -2 => 'µ', -3 => 'n', -4 => 'p'];
	} else {
		$sizes = [0 => '', 1 => 'K', 2 => 'M', 3 => 'G', 4 => 'T', 5 => 'P'];
	}

	$i = (int) floor(log(abs($bytes)) / log($factor));
	$d = pow($factor, $i);

	if (!array_key_exists($i, $sizes)) {
		cacti_log('TOPX WARNING: Bytes = [' . $bytes . '], Factor = [' . $factor . '], i = [' . $i . '] d = [' . $d . ']', false, 'TOPX');

		$size = '<unknown>';
		$i    = 1;
	} else {
		$size = $sizes[$i];
	}

	return round(empty($d) ? 0 : ($bytes / pow($factor, $i)), 2) . ' ' . $size;
}

/**
 * Applies a data source's "final operation" to a computed value, producing
 * the display string shown in the TopX table. 'strip' simply rounds to the
 * configured precision and appends the unit; '/' walks a slash-delimited set
 * of divisors/suffixes (e.g. bit/Kbit/Mbit) and picks the largest unit whose
 * scaled value is greater than one.
 *
 * @param float|int|string $value           The computed value to format.
 * @param string           $final_operation The operation token (strip or /).
 * @param string           $final_unit      Unit label, or slash-delimited set.
 * @param string           $final_number    Rounding precision, or slash set.
 *
 * @return string The formatted, unit-suffixed display string.
 */
function topx_final_operation($value, string $final_operation, string $final_unit, string $final_number): string {
	if ($final_operation === 'strip') {
		$value = round((float) $value, (int) $final_number) . ' ' . $final_unit;
	} elseif ($final_operation === '/') {
		$num = array_reverse(explode('/', $final_number), true);
		$suf = array_reverse(explode('/', $final_unit), true);

		for ($f = cacti_count($num) - 1; $f >= 0; $f--) {
			if ((float) $num[$f] == 0) {
				continue;
			}

			$xvalue = $value / $num[$f];

			if ($xvalue > 1) {
				$value = round($xvalue, 2) . ' ' . $suf[$f];

				break;
			}
		}
	}

	return (string) $value;
}

/**
 * Builds the keyed data source list used to populate the filter dropdown from
 * the raw supported/unsupported rows. Supported sources (those matching a
 * plugin_topx_source hash) are keyed by the negative of their data template id
 * so the two sets never collide. Kept free of database access so it can be
 * unit tested directly.
 *
 * @param array $supported   Rows of supported data templates (dsname, dsid,
 *                           dscount) flagged sup='true'.
 * @param array $unsupported Rows of unsupported data templates flagged
 *                           sup='false'.
 *
 * @return array The merged list keyed by signed id, each entry holding key,
 *               name, count and supported flags.
 */
function topx_build_ds_list(array $supported, array $unsupported): array {
	$list = [];

	foreach (array_merge($supported, $unsupported) as $ds) {
		$key = ($ds['sup'] === 'true') ? -1 * (int) $ds['dsid'] : (int) $ds['dsid'];

		$list[$key] = [
			'key'       => $key,
			'name'      => $ds['dsname'],
			'count'     => $ds['dscount'],
			'supported' => $ds['sup'],
		];
	}

	return $list;
}

/**
 * Fetches the supported and unsupported data templates from Cacti and returns
 * the merged, keyed list used by the TopX filter. Thin database wrapper around
 * topx_build_ds_list(); contains no request-controlled SQL.
 *
 * @return array The merged data source list keyed by signed data template id.
 */
function topx_get_ds_list(): array {
	$supported = db_fetch_assoc("SELECT DISTINCT(t3.dt_name) AS dsname, t1.id AS dsid, COUNT(t1.id) AS dscount, 'true' AS sup
		FROM data_template AS t1
		INNER JOIN data_template_data AS t2 ON t1.id = t2.data_template_id
		INNER JOIN plugin_topx_source AS t3 ON t1.hash = t3.hash
		GROUP BY data_template_id
		ORDER BY dsname");

	$unsupported = db_fetch_assoc("SELECT DISTINCT(CONCAT('unsupported - ', t1.name)) AS dsname, t1.id AS dsid, COUNT(t1.id) AS dscount, 'false' AS sup
		FROM data_template AS t1
		INNER JOIN data_template_data AS t2 ON t1.id = t2.data_template_id
		GROUP BY data_template_id
		ORDER BY dsname");

	return topx_build_ds_list($supported, $unsupported);
}

/**
 * Returns the arithmetic mean of the 'xvalue' column across a set of rows.
 * Kept free of database access so it can be unit tested directly.
 *
 * @param array $rows Rows each containing a numeric 'xvalue' entry.
 *
 * @return float The mean of all xvalue entries, or 0.0 when there are none.
 */
function topx_average_rows(array $rows): float {
	$sum   = 0;
	$count = cacti_count($rows);

	foreach ($rows as $row) {
		$sum += $row['xvalue'];
	}

	return $count > 0 ? $sum / $count : 0.0;
}

/**
 * Computes the "average all" figure for a combined two-metric data source by
 * averaging, per local data source, the ratio of the where-metric value to the
 * base-metric value. This preserves the original plugin's average calculation
 * for the errors+discards and traffic data sources.
 *
 * @param string $table     Whitelisted dsstats table name (see topx_age_table).
 * @param int    $id        The data template id to aggregate.
 * @param string $base_rrd  The rrd_name used in the correlated sub-select.
 * @param string $where_rrd The rrd_name the outer query filters on.
 *
 * @return float The averaged ratio across the matching data sources.
 */
function topx_combined_sum_average(string $table, int $id, string $base_rrd, string $where_rrd): float {
	$rows = db_fetch_assoc_prepared("SELECT t1.local_data_id AS ldid,
		average / (SELECT average FROM $table WHERE local_data_id = ldid AND rrd_name = ?) AS xvalue
		FROM data_template_data AS t1
		LEFT JOIN $table AS t2 ON t1.local_data_id = t2.local_data_id
		WHERE t1.data_template_id = ? AND rrd_name = ?",
		array($base_rrd, $id, $where_rrd));

	return topx_average_rows($rows);
}

/**
 * Fetches the per-data-source rows for a combined two-metric data source,
 * summing the where-metric and base-metric values (optionally scaled, e.g. a
 * bytes-to-bits multiplier of 8) for both the average and the peak statistic.
 *
 * @param string $table        Whitelisted dsstats table name.
 * @param int    $id           The data template id to aggregate.
 * @param string $base_rrd     The rrd_name used in the correlated sub-select.
 * @param string $where_rrd    The rrd_name the outer query filters on.
 * @param int    $mult         A scaling multiplier applied to both metrics.
 * @param string $order_col    Whitelisted order column (xvalue or xpeak).
 * @param string $dir          Whitelisted sort direction (ASC or DESC).
 * @param string $limit_clause Pre-built, integer-only LIMIT clause (may be '').
 *
 * @return array The result rows (name, ldid, xvalue, xpeak).
 */
function topx_combined_result(string $table, int $id, string $base_rrd, string $where_rrd, int $mult, string $order_col, string $dir, string $limit_clause): array {
	return db_fetch_assoc_prepared("SELECT name_cache AS name, t2.local_data_id AS ldid,
		$mult * average + $mult * (SELECT average FROM $table WHERE local_data_id = ldid AND rrd_name = ?) AS xvalue,
		$mult * peak + $mult * (SELECT peak FROM $table WHERE local_data_id = ldid AND rrd_name = ?) AS xpeak
		FROM data_template_data AS t1
		LEFT JOIN $table AS t2 ON t1.local_data_id = t2.local_data_id
		WHERE t1.data_template_id = ? AND rrd_name = ?
		ORDER BY $order_col $dir$limit_clause",
		array($base_rrd, $base_rrd, $id, $where_rrd));
}
