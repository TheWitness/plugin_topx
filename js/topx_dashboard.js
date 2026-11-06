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
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/* UPS Dashboard: the per-UPS drilldown card grid. Mirrors the hmib/intropage
 * card interaction model (drag/keyboard reorder, per-card tools, an "Add card"
 * catalogue, a Cacti timespan selector, auto-refresh interval + manual refresh)
 * with the card order, expanded state and current UPS persisted server-side in
 * settings_user. */

var topxDashDragging = null;

/** Cacti's browser auto-logout timer as it was when this script loaded, before the
 *  dashboard replaces it with its own refresh. Choosing "No Refresh" puts it back (a
 *  page reload used to). It is kept across re-runs of this script, which can find the
 *  dashboard's own override in place of the original. */
var topxDashCoreTimer = typeof topxDashCoreTimer === 'undefined' ? null : topxDashCoreTimer;

if (typeof refreshIsLogout !== 'undefined' && refreshIsLogout === true && typeof refreshMSeconds !== 'undefined' && typeof refreshPage !== 'undefined') {
	topxDashCoreTimer = {ms: refreshMSeconds, page: refreshPage};
}

/** The endpoint/scope configuration for a grid, read from its data attributes so
 *  the per-UPS and fleet dashboards can share this engine. */
function topxDashCfg(grid) {
	return {
		page:          grid.dataset.page          || 'topx.php',
		cardAction:    grid.dataset.cardAction    || 'dashboard_card',
		layoutAction:  grid.dataset.layoutAction  || 'dashboard_layout',
		refreshAction: grid.dataset.refreshAction || 'dashboard_refresh',
		scopeParam:    grid.dataset.scopeParam    || '',
		scope:         grid.dataset.scope         || '',
		reload:        grid.dataset.reload        || 'topx.php',
		refreshPage:   grid.dataset.refreshPage   || ''
	};
}

/** Build a POST payload for a grid, adding the CSRF token and the grid's scope
 *  parameter (the UPS id for the per-UPS dashboard; absent for the fleet one). */
function topxDashPost(grid, extra) {
	var cfg = topxDashCfg(grid);
	var data = {__csrf_magic: csrfMagicToken};
	for (var key in extra) {
		if (Object.prototype.hasOwnProperty.call(extra, key)) {
			data[key] = extra[key];
		}
	}
	if (cfg.scopeParam) {
		data[cfg.scopeParam] = cfg.scope;
	}
	return data;
}

/** Make a container's sortable headers focusable and screen-reader friendly. */
function topxDashPrepSortHeaders(container) {
	if (!container) {
		return;
	}
	container.querySelectorAll('th.topxDashSortable').forEach(function(th) {
		if (!th.hasAttribute('tabindex')) {
			th.setAttribute('tabindex', '0');
		}
		// Keep the native columnheader role: aria-sort is only valid there, and the
		// tabindex + Enter/Space keydown handler already makes it operable.
		if (!th.hasAttribute('aria-sort')) {
			th.setAttribute('aria-sort', 'none');
		}
	});
}

/** Sort a card table by the clicked column header, toggling asc/desc. Numeric
 *  columns (data-sort="num") sort by the cell's data-sort-value when present. */
