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
 | TopX horizontal bar chart rendering. Reads its data from a JSON island  |
 | emitted by topx.php (#topx-chart-data) so no PHP values are inlined into |
 | executable JavaScript.                                                   |
 +-------------------------------------------------------------------------+
*/

/* global Chart */

function topxRenderChart() {
	var island = document.getElementById('topx-chart-data');

	if (island === null || typeof Chart === 'undefined') {
		return;
	}

	var config;

	try {
		config = JSON.parse(island.textContent);
	} catch (e) {
		return;
	}

	var canvas = document.getElementById(config.canvas);

	if (canvas === null) {
		return;
	}

	var ctx = canvas.getContext('2d');

	new Chart(ctx, {
		type: 'horizontalBar',
		data: {
			labels: config.labels,
			datasets: [{
				backgroundColor: ['#555555'],
				data: config.values
			}]
		},
		options: {
			responsive: false,
			legend: {
				display: false
			},
			scales: {
				xAxes: [{
					display: true,
					scaleLabel: {
						display: true,
						labelString: config.title
					}
				}]
			},
			tooltipTemplate: '<%= label %>',
			annotation: {
				annotations: [{
					type: 'line',
					mode: 'vertical',
					scaleID: 'x-axis-0',
					value: config.average,
					borderColor: 'red'
				}]
			}
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
