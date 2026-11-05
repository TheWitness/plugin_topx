/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2015-2026 Petr Macek                                      |
 |                                                                         |
 | This program is free software; you can redistribute it and/or           |
 | modify it under the terms of the GNU General Public License             |
 | as published by the Free Software Foundation; either version 2          |
 | of the License, or (at your option) any later version.                  |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
 | TopX client behaviour: the filter navigation helper plus the billboard  |
 | horizontal bar chart. billboard.js and d3.js are already loaded by      |
 | Cacti's general_header(); this reads its data from a JSON island         |
 | (#topx-chart-data) emitted by topx.php so no PHP values are inlined into |
 | executable JavaScript.                                                   |
 +-------------------------------------------------------------------------+
*/

/* global bb */

// Referenced by the filter selects' onChange handlers in topx.php.
function applyTopxFilter(objForm) {
	var strURL = '?ds=' + encodeURIComponent(objForm.ds.value);
	strURL += '&age=' + encodeURIComponent(objForm.age.value);
	strURL += '&topx=' + encodeURIComponent(objForm.topx.value);
	strURL += '&sort=' + encodeURIComponent(objForm.sort.value);
	strURL += '&what=' + encodeURIComponent(objForm.what.value);

	document.location = strURL;
}

function topxRenderChart() {
	var island = document.getElementById('topx-chart-data');

	if (island === null || typeof bb === 'undefined') {
		return;
	}

	var cfg;

	try {
		cfg = JSON.parse(island.textContent);
	} catch (e) {
		return;
	}

	if (document.getElementById(cfg.bindto.replace(/^#/, '')) === null) {
		return;
	}

	var colors = {};
	colors[cfg.series] = '#555555';

	bb.generate({
		bindto: cfg.bindto,
		size: {
			width: cfg.width,
			height: cfg.height
		},
		data: {
			columns: [[cfg.series].concat(cfg.values)],
			type: 'bar',
			colors: colors
		},
		bar: {
			width: {
				ratio: 0.6
			}
		},
		axis: {
			rotated: true,
			x: {
				type: 'category',
				categories: cfg.labels
			},
			y: {
				label: {
					text: cfg.title,
					position: 'outer-center'
				}
			}
		},
		legend: {
			show: false
		},
		grid: {
			y: {
				lines: [{
					value: cfg.average,
					text: cfg.averageLabel,
					class: 'topx-avg-line'
				}]
			}
		},
		tooltip: {
			show: true
		}
	});
}

if (typeof jQuery !== 'undefined') {
	jQuery(function() {
		topxRenderChart();
	});
} else {
	document.addEventListener('DOMContentLoaded', topxRenderChart);
}