function topxDashSortTable(th) {
	var table = th.closest('table');
	if (!table) {
		return;
	}
	var tbody = table.tBodies[0];
	if (!tbody) {
		return;
	}
	var headers = Array.prototype.slice.call(th.parentNode.children);
	var index = headers.indexOf(th);
	var numeric = th.dataset.sort === 'num';
	var asc = !th.classList.contains('topxDashSortAsc');

	headers.forEach(function(header) {
		header.classList.remove('topxDashSortAsc', 'topxDashSortDesc');
		if (header.classList.contains('topxDashSortable')) {
			header.setAttribute('aria-sort', 'none');
		}
	});
	th.classList.add(asc ? 'topxDashSortAsc' : 'topxDashSortDesc');
	th.setAttribute('aria-sort', asc ? 'ascending' : 'descending');

	var rows = Array.prototype.slice.call(tbody.rows).filter(function(row) {
		return !row.classList.contains('topxDashEmptyRow');
	});

	var value = function(row) {
		var cell = row.cells[index];
		if (!cell) {
			return numeric ? 0 : '';
		}
		if (numeric) {
			var raw = cell.dataset.sortValue !== undefined ? cell.dataset.sortValue : cell.textContent;
			var num = parseFloat(String(raw).replace(/[^0-9.\-]/g, ''));
			return isNaN(num) ? 0 : num;
		}
		return cell.textContent.trim().toLowerCase();
	};

	rows.sort(function(a, b) {
		var va = value(a);
		var vb = value(b);
		if (va < vb) { return asc ? -1 : 1; }
		if (va > vb) { return asc ? 1 : -1; }
		return 0;
	});

	rows.forEach(function(row) {
		tbody.appendChild(row);
	});
}

/** Remember which header a card's table is sorted by, and in which direction. */
function topxDashSortSnapshot(card) {
	var th = card.querySelector('th.topxDashSortAsc, th.topxDashSortDesc');
	if (!th) {
		return null;
	}
	return {index: Array.prototype.indexOf.call(th.parentNode.children, th), asc: th.classList.contains('topxDashSortAsc')};
}

/** Re-apply a remembered sort to a freshly rendered card, so an in-place refresh
 *  does not throw away the order the user picked. */
function topxDashSortRestore(card, snapshot) {
	if (!snapshot) {
		return;
	}
	var th = card.querySelector('th.topxDashSortable:nth-child(' + (snapshot.index + 1) + ')');
	if (!th) {
		return;
	}
	// topxDashSortTable() toggles the direction, so seed the opposite one.
	th.classList.toggle('topxDashSortAsc', !snapshot.asc);
	topxDashSortTable(th);
}

/** Wire click and Enter/Space sorting on the sortable headers under a root. The
 *  handlers are delegated, so cards that are refreshed or added later keep working. */
function topxDashBindSort(root) {
	$(root).off('click.topxsort').on('click.topxsort', 'th.topxDashSortable', function() {
		topxDashSortTable(this);
	});
	$(root).off('keydown.topxsort').on('keydown.topxsort', 'th.topxDashSortable', function(event) {
		if (event.key === 'Enter' || event.key === ' ' || event.key === 'Spacebar') {
			event.preventDefault();
			topxDashSortTable(this);
		}
	});
}

