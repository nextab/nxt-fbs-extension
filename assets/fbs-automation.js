(function () {
	'use strict';

	const INJECTED_ATTR   = 'data-nxt-injected';
	const CONTAINER_CLASS = 'fbs-task-sidebar-action-btns';
	const BTN_CLASS       = 'nxt-fbs-automation-btn';

	/**
	 * Liest die Task-ID aus der aktuellen URL.
	 * Fluent Boards nutzt Hash-Routing: #/boards/{boardId}/tasks/{taskId}
	 */
	function getTaskIdFromUrl() {
		const match = window.location.hash.match(/\/tasks\/(\d+)/);
		return match ? parseInt(match[1], 10) : null;
	}

	/**
	 * Führt den AJAX-Call aus und triggert anschließend ein Custom Event,
	 * damit die Vue-App reagieren kann (z. B. Task neu laden).
	 */
	function runAutomation(actionId, taskId, button) {
		button.disabled = true;
		button.classList.add('is-loading');

		const body = new URLSearchParams({
			action:    'nxt_fbs_run_automation',
			nonce:     nxtFbsVars.nonce,
			task_id:   taskId,
			action_id: actionId,
		});

		fetch(nxtFbsVars.ajaxUrl, {
			method:  'POST',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body:    body.toString(),
		})
			.then(function (res) { return res.json(); })
			.then(function (data) {
				if (data.success) {
					window.dispatchEvent(new CustomEvent('nxt_fbs:automation_success', {
						detail: { actionId: actionId, taskId: taskId, response: data.data },
					}));
				} else {
					const msg = data.data?.message ?? 'Fehler bei der Automation.';
					alert(msg);
					button.disabled = false;
					button.classList.remove('is-loading');
				}
			})
			.catch(function () {
				alert('Verbindungsfehler. Bitte Seite neu laden.');
				button.disabled = false;
				button.classList.remove('is-loading');
			});
	}

	/**
	 * Erstellt einen Button-Node aus einer Button-Konfiguration.
	 */
	function createButton(btnConfig) {
		const btn = document.createElement('button');
		btn.type      = 'button';
		btn.className = 'el-button ' + BTN_CLASS;
		btn.setAttribute('data-nxt-action', btnConfig.id);

		if (btnConfig.icon) {
			const icon = document.createElement('i');
			icon.className = 'el-icon ' + btnConfig.icon;
			btn.appendChild(icon);
		}

		const label = document.createElement('span');
		label.textContent = btnConfig.label ?? btnConfig.id;
		btn.appendChild(label);

		btn.addEventListener('click', function () {
			const taskId = getTaskIdFromUrl();
			if (!taskId) {
				alert('Task-ID konnte nicht ermittelt werden.');
				return;
			}

			if (btnConfig.confirm && !window.confirm(btnConfig.confirm)) {
				return;
			}

			runAutomation(btnConfig.id, taskId, btn);
		});

		return btn;
	}

	/**
	 * Injiziert alle konfigurierten Buttons in den Sidebar-Container.
	 * Idempotent – läuft nicht doppelt wenn Container bereits injiziert wurde.
	 */
	function injectButtons(container) {
		if (container.hasAttribute(INJECTED_ATTR)) return;
		container.setAttribute(INJECTED_ATTR, '1');

		const buttons = window.fluentAddonVars?.nxt_automation_buttons ?? [];
		if (!buttons.length) return;

		buttons.forEach(function (btnConfig) {
			container.appendChild(createButton(btnConfig));
		});
	}

	/**
	 * MutationObserver: Reagiert wenn die Task-Sidebar in den DOM eingefügt wird.
	 * Fluent Boards rendert die Sidebar dynamisch beim Öffnen einer Karte.
	 */
	const observer = new MutationObserver(function (mutations) {
		for (let i = 0; i < mutations.length; i++) {
			const added = mutations[i].addedNodes;
			for (let j = 0; j < added.length; j++) {
				const node = added[j];
				if (!(node instanceof HTMLElement)) continue;

				if (node.classList.contains(CONTAINER_CLASS)) {
					injectButtons(node);
					continue;
				}

				const nested = node.querySelector('.' + CONTAINER_CLASS);
				if (nested) injectButtons(nested);
			}
		}
	});

	/**
	 * Nach Automation-Erfolg: INJECTED_ATTR entfernen, damit beim erneuten
	 * Öffnen des Tasks die Buttons wieder korrekt injiziert werden.
	 */
	window.addEventListener('nxt_fbs:automation_success', function () {
		document.querySelectorAll('.' + CONTAINER_CLASS).forEach(function (el) {
			el.removeAttribute(INJECTED_ATTR);
		});
	});

	document.addEventListener('DOMContentLoaded', function () {
		observer.observe(document.body, { childList: true, subtree: true });

		// Fallback: Falls der Container bereits im DOM ist beim Laden
		const existing = document.querySelector('.' + CONTAINER_CLASS);
		if (existing) injectButtons(existing);
	});
})();
