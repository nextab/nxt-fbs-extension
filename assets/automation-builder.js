(function () {
	'use strict';

	// ─── State ───────────────────────────────────────────────────────────────────

	let state = {
		boards:  [],
		users:   [],
		buttons: [],
		saving:  false,
	};

	// ─── i18n ─────────────────────────────────────────────────────────────────────

	const i18n = {
		newButton:       'Neuer Button',
		buttonLabel:     'Button-Label',
		confirmText:     'Bestätigungstext (optional)',
		addAction:       '+ Aktion hinzufügen',
		deleteButton:    'Button löschen',
		deleteAction:    'Entfernen',
		save:            'Speichern',
		saved:           'Gespeichert.',
		saving:          'Wird gespeichert…',
		loading:         'Lade Daten…',
		actionType:      'Aktion wählen…',
		chooseLabel:     'Labels wählen…',
		chooseBoard:     'Board wählen…',
		chooseUser:      'Benutzer wählen…',
		mode_add:        'Hinzufügen',
		mode_remove:     'Entfernen',
		priority_low:    'Niedrig',
		priority_medium: 'Mittel',
		priority_high:   'Hoch',
		status_open:     'Offen',
		status_closed:   'Abgeschlossen',
		noBoards:        '(Keine Boards verfügbar)',
		confirmDelete:   'Button wirklich löschen?',
		errSave:         'Fehler beim Speichern.',
		errLoad:         'Fehler beim Laden der Daten.',
		actionsLabel:    'Aktionen (werden der Reihe nach ausgeführt):',
		labelBoardContext: 'Labels aus Board: ',
		noActions:       'Noch keine Aktionen.',
	};

	const ACTION_TYPES = [
		{ value: 'set_label',    label: 'Label(s) setzen' },
		{ value: 'set_priority', label: 'Priorität setzen' },
		{ value: 'set_status',   label: 'Status setzen' },
		{ value: 'move_board',   label: 'Auf anderes Board verschieben' },
		{ value: 'set_assignee', label: 'Assignee ändern' },
	];

	// ─── Helpers ──────────────────────────────────────────────────────────────────

	function uid() {
		return 'btn_' + Date.now().toString(36) + '_' + Math.random().toString(36).slice(2, 7);
	}

	function el(tag, attrs, children) {
		const node = document.createElement(tag);
		if (attrs) {
			Object.entries(attrs).forEach(([k, v]) => {
				if (k === 'class') node.className = v;
				else if (k.startsWith('on')) node.addEventListener(k.slice(2), v);
				else node.setAttribute(k, v);
			});
		}
		if (children) {
			(Array.isArray(children) ? children : [children]).forEach(c => {
				if (c === null || c === undefined) return;
				node.appendChild(typeof c === 'string' ? document.createTextNode(c) : c);
			});
		}
		return node;
	}

	function makeSelect(options, value, onchange, placeholder) {
		const select = el('select');
		if (placeholder) {
			const ph = el('option', { value: '' });
			ph.textContent = placeholder;
			ph.disabled = true;
			if (!value) ph.selected = true;
			select.appendChild(ph);
		}
		options.forEach(opt => {
			const o = el('option', { value: String(opt.value) });
			o.textContent = opt.label;
			if (String(opt.value) === String(value)) o.selected = true;
			select.appendChild(o);
		});
		select.addEventListener('change', () => onchange(select.value));
		return select;
	}

	// ─── AJAX ─────────────────────────────────────────────────────────────────────

	function fetchBuilderData() {
		return fetch(nxtFbsBuilder.ajaxUrl, {
			method:  'POST',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body:    new URLSearchParams({ action: 'nxt_fbs_get_builder_data', nonce: nxtFbsBuilder.dataNonce }),
		}).then(r => r.json());
	}

	function saveButtons(buttons) {
		return fetch(nxtFbsBuilder.ajaxUrl, {
			method:  'POST',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body:    new URLSearchParams({
				action:  'nxt_fbs_save_buttons',
				nonce:   nxtFbsBuilder.saveNonce,
				buttons: JSON.stringify(buttons),
			}),
		}).then(r => r.json());
	}

	// ─── Render: Action row ───────────────────────────────────────────────────────

	function renderActionFields(action, onChange) {
		const wrapper = el('div', { class: 'nxt-action-fields' });

		function refresh() {
			wrapper.innerHTML = '';
			renderFields();
		}

		function renderFields() {
			switch (action.type) {
				case 'set_label':
					renderSetLabel();
					break;
				case 'set_priority':
					renderSetPriority();
					break;
				case 'set_status':
					renderSetStatus();
					break;
				case 'move_board':
					renderMoveBoard();
					break;
				case 'set_assignee':
					renderSetAssignee();
					break;
			}
		}

		function renderSetLabel() {
			// Board selector to filter labels
			const boardOptions = state.boards.map(b => ({ value: b.id, label: b.title }));
			const boardSel = makeSelect(boardOptions, action._board_id ?? '', (val) => {
				action._board_id = parseInt(val, 10);
				action.label_ids = [];
				refresh();
			}, i18n.chooseBoard);
			boardSel.className = 'nxt-select-sm';

			wrapper.appendChild(el('span', { class: 'nxt-field-label' }, i18n.labelBoardContext));
			wrapper.appendChild(boardSel);

			if (action._board_id) {
				const board = state.boards.find(b => b.id === action._board_id);
				if (board && board.labels.length) {
					const labelIds = action.label_ids ?? [];
					board.labels.forEach(lbl => {
						const cbId = 'lbl_' + lbl.id + '_' + action._uid;
						const cb   = el('input', { type: 'checkbox', id: cbId, value: String(lbl.id) });
						if (labelIds.includes(lbl.id)) cb.checked = true;

						cb.addEventListener('change', () => {
							const current = action.label_ids ?? [];
							if (cb.checked) {
								action.label_ids = [...current, lbl.id];
							} else {
								action.label_ids = current.filter(id => id !== lbl.id);
							}
							onChange();
						});

						const lblEl = el('label', { for: cbId, class: 'nxt-label-chip' }, lbl.title);
						if (lbl.color) lblEl.style.borderLeftColor = lbl.color;

						const row = el('span', { class: 'nxt-label-option' }, [cb, lblEl]);
						wrapper.appendChild(row);
					});
				}
			}
		}

		function renderSetPriority() {
			const opts = [
				{ value: 'low',    label: i18n.priority_low },
				{ value: 'medium', label: i18n.priority_medium },
				{ value: 'high',   label: i18n.priority_high },
			];
			wrapper.appendChild(makeSelect(opts, action.priority ?? '', (val) => {
				action.priority = val;
				onChange();
			}, i18n.actionType));
		}

		function renderSetStatus() {
			const opts = [
				{ value: 'open',   label: i18n.status_open },
				{ value: 'closed', label: i18n.status_closed },
			];
			wrapper.appendChild(makeSelect(opts, action.status ?? '', (val) => {
				action.status = val;
				onChange();
			}, i18n.actionType));
		}

		function renderMoveBoard() {
			const boardOptions = state.boards.map(b => ({ value: b.id, label: b.title }));
			wrapper.appendChild(makeSelect(boardOptions, action.board_id ?? '', (val) => {
				action.board_id = parseInt(val, 10);
				onChange();
			}, i18n.chooseBoard));
		}

		function renderSetAssignee() {
			const userOptions = state.users.map(u => ({ value: u.id, label: u.label }));
			wrapper.appendChild(makeSelect(userOptions, action.user_id ?? '', (val) => {
				action.user_id = parseInt(val, 10);
				onChange();
			}, i18n.chooseUser));

			const modeOpts = [
				{ value: 'add',    label: i18n.mode_add },
				{ value: 'remove', label: i18n.mode_remove },
			];
			wrapper.appendChild(makeSelect(modeOpts, action.mode ?? 'add', (val) => {
				action.mode = val;
				onChange();
			}));
		}

		renderFields();
		return wrapper;
	}

	function renderActionRow(action, btnData, onRemove, onChange) {
		if (!action._uid) action._uid = uid();

		const row = el('div', { class: 'nxt-action-row' });

		const typeOptions = ACTION_TYPES.map(t => ({ value: t.value, label: t.label }));
		const typeSel = makeSelect(typeOptions, action.type ?? '', (val) => {
			// Reset fields when type changes
			const keep = { type: val, _uid: action._uid };
			Object.keys(action).forEach(k => delete action[k]);
			Object.assign(action, keep);
			fieldsContainer.innerHTML = '';
			fieldsContainer.appendChild(renderActionFields(action, onChange));
			onChange();
		}, i18n.actionType);
		typeSel.className = 'nxt-type-select';

		const removeBtn = el('button', { type: 'button', class: 'button nxt-btn-remove', onclick: onRemove }, i18n.deleteAction);

		const fieldsContainer = el('div', { class: 'nxt-action-fields-wrap' });
		if (action.type) {
			fieldsContainer.appendChild(renderActionFields(action, onChange));
		}

		row.appendChild(el('div', { class: 'nxt-action-top' }, [typeSel, removeBtn]));
		row.appendChild(fieldsContainer);

		return row;
	}

	// ─── Render: Button card ──────────────────────────────────────────────────────

	function renderButtonCard(btnData, onDelete) {
		const card = el('div', { class: 'nxt-btn-card' });

		// Header
		const headerLabel  = el('input', { type: 'text', class: 'regular-text nxt-btn-label-input', placeholder: i18n.buttonLabel, value: btnData.label ?? '' });
		headerLabel.addEventListener('input', () => { btnData.label = headerLabel.value; });

		const confirmInput = el('input', { type: 'text', class: 'regular-text nxt-btn-confirm-input', placeholder: i18n.confirmText, value: btnData.confirm ?? '' });
		confirmInput.addEventListener('input', () => { btnData.confirm = confirmInput.value; });

		const deleteBtn = el('button', { type: 'button', class: 'button nxt-btn-delete', onclick: () => {
			if (window.confirm(i18n.confirmDelete)) onDelete();
		}}, i18n.deleteButton);

		card.appendChild(el('div', { class: 'nxt-card-header' }, [
			el('div', { class: 'nxt-card-header-inputs' }, [
				el('label', { class: 'nxt-field-label' }, i18n.buttonLabel),
				headerLabel,
				el('label', { class: 'nxt-field-label' }, i18n.confirmText),
				confirmInput,
			]),
			deleteBtn,
		]));

		// Actions list
		const actionsWrapper = el('div', { class: 'nxt-actions-wrapper' });
		card.appendChild(el('p', { class: 'nxt-section-label' }, i18n.actionsLabel));
		card.appendChild(actionsWrapper);

		function rebuildActions() {
			actionsWrapper.innerHTML = '';
			if (!btnData.actions || !btnData.actions.length) {
				actionsWrapper.appendChild(el('p', { class: 'nxt-empty-note' }, i18n.noActions));
			} else {
				btnData.actions.forEach((action, idx) => {
					const row = renderActionRow(action, btnData, () => {
						btnData.actions.splice(idx, 1);
						rebuildActions();
					}, () => {});
					actionsWrapper.appendChild(row);
				});
			}
		}

		rebuildActions();

		const addActionBtn = el('button', { type: 'button', class: 'button nxt-btn-add-action', onclick: () => {
			if (!Array.isArray(btnData.actions)) btnData.actions = [];
			btnData.actions.push({ type: '', _uid: uid() });
			rebuildActions();
		}}, i18n.addAction);

		card.appendChild(addActionBtn);

		return card;
	}

	// ─── Render: Full page ────────────────────────────────────────────────────────

	function render() {
		const root = document.getElementById('nxt-fbs-builder-root');
		if (!root) return;
		root.innerHTML = '';

		// Toolbar
		const addBtn = el('button', { type: 'button', class: 'button button-primary nxt-btn-new', onclick: () => {
			state.buttons.push({ id: uid(), label: '', confirm: '', actions: [] });
			render();
		}}, '+ ' + i18n.newButton);

		const saveBtn = el('button', { type: 'button', class: 'button button-primary nxt-btn-save', onclick: handleSave }, i18n.save);
		const statusMsg = el('span', { class: 'nxt-save-status', id: 'nxt-save-status' }, '');

		root.appendChild(el('div', { class: 'nxt-toolbar' }, [addBtn, saveBtn, statusMsg]));

		// Cards
		const list = el('div', { class: 'nxt-btn-list' });

		if (!state.buttons.length) {
			list.appendChild(el('p', { class: 'nxt-empty-note' }, 'Noch keine Buttons. Klicke "+ Neuer Button" um zu starten.'));
		}

		state.buttons.forEach((btnData, idx) => {
			const card = renderButtonCard(btnData, () => {
				state.buttons.splice(idx, 1);
				render();
			});
			list.appendChild(card);
		});

		root.appendChild(list);
	}

	function setStatus(msg, isError) {
		const el = document.getElementById('nxt-save-status');
		if (!el) return;
		el.textContent = msg;
		el.className = 'nxt-save-status' + (isError ? ' nxt-error' : ' nxt-success');
	}

	function handleSave() {
		if (state.saving) return;
		state.saving = true;
		setStatus(i18n.saving, false);

		saveButtons(state.buttons)
			.then(data => {
				if (data.success) {
					state.buttons = data.data.buttons;
					setStatus(i18n.saved, false);
					render();
				} else {
					setStatus(data.data?.message ?? i18n.errSave, true);
				}
			})
			.catch(() => setStatus(i18n.errSave, true))
			.finally(() => { state.saving = false; });
	}

	// ─── Boot ─────────────────────────────────────────────────────────────────────

	document.addEventListener('DOMContentLoaded', function () {
		const root = document.getElementById('nxt-fbs-builder-root');
		if (!root) return;

		root.textContent = i18n.loading;

		fetchBuilderData()
			.then(data => {
				if (!data.success) {
					root.textContent = i18n.errLoad;
					return;
				}
				state.boards  = data.data.boards  ?? [];
				state.users   = data.data.users   ?? [];
				state.buttons = data.data.buttons ?? [];
				render();
			})
			.catch(() => { root.textContent = i18n.errLoad; });
	});
})();