function initTopxDashboard() {
	$(function() {
		var grid = document.getElementById('topx_dashboard');
		if (!grid) {
			return;
		}

		// Delegated so the sortable headers also work in cards that are refreshed or
		// added later.
		topxDashBindSort(grid);

		grid.querySelectorAll('.topxDashCard').forEach(function(card) {
			topxDashBindCard(grid, card);
		});

		grid.addEventListener('dragover', function(event) {
			if (!topxDashDragging) {
				return;
			}
			event.preventDefault();
			event.dataTransfer.dropEffect = 'move';
			var before = topxDashDragReference(grid, event.clientX, event.clientY);
			if (before == null) {
				grid.appendChild(topxDashDragging);
			} else if (before !== topxDashDragging) {
				grid.insertBefore(topxDashDragging, before);
			}
		});

		// Per-card tool buttons bubble to the grid.
		$(grid).off('click.topxdash').on('click.topxdash', '.topxDashCardTool', function() {
			var card = this.closest('.topxDashCard');
			if (!card) {
				return;
			}
			switch (this.dataset.tool) {
				case 'expand':   card.classList.add('topxDashCardExpanded'); topxDashSaveLayout(grid); break;
				case 'collapse': card.classList.remove('topxDashCardExpanded'); topxDashSaveLayout(grid); break;
				case 'maximize': topxDashMaximize(card); break;
				case 'refresh':  topxDashRefreshCard(grid, card); break;
				case 'remove':   topxDashRemoveCard(grid, card); break;
			}
		});

		var add = document.getElementById('topx_dashboard_add');
		if (add) {
			$(add).off('change.topxdash').on('change.topxdash', function() {
				var key = this.value;
				this.value = '';
				if (key) {
					topxDashAddCard(grid, key);
				}
			});
		}

		var ups = document.getElementById('topx_dashboard_ds');
		if (ups) {
			// Switch the per-UPS dashboard in place (no full page refresh), like the
			// timespan selector and the UPS filter do.
			$(ups).off('change.topxdash').on('change.topxdash', function() {
				if (this.value) {
					loadPageNoHeader('topx.php?action=view&ds=' + encodeURIComponent(this.value) + '&header=false');
				}
			});
		}

		// Changing the timespan loads the dashboard in place (no full page refresh)
		// so Cacti's session timespan selector picks up predefined_timespan, which
		// drives both the graph cards and the RRD bullet/gauge queries. The grid's
		// reload target (per-UPS view or the fleet dashboard) carries the id, so
		// build the URL from it and request it without the page header.
		var timespan = document.getElementById('topx_dashboard_timespan');
		if (timespan) {
			$(timespan).off('change.topxdash').on('change.topxdash', function() {
				var reload = topxDashCfg(grid).reload;
				var sep = reload.indexOf('?') === -1 ? '?' : '&';
				loadPageNoHeader(reload + sep + 'predefined_timespan=' + encodeURIComponent(this.value) + '&header=false');
			});
		}

		// Changing the interval persists the preference through a CSRF-protected POST
		// (never a drive-by GET), then applies it straight away without reloading the
		// page. If the save fails the dropdown goes back to the interval in force.
		var interval = document.getElementById('topx_dashboard_refresh');
		if (interval) {
			interval.dataset.previous = interval.value;
			$(interval).off('change.topxdash').on('change.topxdash', function() {
				topxDashSaveRefresh(grid, this);
			});
		}

		var refreshNow = document.getElementById('topx_dashboard_refresh_now');
		if (refreshNow) {
			$(refreshNow).off('click.topxdash').on('click.topxdash', function() {
				topxDashRefreshAll(grid, this);
			});
		}

		// The drilldown records/order/what selects reload the dashboard in place
		// (like the timespan selector) so the server re-validates and persists them.
		var filterReload = function() {
			var cfg = topxDashCfg(grid);
			var params = [];
			['records', 'sort', 'what'].forEach(function(name) {
				var el = document.getElementById('topx_dashboard_' + name);
				if (el) {
					params.push(name + '=' + encodeURIComponent(el.value));
				}
			});
			var sep = cfg.reload.indexOf('?') === -1 ? '?' : '&';
			loadPageNoHeader(cfg.reload + sep + params.join('&') + '&header=false');
		};
		['records', 'sort', 'what'].forEach(function(name) {
			var el = document.getElementById('topx_dashboard_' + name);
			if (el) {
				$(el).off('change.topxdash').on('change.topxdash', filterReload);
			}
		});

		topxDashRenderCharts(grid);
	});
}

/** Format a chart value for display with the browser locale's thousands
 *  separators and at most two decimals, so huge raw floats (e.g.
 *  16835076.05555555) read as 16,835,076.06. */
function topxFormatNumber(value) {
	var n = Number(value);

	if (!isFinite(n)) {
		return value;
	}

	return n.toLocaleString(undefined, {maximumFractionDigits: 2});
}

/** Escape HTML special characters. billboard injects the tooltip title as HTML,
 *  so a label or data source name containing '<', '>' or '&' would otherwise
 *  leak markup (e.g. a stray "</th>") or break the tooltip. */
function topxEscapeHtml(value) {
	return String(value).replace(/[&<>"']/g, function(ch) {
		return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[ch];
	});
}

/** Render every billboard chart placeholder under a root from its data-chart
 *  JSON. Skips placeholders already rendered, so it is safe to call again after a
 *  card is added or refreshed. billboard.js is loaded globally by general_header(). */
