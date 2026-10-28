/**
 * FluentBoards 2.2: Listenansicht zeigt je Phase nur 20 Karten.
 *
 * Der Core lädt beim Öffnen eines Boards je Phase nur die ersten 20 Karten
 * (/tasks/by-stage) und holt weitere erst, wenn der Karten-Container einer
 * Phase bis ans Ende gescrollt wird (ListView.handleScroll). In der Kanban-
 * Spalte klappt das, in der Listenansicht scrollt dieser Container aber nie
 * selbst – es scrollt die Seite –, das Scroll-Event kommt also nie an.
 * Zusätzlich rendert die Liste je Phase nur ein Fenster von 20–25 Karten
 * (visibleTasks), der Rest bleibt leer.
 *
 * Fix: die ListView-Komponente im Vue-Baum suchen, ihr Fenster abschalten
 * (isTaskVisible → immer true) und, sobald eine Phase unvollständig ist, das
 * Board über den Core-eigenen loadAllBoardTasks() komplett laden – derselbe
 * Weg, den Kalender- und Gantt-Ansicht nehmen. Danach sind Store,
 * Kartenzähler („20 / 29“) und Drag & Drop konsistent.
 * Findet das Skript die Interna nicht mehr (FluentBoards-Update), tut es
 * nichts und meldet das einmal in der Konsole.
 */
(function () {
	'use strict';

	if (window.__nxtFbsListViewAllTasks) {
		return;
	}
	window.__nxtFbsListViewAllTasks = true;

	const CONTAINER = '.fbs_list_view_container';

	let warned    = false;
	let scheduled = false;

	function warn(message) {
		if (warned) {
			return;
		}
		warned = true;
		console.warn('[nxt-fbs-extension] Listenansicht: ' + message);
	}

	/**
	 * Vue hängt im Produktions-Build kein __vueParentComponent ans DOM, wohl
	 * aber den Wurzel-VNode ans Mount-Element (data-v-app → _vnode). Von dort
	 * aus die Komponente suchen, die das Element selbst rendert.
	 */
	function findOwner(el) {
		const roots = document.querySelectorAll('[data-v-app]');

		for (let r = 0; r < roots.length; r++) {
			const stack = [roots[r]._vnode];
			let guard   = 0;

			while (stack.length && guard++ < 200000) {
				const vnode = stack.pop();
				if (!vnode || typeof vnode !== 'object') {
					continue;
				}

				const inst = vnode.component;
				if (inst) {
					const sub = inst.subTree;
					if (sub && sub.el === el && typeof sub.type === 'string') {
						return inst;
					}
					stack.push(sub);
					continue;
				}

				if (vnode.suspense) {
					stack.push(vnode.suspense.activeBranch);
				}
				if (Array.isArray(vnode.children)) {
					for (let i = 0; i < vnode.children.length; i++) {
						stack.push(vnode.children[i]);
					}
				}
			}
		}

		return null;
	}

	// Die Board-Komponente, die der ListView fetchStageTasksPage reicht.
	function findBoard(inst) {
		let parent = inst.parent;

		for (let i = 0; parent && i < 10; i++) {
			if (parent.ctx && typeof parent.ctx.loadAllBoardTasks === 'function' && parent.proxy) {
				return parent;
			}
			parent = parent.parent;
		}

		return null;
	}

	function boardId(proxy) {
		if (proxy.shortcodeId) {
			return proxy.shortcodeId;
		}
		if (proxy.$route && proxy.$route.params && proxy.$route.params.id) {
			return proxy.$route.params.id;
		}
		return proxy.board && proxy.board.id;
	}

	function loadAllTasks(listView, board) {
		const proxy      = board.proxy;
		const pagination = listView.props.stageTaskPagination || {};

		if (listView.isUnmounted || board.isUnmounted || proxy.boardAllTaskLoaded) {
			return;
		}

		const incomplete = Object.keys(pagination).some(function (stageId) {
			const page = pagination[stageId];
			return page && (page.has_more_after || page.has_more_before);
		});
		const id = boardId(proxy);

		if (!incomplete || !id) {
			return;
		}

		// Doppelte Aufrufe fängt der Core ab (fullTaskLoadPromise), Fehler
		// zeigt er selbst an. Danach ist die Pagination leer – kein Retry.
		Promise.resolve()
			.then(function () {
				return proxy.loadAllBoardTasks(id);
			})
			.catch(function () {});
	}

	function patch(inst) {
		const ctx   = inst.ctx;
		const board = findBoard(inst);

		if (
			!ctx ||
			!inst.proxy ||
			!board ||
			!('boardAllTaskLoaded' in board.proxy) ||
			typeof ctx.isTaskVisible !== 'function' ||
			!('stageTaskPagination' in inst.props)
		) {
			warn('FluentBoards-Interna haben sich geändert, Fix greift nicht.');
			return;
		}

		// Methoden liegen im Produktions-Build als einfache Properties auf ctx,
		// das kompilierte Template ruft sie direkt von dort auf.
		ctx.isTaskVisible = function () {
			return true;
		};
		inst.proxy.$forceUpdate();

		// Der Watcher hängt an der ListView und endet mit ihr. Er feuert auch
		// nach jedem Neuladen des Boards (by-stage setzt die Pagination neu).
		inst.proxy.$watch(
			'stageTaskPagination',
			function () {
				loadAllTasks(inst, board);
			},
			{ deep: true, immediate: true }
		);
	}

	function scan() {
		scheduled = false;

		document.querySelectorAll(CONTAINER).forEach(function (el) {
			if (el.__nxtAllTasks) {
				return;
			}
			el.__nxtAllTasks = true;

			const inst = findOwner(el);
			if (!inst) {
				warn('ListView-Komponente nicht gefunden, Fix greift nicht.');
				return;
			}
			patch(inst);
		});
	}

	function schedule() {
		if (scheduled) {
			return;
		}
		scheduled = true;
		window.setTimeout(scan, 50);
	}

	function boot() {
		new MutationObserver(schedule).observe(document.body, { childList: true, subtree: true });
		schedule();
	}

	if (document.body) {
		boot();
	} else {
		document.addEventListener('DOMContentLoaded', boot);
	}
})();
