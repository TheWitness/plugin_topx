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
 * the raw supported/unsupported rows. Supported sources (each a plugin_topx_source
 * definition) are keyed by the negative of their *definition* id, so a Data
 * Template can carry several independent definitions (e.g. traffic_in and
 * traffic_out ranked separately) and each appears as its own selectable entry.
 * Unsupported templates are keyed by their positive template id, so the two sets
 * never collide. Each entry also carries the underlying template_id used to look
 * up data source instances. Kept free of database access so it can be unit
 * tested directly.
 *
 * @param array $supported   Rows of supported definitions (dsname, dsid [the
 *                           plugin_topx_source id], tplid [the data template id],
 *                           dscount) flagged sup='true'.
 * @param array $unsupported Rows of unsupported data templates flagged
 *                           sup='false'.
 *
 * @return array The merged list keyed by signed id, each entry holding key,
 *               name, count, supported and template_id.
 */
function topx_build_ds_list(array $supported, array $unsupported): array {
	$list = [];

	foreach (array_merge($supported, $unsupported) as $ds) {
		$key = ($ds['sup'] === 'true') ? -1 * (int) $ds['dsid'] : (int) $ds['dsid'];

		$list[$key] = [
			'key'         => $key,
			'name'        => $ds['dsname'],
			'count'       => $ds['dscount'],
			'supported'   => $ds['sup'],
			'template_id' => isset($ds['tplid']) ? (int) $ds['tplid'] : (int) $ds['dsid'],
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
	// Only count data source *instances* that actually exist on a device
	// (local_data_id > 0); the data_template_data row with local_data_id = 0 is
	// the template master itself, so a template that is defined but not used on
	// any device is excluded rather than shown with a misleading count.
	$supported = db_fetch_assoc("SELECT t3.dt_name AS dsname, t3.id AS dsid, t1.id AS tplid, COUNT(DISTINCT dtd.local_data_id) AS dscount, 'true' AS sup
		FROM data_template AS t1
		INNER JOIN data_template_data AS dtd ON t1.id = dtd.data_template_id AND dtd.local_data_id > 0
		INNER JOIN plugin_topx_source AS t3 ON t1.hash = t3.hash
		GROUP BY t3.id, t3.dt_name, t1.id
		ORDER BY dsname");

	$unsupported = db_fetch_assoc("SELECT CONCAT('unsupported - ', t1.name) AS dsname, t1.id AS dsid, COUNT(DISTINCT dtd.local_data_id) AS dscount, 'false' AS sup
		FROM data_template AS t1
		INNER JOIN data_template_data AS dtd ON t1.id = dtd.data_template_id AND dtd.local_data_id > 0
		GROUP BY t1.id, t1.name
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

/**
 * Maps a timespan length (in seconds) to the whitelisted dsstats rollup table
 * whose resolution best matches it. Lets the Cacti timespan selector drive which
 * dsstats bucket the dashboard reads, replacing the legacy fixed "age" filter.
 * Kept free of database access so it can be unit tested directly.
 *
 * @param int $seconds The selected timespan length in seconds.
 *
 * @return string The matching data_source_stats_* table name.
 */
function topx_timespan_bucket(int $seconds): string {
	if ($seconds <= 7200) {
		return 'data_source_stats_hourly';
	} elseif ($seconds <= 172800) {
		return 'data_source_stats_daily';
	} elseif ($seconds <= 1209600) {
		return 'data_source_stats_weekly';
	} elseif ($seconds <= 5270400) {
		return 'data_source_stats_monthly';
	}

	return 'data_source_stats_yearly';
}

/**
 * Buckets a list of per-host uptime values (in days) into the five fixed ranges
 * shown on the Uptime by Host card. Kept free of database access so it can be
 * unit tested directly.
 *
 * @param array $days_list A list of uptime values expressed in days.
 *
 * @return array Counts keyed by bucket: lt_day, day_week, week_month,
 *               month_six and gt_six.
 */
function topx_uptime_bucket_days(array $days_list): array {
	$buckets = [
		'lt_day'     => 0,
		'day_week'   => 0,
		'week_month' => 0,
		'month_six'  => 0,
		'gt_six'     => 0,
	];

	foreach ($days_list as $value) {
		$days = (float) $value;

		if ($days < 1) {
			$buckets['lt_day']++;
		} elseif ($days < 7) {
			$buckets['day_week']++;
		} elseif ($days < 30) {
			$buckets['week_month']++;
		} elseif ($days < 182) {
			$buckets['month_six']++;
		} else {
			$buckets['gt_six']++;
		}
	}

	return $buckets;
}

/**
 * Fetches the latest uptime reading per host from the hourly dsstats table and
 * returns each as a number of days. SNMP sysUpTime style counters are reported
 * in hundredths of a second (timeticks), so the raw value is divided by 100 and
 * then by 86400. Thin database wrapper around topx_uptime_bucket_days().
 *
 * @return array A list of per-host uptime values in days.
 */
function topx_uptime_host_days(): array {
	$rows = db_fetch_assoc("SELECT MAX(s.average) AS val
		FROM data_source_stats_hourly AS s
		INNER JOIN data_local AS dl ON s.local_data_id = dl.id
		INNER JOIN host AS h ON dl.host_id = h.id
		WHERE s.rrd_name LIKE '%uptime%' AND s.average > 0
		GROUP BY h.id");

	$days = [];

	foreach ($rows as $row) {
		$days[] = ((float) $row['val'] / 100) / 86400;
	}

	return $days;
}

/**
 * Computes high-level counts across the TopX data source catalogue for the
 * overview dashboard tiles and distribution card.
 *
 * @return array Counts keyed by supported_types, unsupported_types,
 *               total_types and total_sources.
 */
function topx_overview_stats(): array {
	$list = topx_get_ds_list();

	$supported   = 0;
	$unsupported = 0;
	$sources     = 0;

	// A Data Template can carry several supported definitions, each its own list
	// entry; count the template's data source instances only once so the total
	// is not inflated by multi-definition templates.
	$counted = [];

	foreach ($list as $entry) {
		if ($entry['supported'] === 'true') {
			$supported++;
		} else {
			$unsupported++;
		}

		$tpl = (int) ($entry['template_id'] ?? 0);

		if (!isset($counted[$tpl])) {
			$sources          += (int) $entry['count'];
			$counted[$tpl]     = true;
		}
	}

	return [
		'supported_types'   => $supported,
		'unsupported_types' => $unsupported,
		'total_types'       => $supported + $unsupported,
		'total_sources'     => $sources,
	];
}

/**
 * Computes the ranked rows and overall average for a single data source over a
 * whitelisted dsstats table, reproducing every per-operation branch of the
 * classic TopX view (single value, value/number, hdd percentage and the
 * combined errors/discards/traffic sources). Shared by the drilldown dashboard
 * cards so the ranking logic lives in one tested place.
 *
 * @param int    $ds_key         The signed data source key (negative when the
 *                              source is a supported, plugin-defined source).
 * @param bool   $supported      Whether $ds_key is a supported source.
 * @param string $table          Whitelisted dsstats table (see topx_age_table
 *                              or topx_timespan_bucket).
 * @param string $order          Whitelisted stat column for the direct branches.
 * @param string $dir            Whitelisted sort direction (ASC or DESC).
 * @param int    $limit          Record limit (0 for unlimited).
 * @param string $computed_order Whitelisted order column (xvalue or xpeak) for
 *                              the computed branches.
 *
 * @return array{source: array, rows: array, avg: float} The source definition
 *               (empty for unsupported sources), the ranked rows and the overall
 *               average.
 */
function topx_compute_ranking(int $ds_key, bool $supported, string $table, string $order, string $dir, int $limit, string $computed_order): array {
	$limit_clause = $limit > 0 ? " LIMIT $limit" : '';
	$source       = [];
	$result       = [];
	$avg          = 0;

	if (!$supported) {
		$id = $ds_key;

		$from = " FROM data_template_data AS t1
			LEFT JOIN $table AS t2 ON t1.local_data_id = t2.local_data_id
			WHERE t1.data_template_id = ?";

		$avg = db_fetch_cell_prepared("SELECT AVG(average)$from", array($id));

		$result = db_fetch_assoc_prepared("SELECT t1.local_data_id AS ldid, CONCAT(t1.name_cache, ' - ', t2.rrd_name) AS name, t2.average AS xvalue, t2.peak AS xpeak
			$from ORDER BY $computed_order $dir$limit_clause", array($id));

		return ['source' => $source, 'rows' => $result, 'avg' => (float) $avg];
	}

	// A supported entry is keyed by the negative of its plugin_topx_source id, so
	// a Data Template can carry several independent definitions. Resolve the
	// definition by its own id, then derive the data template id (via the stored
	// hash) that locates the data source instances to rank.
	$source = db_fetch_row_prepared('SELECT * FROM plugin_topx_source WHERE id = ?', array(-1 * $ds_key));

	if (!is_array($source)) {
		$source = [];
	}

	$id = (int) db_fetch_cell_prepared('SELECT id FROM data_template WHERE hash = ?', array($source['hash'] ?? ''));

	$operation = $source['operation'] ?? '';

	if (strpos($operation, '=') !== false) {
		$from = " FROM data_template_data AS t1
			LEFT JOIN $table AS t2 ON t1.local_data_id = t2.local_data_id
			WHERE t1.data_template_id = ?";

		$avg    = db_fetch_cell_prepared("SELECT AVG(average)$from", array($id));
		$result = db_fetch_assoc_prepared("SELECT t1.local_data_id AS ldid, CONCAT(t1.name_cache, ' - ', t2.rrd_name) AS name, t2.average AS xvalue, t2.peak AS xpeak
			$from ORDER BY t2.$order $dir$limit_clause", array($id));
	}

	if (strpos($operation, '/') !== false) {
		$from = " FROM data_template_data AS t1
			LEFT JOIN $table AS t2 ON t1.local_data_id = t2.local_data_id
			WHERE t1.data_template_id = ?";

		$avg    = db_fetch_cell_prepared("SELECT AVG(average)$from", array($id));
		$result = db_fetch_assoc_prepared("SELECT t1.local_data_id AS ldid, CONCAT(t1.name_cache, ' - ', t2.rrd_name) AS name, t2.average AS xvalue, t2.peak AS xpeak
			$from ORDER BY t2.$order $dir$limit_clause", array($id));
	} elseif (strpos($operation, '%') !== false) {
		$columns = "name_cache AS name, t2.local_data_id AS ldid,
			100 * average / (SELECT average FROM $table WHERE local_data_id = ldid AND rrd_name = 'hdd_total') AS xvalue,
			100 * peak / (SELECT peak FROM $table WHERE local_data_id = ldid AND rrd_name = 'hdd_total') AS xpeak";

		$from = " FROM data_template_data AS t1
			LEFT JOIN $table AS t2 ON t1.local_data_id = t2.local_data_id
			WHERE t1.data_template_id = ? AND rrd_name = 'hdd_used'";

		$result = db_fetch_assoc_prepared("SELECT $columns $from ORDER BY $computed_order $dir$limit_clause", array($id));

		$xavg = db_fetch_assoc_prepared("SELECT t1.local_data_id AS ldid,
			100 * average / (SELECT average FROM $table WHERE local_data_id = ldid AND rrd_name = 'hdd_total') AS xvalue
			$from", array($id));

		$avg = topx_average_rows($xavg);
	} elseif ($operation === 'discards_in+errors_in') {
		$avg    = topx_combined_sum_average($table, $id, 'discards_in', 'errors_in');
		$result = topx_combined_result($table, $id, 'discards_in', 'errors_in', 1, $computed_order, $dir, $limit_clause);
	} elseif ($operation === 'discards_out+errors_out') {
		$avg    = topx_combined_sum_average($table, $id, 'discards_out', 'errors_out');
		$result = topx_combined_result($table, $id, 'discards_out', 'errors_out', 1, $computed_order, $dir, $limit_clause);
	} elseif ($operation === 'traffic_in+traffic_out') {
		$avg    = 8 * topx_combined_sum_average($table, $id, 'traffic_in', 'traffic_out');
		$result = topx_combined_result($table, $id, 'traffic_in', 'traffic_out', 8, $computed_order, $dir, $limit_clause);
	}

	return ['source' => $source, 'rows' => $result, 'avg' => (float) $avg];
}

/**
 * Derives a short, readable axis label from a data source's full name. Data
 * source names are long device-qualified strings such as
 * "Device - Traffic - 10.0.0.1 - wan1 - traffic_out"; the chart only needs the
 * most specific part (the interface/alias, e.g. "wan1"). When the final segment
 * looks like an rrd field name (all-lowercase with an underscore, e.g.
 * "traffic_out") the segment before it is used instead. The full name is still
 * shown on hover. Kept free of database access so it can be unit tested.
 *
 * @param string $name The full data source name.
 *
 * @return string A short label for the chart axis.
 */
function topx_short_label(string $name): string {
	$parts = array_values(array_filter(array_map('trim', explode(' - ', $name)), function ($part) {
		return $part !== '';
	}));

	$count = cacti_count($parts);

	if ($count === 0) {
		return $name;
	}

	$last = $parts[$count - 1];

	if ($count >= 2 && preg_match('/^[a-z0-9]+(_[a-z0-9]+)+$/', $last)) {
		return $parts[$count - 2];
	}

	return $last;
}