function topxDashRenderCharts(root) {
	if (!root || typeof bb === 'undefined' || !root.querySelectorAll) {
		return;
	}

	root.querySelectorAll('.topx-bb-chart').forEach(function(node) {
		if (node.dataset.rendered === '1') {
			return;
		}

		var cfg;
		try {
			cfg = JSON.parse(node.dataset.chart);
		} catch (e) {
			return;
		}

		node.dataset.rendered = '1';

		// Per-bar colours. A chart may supply its own ordered range (e.g. the
		// Uptime-by-Host card's red -> green buckets); otherwise fall back to a
		// theme-friendly, green-forward categorical palette so bars are readable
		// and distinct on every Cacti theme. Flat grey (#555) was invisible on
		// dark themes.
		var palette = (cfg.barColors && cfg.barColors.length) ? cfg.barColors : [
			'#2ecc71', '#2e86de', '#8e5bd0', '#f0932b',
			'#17a2a2', '#c9b03a', '#c0556f', '#5aa0c9'
		];

		var options = {
			bindto: node,
			data: {
				columns: [[cfg.series].concat(cfg.values)],
				type: 'bar',
				color: function(inColor, d) {
					if (d && typeof d === 'object' && typeof d.index === 'number') {
						return palette[d.index % palette.length];
					}

					return palette[0];
				}
			},
			bar: {
				width: {
					ratio: 0.6
				}
			},
			axis: {
				rotated: !!cfg.rotated,
				x: {
					type: 'category',
					categories: cfg.labels,
					tick: {
						// Keep each ranking label on a single line instead of
						// wrapping long "host - datasource" names onto two rows.
						multiline: false
					}
				},
				y: {
					label: {
						text: cfg.title,
						position: 'outer-center'
					},
					tick: {
						format: function(value) {
							return topxFormatNumber(value);
						}
					}
				}
			},
			legend: {
				show: false
			},
			tooltip: {
				show: true,
				format: {
					// Show the full data source name (if supplied) on hover, and
					// a locale-formatted value instead of a long raw float.
					title: function(index) {
						if (cfg.fullLabels && cfg.fullLabels.length && typeof cfg.fullLabels[index] !== 'undefined') {
							return topxEscapeHtml(cfg.fullLabels[index]);
						}

						return topxEscapeHtml((cfg.labels && typeof cfg.labels[index] !== 'undefined') ? cfg.labels[index] : index);
					},
					value: function(value) {
						return topxFormatNumber(value);
					}
				}
			}
		};

		// When per-bar peaks are supplied, render each bar as a bullet-style
		// overlay: a thick, light background bar for the peak with a thinner,
		// solid bar for the average nested inside it. billboard's bar.overlap
		// draws the two series at the same category position (instead of side by
		// side) and per-series bar.width makes the average bar thinner, so the
		// average reads as a measure bar inside the peak range band.
		var dual = cfg.rotated && cfg.peaks && cfg.peaks.length === cfg.values.length;

		if (dual) {
			options.data.columns = [
				[cfg.peakLabel].concat(cfg.peaks),
				[cfg.averageLabel].concat(cfg.values)
			];

			options.bar = {
				overlap: true,
				width: {}
			};
			options.bar.width[cfg.peakLabel]    = {ratio: 0.82};
			options.bar.width[cfg.averageLabel] = {ratio: 0.40};

			options.data.color = function(inColor, d) {
				if (d && typeof d === 'object' && d.id === cfg.peakLabel) {
					return 'rgba(130,130,130,0.28)';
				}

				if (d && typeof d === 'object' && typeof d.index === 'number') {
					if (cfg.barColors && cfg.barColors.length) {
						return cfg.barColors[d.index % cfg.barColors.length];
					}

					return palette[d.index % palette.length];
				}

				return palette[0];
			};

			options.legend = {show: true};
		}

		// Horizontal (rotated) ranking charts grow with the record count so Top
		// 10/20/50 stay readable instead of squeezing bars into a fixed height.
		if (cfg.rotated) {
			options.size = {height: Math.max(240, 44 + cfg.values.length * 34)};
		}

		if (cfg.average && cfg.average > 0) {
			options.grid = {
				y: {
					lines: [{
						value: cfg.average,
						text: cfg.averageLabel,
						class: 'topx-avg-line'
					}]
				}
			};
		}

		bb.generate(options);
	});
}

