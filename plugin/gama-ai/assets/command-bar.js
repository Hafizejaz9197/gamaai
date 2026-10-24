/* Gama AI command bar: type a change, then preview, publish, discard or undo. */
(function () {
	'use strict';
	var cfg = window.gamaAi;
	if (!cfg) {
		return;
	}
	var state = { draftId: cfg.draftId, previewUrl: cfg.previewUrl, snapshotId: null, busy: false };

	function el(tag, attrs, children) {
		var node = document.createElement(tag);
		Object.keys(attrs || {}).forEach(function (key) {
			if (key === 'text') {
				node.textContent = attrs[key];
			} else if (key === 'onclick') {
				node.addEventListener('click', attrs[key]);
			} else {
				node.setAttribute(key, attrs[key]);
			}
		});
		(children || []).forEach(function (child) {
			node.appendChild(child);
		});
		return node;
	}

	function api(path, body) {
		return fetch(cfg.restUrl + path, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
			body: JSON.stringify(body || {})
		}).then(function (response) {
			return response.json().catch(function () {
				return {};
			}).then(function (data) {
				if (!response.ok) {
					var message = data.message;
					if (!message && (response.status === 502 || response.status === 504)) {
						message = 'The AI took too long to answer, so the server stopped waiting. It may still finish: reload the preview in a minute. If this keeps happening, choose a faster AI model.';
					}
					var error = new Error(message || 'Something went wrong (' + response.status + ').');
					error.data = data.data || {};
					throw error;
				}
				return data;
			});
		}, function () {
			throw new Error('Could not reach the site. Check your connection and try again.');
		});
	}

	function usd(micros) {
		return '$' + (micros / 1000000).toFixed(micros < 10000 ? 4 : 2);
	}

	var launch = el('button', { id: 'gama-ai-launch', type: 'button', text: 'Gama AI' });
	var close = el('button', { class: 'g-close', type: 'button', 'aria-label': 'Close', text: '\u00d7' });
	var note = el('p', { class: 'g-note' });
	var input = el('textarea', {
		id: 'gama-ai-instruction',
		maxlength: '2000',
		placeholder: 'What should change? For example: Change the main heading to "Fresh flowers, delivered today".'
	});
	var send = el('button', { class: 'g-btn', type: 'button', text: 'Make changes' });
	var hint = el('span', { class: 'g-hint', text: 'Changes go to a draft first.' });
	var result = el('div', { class: 'g-result', role: 'status', 'aria-live': 'polite', hidden: '' });
	var panel = el('div', { id: 'gama-ai-panel', role: 'dialog', 'aria-label': 'Gama AI', hidden: '' }, [
		el('div', { class: 'g-head' }, [el('strong', { text: 'Gama AI' }), close]),
		note,
		el('label', { for: 'gama-ai-instruction', class: 'g-hint', text: 'Describe the change in plain words.' }),
		input,
		el('div', { class: 'g-row' }, [send, hint]),
		result
	]);

	function link(text, href, ghost) {
		return el('a', { class: 'g-btn' + (ghost ? ' g-ghost' : ''), href: href, text: text });
	}

	function button(text, handler, ghost) {
		return el('button', { class: 'g-btn' + (ghost ? ' g-ghost' : ''), type: 'button', text: text, onclick: handler });
	}

	function renderNote() {
		note.textContent = '';
		if (cfg.problem) {
			note.textContent = cfg.problem;
		} else if (cfg.onDraft) {
			note.appendChild(document.createTextNode('You are looking at the draft. Visitors still see the live page. '));
			note.appendChild(el('a', { href: cfg.pageUrl, text: 'Open the live page' }));
		} else if (state.draftId) {
			note.appendChild(document.createTextNode('This page has changes that are not published yet. '));
			note.appendChild(el('a', { href: state.previewUrl, text: 'Preview them' }));
		} else {
			note.textContent = 'Visitors see nothing until you publish.';
		}
		input.disabled = !!cfg.problem;
		send.disabled = !!cfg.problem;
	}

	function show(kind, lines, actions) {
		result.hidden = false;
		result.className = 'g-result' + (kind === 'error' ? ' g-error' : '');
		result.textContent = '';
		lines.forEach(function (line) {
			if (line) {
				result.appendChild(el('p', { class: line.cls || '', text: line.text || line }));
			}
		});
		if (actions && actions.length) {
			result.appendChild(el('div', { class: 'g-row' }, actions));
		}
	}

	function draftActions() {
		var actions = [];
		if (cfg.onDraft) {
			actions.push(button('Reload preview', function () {
				window.location.reload();
			}));
		} else if (state.previewUrl) {
			actions.push(link('Preview changes', state.previewUrl));
		}
		if (cfg.canPublish && state.draftId) {
			actions.push(button('Publish', publish));
		}
		if (state.draftId) {
			actions.push(button('Discard', discard, true));
		}
		return actions;
	}

	function setBusy(busy, label) {
		state.busy = busy;
		send.disabled = busy || !!cfg.problem;
		send.textContent = busy ? label : 'Make changes';
		Array.prototype.forEach.call(result.querySelectorAll('button'), function (b) {
			b.disabled = busy;
		});
	}

	function costLine(usage) {
		if (!usage || typeof usage.tokens !== 'number') {
			return null;
		}
		return { cls: 'g-cost', text: 'Used ' + usage.tokens.toLocaleString() + ' tokens (about ' + usd(usage.estimated_cost_micros || 0) + ').' };
	}

	function edit() {
		var instruction = input.value.trim();
		if (!instruction || state.busy) {
			return;
		}
		setBusy(true, 'Working\u2026 up to a minute');
		show('ok', ['Asking the AI and checking its changes\u2026']);
		api('pages/' + cfg.pageId + '/ai-edit', { instruction: instruction }).then(function (data) {
			setBusy(false);
			if (!data.operations || !data.operations.length) {
				show('ok', [data.summary || 'The AI made no changes.', 'Nothing was changed.', costLine(data.usage)], draftActions());
				return;
			}
			state.draftId = data.draft_id;
			state.previewUrl = data.preview_url;
			input.value = '';
			renderNote();
			var count = data.operations.length;
			show('ok', [data.summary, count + (count === 1 ? ' change' : ' changes') + ' saved to the draft.', costLine(data.usage)], draftActions());
		}, function (error) {
			setBusy(false);
			var lines = [error.message];
			if (error.data && error.data.summary) {
				lines.push('The AI said: ' + error.data.summary);
				lines.push('Nothing was changed. Try saying it differently.');
			}
			show('error', lines.concat([costLine(error.data && error.data.usage)]), draftActions());
		});
	}

	function publish() {
		if (state.busy || !window.confirm('Publish the draft? Visitors will see the changes straight away. You can undo it.')) {
			return;
		}
		setBusy(true, 'Publishing\u2026');
		api('drafts/' + state.draftId + '/publish').then(function (data) {
			setBusy(false);
			state.draftId = null;
			state.previewUrl = null;
			state.snapshotId = data.snapshot_id;
			cfg.onDraft = false;
			renderNote();
			show('ok', ['Published.'], [link('View the page', data.page_url || cfg.pageUrl), button('Undo', undo, true)]);
		}, failed);
	}

	function discard() {
		if (state.busy || !window.confirm('Throw away the unpublished changes?')) {
			return;
		}
		setBusy(true, 'Discarding\u2026');
		api('drafts/' + state.draftId + '/discard').then(function (data) {
			setBusy(false);
			state.draftId = null;
			state.previewUrl = null;
			if (cfg.onDraft) {
				window.location.href = data.page_url || cfg.pageUrl;
				return;
			}
			renderNote();
			show('ok', ['The draft was discarded. The live page did not change.']);
		}, failed);
	}

	function undo() {
		if (state.busy || !window.confirm('Put the page back the way it was before this publish?')) {
			return;
		}
		setBusy(true, 'Undoing\u2026');
		api('snapshots/' + state.snapshotId + '/rollback').then(function () {
			setBusy(false);
			state.snapshotId = null;
			show('ok', ['The page is back to how it was.'], [link('View the page', cfg.pageUrl)]);
		}, failed);
	}

	function failed(error) {
		setBusy(false);
		show('error', [error.message], draftActions());
	}

	function open(event) {
		if (event) {
			event.preventDefault();
		}
		panel.hidden = false;
		launch.hidden = true;
		input.focus();
	}

	launch.addEventListener('click', open);
	close.addEventListener('click', function () {
		panel.hidden = true;
		launch.hidden = false;
	});
	send.addEventListener('click', edit);
	input.addEventListener('keydown', function (event) {
		if (event.key === 'Enter' && (event.ctrlKey || event.metaKey)) {
			edit();
		}
	});

	function mount() {
		document.body.appendChild(launch);
		document.body.appendChild(panel);
		var item = document.querySelector('#wp-admin-bar-gama-ai > a');
		if (item) {
			item.addEventListener('click', open);
		}
		renderNote();
		if (cfg.onDraft) {
			open();
			show('ok', ['These are the unpublished changes.'], draftActions());
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', mount);
	} else {
		mount();
	}
}());