/** Wire a single card's drag handle (mouse + keyboard) and drag events. */
function topxDashBindCard(grid, card) {
	topxDashPrepSortHeaders(card);

	var handle = card.querySelector('.topxDashCardDrag');
	if (!handle) {
		return;
	}

	// Arm HTML5 drag only from the handle, and disarm on release when no drag
	// began so selecting content elsewhere can't drag the card.
	var disarm = function() { card.draggable = false; };
	handle.addEventListener('mousedown', function() {
		card.draggable = true;
		document.addEventListener('mouseup', disarm, {once: true});
	});
	handle.addEventListener('touchstart', function() {
		card.draggable = true;
		document.addEventListener('touchend', disarm, {once: true});
		document.addEventListener('touchcancel', disarm, {once: true});
	}, {passive: true});

	// Keyboard-accessible reordering: move the card with the arrow keys.
	handle.addEventListener('keydown', function(event) {
		var back = event.key === 'ArrowLeft' || event.key === 'ArrowUp';
		var fwd  = event.key === 'ArrowRight' || event.key === 'ArrowDown';
		if (!back && !fwd) {
			return;
		}
		event.preventDefault();
		if (back && card.previousElementSibling) {
			grid.insertBefore(card, card.previousElementSibling);
		} else if (fwd && card.nextElementSibling) {
			grid.insertBefore(card.nextElementSibling, card);
		} else {
			return;
		}
		handle.focus();
		topxDashSaveLayout(grid);
	});

	card.addEventListener('dragstart', function(event) {
		topxDashDragging = card;
		card.classList.add('topxDashCardDragging');
		event.dataTransfer.effectAllowed = 'move';
		try { event.dataTransfer.setData('text/plain', card.dataset.card || ''); } catch (error) { /* IE guard */ }
	});

	card.addEventListener('dragend', function() {
		card.draggable = false;
		card.classList.remove('topxDashCardDragging');
		if (topxDashDragging) {
			topxDashDragging = null;
			topxDashSaveLayout(grid);
		}
	});
}

/** The card the dragged card should be inserted before for the current pointer
 *  position, or null to append at the end. */
function topxDashDragReference(grid, x, y) {
	var cards = Array.prototype.slice.call(grid.querySelectorAll('.topxDashCard:not(.topxDashCardDragging)'));
	for (var i = 0; i < cards.length; i++) {
		var rect = cards[i].getBoundingClientRect();
		if (y < rect.top - 1) {
			return cards[i];
		}
		if (y <= rect.bottom && x < rect.left + rect.width / 2) {
			return cards[i];
		}
	}
	return null;
}

/** Add a card from the catalogue: fetch its HTML, append it, persist and refresh
 *  the "Add" options. */
function topxDashAddCard(grid, key) {
	if (topxDashHasCard(grid, key)) {
		return;
	}
	var cfg = topxDashCfg(grid);
	$.post(cfg.page, topxDashPost(grid, {action: cfg.cardAction, card: key}), null, 'json').done(function(data) {
		if (!data || !data.html || topxDashHasCard(grid, key)) {
			return;
		}
		var card = topxDashParseCard(data.html);
		if (!card) {
			return;
		}
		grid.appendChild(card);
		topxDashBindCard(grid, card);
		topxDashRenderCharts(card);
		topxDashSaveLayout(grid);
		topxDashSyncAddOptions(grid);
	});
}

/** Whether a card with the given key is currently on the grid. */
function topxDashHasCard(grid, key) {
	var present = false;
	grid.querySelectorAll('.topxDashCard').forEach(function(card) {
		if (card.dataset.card === key) {
			present = true;
		}
	});
	return present;
}

/** Remove a card from the page and return it to the "Add" catalogue. */
function topxDashRemoveCard(grid, card) {
	card.parentNode.removeChild(card);
	topxDashSaveLayout(grid);
	topxDashSyncAddOptions(grid);
}

/** Re-fetch a single card's HTML and swap it in place, keeping expanded state. */
function topxDashRefreshCard(grid, card, done) {
	var icon = card.querySelector('.topxDashCardTool[data-tool="refresh"] .fa, .topxDashCardTool[data-tool="refresh"] .fas');
	if (icon) {
		icon.classList.add('fa-spin');
	}
	var settle = function() {
		if (icon) {
			icon.classList.remove('fa-spin');
		}
		if (done) {
			done();
		}
	};
	var expanded = card.classList.contains('topxDashCardExpanded') ? '1' : '0';
	var cfg = topxDashCfg(grid);
	$.post(cfg.page, topxDashPost(grid, {action: cfg.cardAction, card: card.dataset.card, expanded: expanded}), null, 'json').done(function(data) {
		if (!data || !data.html || !card.parentNode) {
			settle();
			return;
		}
		var fresh = topxDashParseCard(data.html);
		if (!fresh) {
			settle();
			return;
		}
		// Read the card's expanded state and sort at swap time: the user may have toggled
		// or sorted it while this request was in flight, and that live state must win over
		// anything captured when the request was sent.
		var sorted = topxDashSortSnapshot(card);
		if (card.classList.contains('topxDashCardExpanded')) {
			fresh.classList.add('topxDashCardExpanded');
		} else {
			fresh.classList.remove('topxDashCardExpanded');
		}
		card.parentNode.replaceChild(fresh, card);
		topxDashBindCard(grid, fresh);
		topxDashRenderCharts(fresh);
		topxDashSortRestore(fresh, sorted);
		if (done) {
			done();
		}
	}).fail(settle);
}

/** Refresh every card on the page, spinning the toolbar glyph until all land. A
 *  refresh that is still in flight is not stacked on, so a slow server is never
 *  hit by overlapping rounds. */
var topxDashRefreshing = false;

function topxDashRefreshAll(grid, button) {
	var icon = button ? button.querySelector('.fa, .fas') : null;
	var cards = Array.prototype.slice.call(grid.querySelectorAll('.topxDashCard'));
	var pending = cards.length;
	if (!pending || topxDashRefreshing) {
		return;
	}
	topxDashRefreshing = true;
	if (icon) {
		icon.classList.add('fa-spin');
	}
	cards.forEach(function(card) {
		topxDashRefreshCard(grid, card, function() {
			pending--;
			if (pending <= 0) {
				topxDashRefreshing = false;
				if (icon) {
					icon.classList.remove('fa-spin');
				}
			}
		});
	});
}

/** Called by Cacti's page timer (the refreshFunction global, the hook the Graphs
 *  page uses too): refresh the cards in place instead of reloading the page. Cacti
 *  re-arms the timer after this returns. The timer outlives ajax page loads, so if
 *  the dashboard is no longer on screen the page that replaced it gets Cacti's
 *  normal full-page refresh back. */
function topxDashAutoRefresh() {
	var grid = document.getElementById('topx_dashboard');
	if (grid) {
		topxDashRefreshAll(grid, document.getElementById('topx_dashboard_refresh_now'));
		return;
	}

	refreshFunction = '';
	if (typeof loadUrl === 'function' && typeof refreshPage !== 'undefined' && refreshPage !== '') {
		loadUrl({url: refreshPage});
	}
}

var topxDashTimer = null;
var topxDashTimerMs = 0;

/** One tick of the timer used where Cacti has no refreshFunction hook. It stops
 *  for good once the dashboard is no longer on screen, so it can never follow
 *  the user to another page. */
function topxDashOwnTick() {
	topxDashTimer = null;

	var grid = document.getElementById('topx_dashboard');
	if (!grid) {
		return;
	}

	topxDashRefreshAll(grid, document.getElementById('topx_dashboard_refresh_now'));
	topxDashTimer = window.setTimeout(topxDashOwnTick, topxDashTimerMs);
}

/** Start automatic refresh with the interval the page script put in Cacti's
 *  refreshMSeconds global. Cacti 1.3+ has a refreshFunction hook in its page
 *  timer, so use that. Cacti 1.2.x has no such hook and its timer would reload
 *  the whole page (losing the cards' state), so there the page timer is stopped
 *  and a timer of our own refreshes the cards instead. */
function topxDashStartRefresh() {
	if (typeof refreshFunction !== 'undefined') {
		refreshFunction = 'topxDashAutoRefresh()';
		setupPageTimeout();
		return;
	}

	if (typeof myRefresh !== 'undefined') {
		clearTimeout(myRefresh);
	}
	if (topxDashTimer !== null) {
		clearTimeout(topxDashTimer);
	}

	topxDashTimerMs = refreshMSeconds;
	topxDashTimer = window.setTimeout(topxDashOwnTick, topxDashTimerMs);
}

/** Put Cacti's own browser auto-logout timer back, as it was before the dashboard
 *  replaced it with a refresh. Does nothing when the page had no such timer. */
function topxDashRestoreCoreTimer() {
	if (topxDashCoreTimer === null || typeof setupPageTimeout !== 'function') {
		return;
	}

	refreshIsLogout = true;
	refreshPage = topxDashCoreTimer.page;
	refreshMSeconds = topxDashCoreTimer.ms;

	if (typeof refreshFunction !== 'undefined') {
		refreshFunction = '';
	}

	setupPageTimeout();
}

/** Apply a new auto-refresh interval without reloading the page: stop whichever
 *  timer is running, then start one for the new interval, or for 0 (off) hand the
 *  page back to Cacti's own timer. */
function topxDashSetRefresh(seconds, page) {
	if (typeof myRefresh !== 'undefined') {
		clearTimeout(myRefresh);
	}
	if (topxDashTimer !== null) {
		clearTimeout(topxDashTimer);
		topxDashTimer = null;
	}

	if (seconds <= 0) {
		topxDashRestoreCoreTimer();
		return;
	}

	refreshIsLogout = false;
	refreshPage = page;
	refreshMSeconds = seconds * 1000;
	topxDashStartRefresh();
}

/** Set a dropdown's value and bring the widget Cacti draws over it (Select2 on
 *  1.2.x, a jQuery UI selectmenu on 1.3) back in line, without running the change
 *  handlers that save a preference. */
function topxDashSetSelect(select, value) {
	var $select = $(select);

	select.value = value;

	if ($select.data('select2')) {
		$select.trigger('change.select2');
	}

	if ($select.data('ui-selectmenu')) {
		$select.selectmenu('refresh');
	}
}

var topxDashRefreshSaving = false;
var topxDashRefreshPending = false;

/** Save the Refresh dropdown's choice, then apply it. One save is in flight at a
 *  time: a choice made meanwhile is sent once it settles, so saves cannot finish
 *  out of order and only a value that was actually saved updates the timer or
 *  becomes the one a failed save rolls back to. */
function topxDashSaveRefresh(grid, select) {
	if (topxDashRefreshSaving) {
		topxDashRefreshPending = true;
		return;
	}

	topxDashRefreshSaving = true;

	var cfg = topxDashCfg(grid);
	var value = select.value;
	var saved = false;
	var message = '';

	$.post(cfg.page, topxDashPost(grid, {action: cfg.refreshAction, refresh: value}), null, 'json').done(function(data) {
		if (data && data.ok) {
			saved = true;
			select.dataset.previous = value;
			topxDashSetRefresh(parseInt(value, 10) || 0, cfg.refreshPage);
		} else if (data && data.error) {
			message = data.error;
		}
	}).always(function() {
		topxDashRefreshSaving = false;

		if (topxDashRefreshPending) {
			topxDashRefreshPending = false;

			if (select.value !== select.dataset.previous) {
				topxDashSaveRefresh(grid, select);
				return;
			}
		}

		if (saved) {
			topxDashShowStatus(grid, '');
		} else {
			topxDashSetSelect(select, select.dataset.previous);
			topxDashShowStatus(grid, message);
		}
	});
}

/** Open a copy of a card's body in a large modal dialog. */
function topxDashMaximize(card) {
	var dialog = document.getElementById('topx_dashboard_dialog');
	if (!dialog) {
		return;
	}
	var title = card.querySelector('.topxDashCardTitle');
	var body  = card.querySelector('.topxDashCardBody');
	dialog.innerHTML = '<div class="topxDashDialogBody">' + (body ? body.innerHTML : '') + '</div>';

	// The dialog is a clone outside the grid, so wire its own sort handlers.
	topxDashPrepSortHeaders(dialog);
	topxDashBindSort(dialog);

	$(dialog).dialog({
		modal: true,
		appendTo: 'body',
		width: Math.min(900, $(window).width() - 40),
		title: title ? title.textContent : ''
	});
}

/** Parse a card HTML string into its <section> element. */
function topxDashParseCard(html) {
	var tmp = document.createElement('div');
	tmp.innerHTML = html;
	return tmp.querySelector('.topxDashCard');
}

/** Rebuild the "Add" dropdown so it lists only cards not currently on the page. */
function topxDashSyncAddOptions(grid) {
	var add = document.getElementById('topx_dashboard_add');
	if (!add || typeof topxDashCatalog === 'undefined') {
		return;
	}
	var present = {};
	grid.querySelectorAll('.topxDashCard').forEach(function(card) {
		if (card.dataset.card) {
			present[card.dataset.card] = true;
		}
	});
	while (add.options.length > 1) {
		add.remove(1);
	}
	Object.keys(topxDashCatalog).forEach(function(key) {
		if (!present[key]) {
			var option = document.createElement('option');
			option.value = key;
			option.textContent = topxDashCatalog[key];
			add.appendChild(option);
		}
	});
}

/** Show (or clear, with an empty message) the status line above the grid. */
function topxDashShowStatus(grid, message) {
	var status = grid.previousElementSibling && grid.previousElementSibling.querySelector('.topxDashSaveStatus');
	if (status) {
		status.textContent = message;
		status.hidden = !status.textContent;
	}
}

/** Serialize layout POSTs so rapid actions can't persist out of order: one
 *  request is in flight at a time, and a save requested meanwhile is coalesced
 *  into a single follow-up that reads the latest DOM, so the final state wins. */
var topxDashSaveInFlight = false;
var topxDashSaveQueued = false;

function topxDashSaveLayout(grid) {
	if (topxDashSaveInFlight) {
		topxDashSaveQueued = true;
		return;
	}
	topxDashSaveInFlight = true;

	var order = [];
	var expanded = {};
	grid.querySelectorAll('.topxDashCard').forEach(function(card) {
		if (!card.dataset.card) {
			return;
		}
		order.push(card.dataset.card);
		if (card.classList.contains('topxDashCardExpanded')) {
			expanded[card.dataset.card] = true;
		}
	});

	var cfg = topxDashCfg(grid);
	$.post(cfg.page, topxDashPost(grid, {
		action: cfg.layoutAction,
		layout: JSON.stringify({order: order, expanded: expanded})
	}), null, 'json').done(function(data) {
		topxDashShowStatus(grid, data && data.error ? data.error : '');
	}).always(function() {
		topxDashSaveInFlight = false;
		if (topxDashSaveQueued) {
			topxDashSaveQueued = false;
			topxDashSaveLayout(grid);
		}
	});
}
