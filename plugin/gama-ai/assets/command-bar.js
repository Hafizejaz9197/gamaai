/* Gama AI command bar: type a change, then preview, publish, discard or undo. */
(function () {
	'use strict';
	var cfg = window.gamaAi;
	if (!cfg) {
		return;
	}
	var state = { draftId: cfg.draftId, previewUrl: cfg.previewUrl, snapshotId: null, busy: false, target: null, undoSteps: 0, retryTimer: null, image: null, tasks: [], tab: 'page', fixing: false };
	// Screenshots are shrunk in the browser before they are sent: the AI
	// reads a 1280 px image as well as a bigger one, for far fewer tokens.
	var IMAGE_MAX_SIDE = 1280;

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

	function api(path, body, method) {
		method = method || 'POST';
		var options = {
			method: method,
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce }
		};
		if (method !== 'GET') {
			options.body = JSON.stringify(body || {});
		}
		return fetch(cfg.restUrl + path, options).then(function (response) {
			return response.json().catch(function () {
				return {};
			}).then(function (data) {
				if (!response.ok) {
					var message = data.message;
					if (!message && (response.status === 502 || response.status === 504)) {
						message = 'The AI took too long to answer, so the server stopped waiting. It may still finish: reload the preview in a minute. If this keeps happening, choose a faster AI model.';
					}
					var error = new Error(message || 'Something went wrong (' + response.status + ').');
					error.status = response.status;
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
	var pick = el('button', { class: 'g-btn g-ghost', type: 'button', text: 'Pick a section', hidden: '' });
	var save = el('button', { class: 'g-btn g-ghost', type: 'button', text: 'Save as task', title: 'Keep this as a task. The AI works on it only when you press Fix.' });
	var tasksBox = el('div', { class: 'g-tasks' });
	var picked = el('p', { class: 'g-hint g-picked', hidden: '' });
	var file = el('input', { type: 'file', accept: 'image/png,image/jpeg,image/webp,image/gif', hidden: '' });
	var attach = el('button', { class: 'g-btn g-ghost', type: 'button', text: 'Add image', title: 'Add a screenshot or picture that shows what you mean. You can also paste one into the box.' });
	var attached = el('div', { class: 'g-attached', hidden: '' });
	var result = el('div', { class: 'g-result', role: 'status', 'aria-live': 'polite', hidden: '' });
	var panel = el('div', { id: 'gama-ai-panel', role: 'dialog', 'aria-label': 'Gama AI', hidden: '' }, [
		el('div', { class: 'g-head' }, [el('strong', { text: 'Gama AI' }), close]),
		note,
		el('label', { for: 'gama-ai-instruction', class: 'g-hint', text: 'Describe the change in plain words.' }),
		input,
		attached,
		picked,
		el('div', { class: 'g-row' }, [send, save, pick, attach, file, hint]),
		result,
		tasksBox
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
		save.disabled = !!cfg.problem;
		attach.disabled = !!cfg.problem;
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
		if (state.draftId && state.undoSteps > 0) {
			actions.push(button('Undo last change', undoChange, true));
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

	// Section picker. Avada renders each top-level container as a
	// .fusion-fullwidth box in the post content; cfg.containers lists their
	// ids in the same order, or is null when the order cannot be trusted.
	function sectionBoxes() {
		var holders = document.querySelectorAll('.post-content');
		for (var i = 0; i < holders.length; i++) {
			var boxes = Array.prototype.filter.call(holders[i].children, function (child) {
				return child.classList.contains('fusion-fullwidth');
			});
			if (boxes.length) {
				return boxes;
			}
		}
		return [];
	}

	// Elements Avada rendered, marked by the plugin with their ids while
	// the page on screen is the layout edits change.
	function marked() {
		return cfg.containers ? document.querySelectorAll('[data-gama-id]') : [];
	}

	var TYPE_NAMES = {
		fusion_builder_container: 'section', fusion_builder_row: 'row', fusion_builder_column: 'column',
		fusion_builder_row_inner: 'inner row', fusion_builder_column_inner: 'inner column', fusion_title: 'heading',
		fusion_text: 'text', fusion_button: 'button', fusion_imageframe: 'image', fusion_separator: 'divider',
		fusion_checklist: 'list', fusion_fontawesome: 'icon', fusion_gallery: 'gallery'
	};

	function elementLabel(node) {
		var type = node.getAttribute('data-gama-type') || '';
		var name = TYPE_NAMES[type] || type.replace(/^(fusion_builder_|fusion_|awb_)/, '').replace(/_/g, ' ');
		var text = (node.innerText || '').replace(/\s+/g, ' ').trim();
		return name + (text ? ' \u201C' + (text.length > 30 ? text.slice(0, 30) + '\u2026' : text) + '\u201D' : '');
	}

	function canPick() {
		if (marked().length) {
			return true;
		}
		return !!(cfg.containers && cfg.containers.length > 1 && sectionBoxes().length === cfg.containers.length);
	}

	function renderPicked() {
		picked.textContent = '';
		picked.hidden = !state.target;
		if (state.target) {
			picked.appendChild(document.createTextNode('Pinned to ' + state.target.label + '. '));
			var parent = state.target.box.parentElement && state.target.box.parentElement.closest('[data-gama-id]');
			if (parent) {
				picked.appendChild(button('Bigger area', function () {
					setTarget({ id: parent.getAttribute('data-gama-id'), box: parent, label: elementLabel(parent) });
				}, true));
			}
			picked.appendChild(button('Clear', function () {
				setTarget(null);
			}, true));
		}
		pick.hidden = !canPick() || !!cfg.problem;
		pick.textContent = marked().length ? '+ Pin' : 'Pick a section';
	}

	function setTarget(target) {
		state.target = target;
		Array.prototype.forEach.call(document.querySelectorAll('.gama-ai-picked'), function (box) {
			box.classList.remove('gama-ai-picked');
		});
		if (target) {
			target.box.classList.add('gama-ai-picked');
		}
		renderPicked();
	}

	function startPinning() {
		panel.hidden = true;
		document.body.classList.add('gama-ai-pinning');
		var tip = el('div', { id: 'gama-ai-tip', text: 'Click the part of the page this is about. Press Esc to cancel.' });
		document.body.appendChild(tip);
		var hover = null;

		function target(event) {
			return event.target && event.target.closest ? event.target.closest('[data-gama-id]') : null;
		}
		function mark(node) {
			if (hover) {
				hover.classList.remove('gama-ai-hover');
			}
			hover = node;
			if (hover) {
				hover.classList.add('gama-ai-hover');
			}
		}
		function over(event) {
			mark(target(event));
		}
		function stop() {
			mark(null);
			document.body.classList.remove('gama-ai-pinning');
			tip.remove();
			document.removeEventListener('mouseover', over, true);
			document.removeEventListener('click', choose, true);
			document.removeEventListener('keydown', onKey, true);
			panel.hidden = false;
		}
		function choose(event) {
			var node = target(event);
			if (!node) {
				return;
			}
			event.preventDefault();
			event.stopPropagation();
			stop();
			setTarget({ id: node.getAttribute('data-gama-id'), box: node, label: elementLabel(node) });
			input.focus();
		}
		function onKey(event) {
			if (event.key === 'Escape') {
				stop();
			}
		}
		document.addEventListener('mouseover', over, true);
		document.addEventListener('click', choose, true);
		document.addEventListener('keydown', onKey, true);
	}

	function startPicking() {
		if (marked().length) {
			startPinning();
			return;
		}
		var boxes = sectionBoxes();
		if (!canPick()) {
			return;
		}
		panel.hidden = true;
		document.body.classList.add('gama-ai-picking');
		var tip = el('div', { id: 'gama-ai-tip', text: 'Click the section to work on. Press Esc to cancel.' });
		document.body.appendChild(tip);

		function stop() {
			document.body.classList.remove('gama-ai-picking');
			tip.remove();
			boxes.forEach(function (box) {
				box.removeEventListener('click', choose, true);
			});
			document.removeEventListener('keydown', onKey, true);
			panel.hidden = false;
		}
		function choose(event) {
			event.preventDefault();
			event.stopPropagation();
			var index = boxes.indexOf(event.currentTarget);
			stop();
			setTarget({ id: cfg.containers[index], box: boxes[index], label: 'section ' + (index + 1) });
			input.focus();
		}
		function onKey(event) {
			if (event.key === 'Escape') {
				stop();
			}
		}
		boxes.forEach(function (box) {
			box.addEventListener('click', choose, true);
		});
		document.addEventListener('keydown', onKey, true);
	}

	// After a change the page on screen no longer matches the draft, so ids
	// from the picker could point at the wrong section until it is reloaded.
	function stopPickingUntilReload() {
		setTarget(null);
		cfg.containers = null;
		renderPicked();
		renderPins();
	}

	// Tasks: requests pinned to the page, fixed by the AI only on Fix.
	var STATUS_NAMES = { open: 'Open', review: 'Ready to review', done: 'Done' };

	function loadTasks() {
		return api('tasks', null, 'GET').then(function (data) {
			state.tasks = data.tasks || [];
			renderTasks();
			renderPins();
		}, function () {});
	}

	function pageTasks() {
		return state.tasks.filter(function (task) {
			return task.page_id === cfg.pageId;
		});
	}

	function replaceTask(task) {
		state.tasks = state.tasks.map(function (t) {
			return t.id === task.id ? task : t;
		});
	}

	function saveTask() {
		var instruction = input.value.trim();
		if (!instruction || state.busy) {
			return;
		}
		var body = { instruction: instruction };
		if (state.target) {
			body.target = state.target.id;
		}
		setBusy(true, 'Working\u2026');
		api('pages/' + cfg.pageId + '/tasks', body).then(function (data) {
			setBusy(false);
			state.tasks.unshift(data.task);
			input.value = '';
			setTarget(null);
			state.tab = 'page';
			renderTasks();
			renderPins();
			show('ok', ['Task #' + data.task.id + ' saved. Press Fix when you want the AI to work on it.']);
		}, failed);
	}

	function fixTask(task) {
		if (state.busy) {
			return Promise.resolve(false);
		}
		setBusy(true, 'Fixing #' + task.id + '\u2026');
		show('ok', ['The AI is working on task #' + task.id + '\u2026']);
		return api('tasks/' + task.id + '/fix').then(function (data) {
			setBusy(false);
			if (data.task) {
				replaceTask(data.task);
			}
			var how = data.quick ? { cls: 'g-cost', text: 'Done without the AI, so no tokens were used.' } : costLine(data.usage);
			if (data.operations && data.operations.length) {
				state.draftId = data.draft_id;
				state.previewUrl = data.preview_url;
				state.undoSteps = data.undo_steps || 0;
				stopPickingUntilReload();
				renderNote();
				show('ok', ['#' + task.id + ': ' + (data.summary || 'Changed.'), 'Saved to the draft.', how], draftActions());
			} else {
				show('ok', ['#' + task.id + ': ' + (data.summary || 'The AI made no changes.'), 'Nothing was changed.', how], draftActions());
			}
			renderTasks();
			return true;
		}, function (error) {
			setBusy(false);
			if (error.data && error.data.task) {
				replaceTask(error.data.task);
			}
			var lines = ['#' + task.id + ': ' + error.message];
			if (error.data && error.data.summary) {
				lines.push('The AI said: ' + error.data.summary);
			}
			show('error', lines.concat([costLine(error.data && error.data.usage)]), draftActions());
			renderTasks();
			if (error.status === 429) {
				retryLater(error.data && error.data.retry_after ? error.data.retry_after : 30);
			}
			return false;
		});
	}

	// One task after another, stopping at the first failure so nothing is
	// sent again on its own.
	function fixAll() {
		var queue = pageTasks().filter(function (task) {
			return task.status === 'open';
		}).reverse();
		function next() {
			var task = queue.shift();
			if (!task) {
				return;
			}
			fixTask(task).then(function (ok) {
				if (ok) {
					next();
				}
			});
		}
		next();
	}

	function setTaskStatus(task, status) {
		api('tasks/' + task.id, { status: status }).then(function (data) {
			replaceTask(data.task);
			renderTasks();
			renderPins();
		}, failed);
	}

	function deleteTask(task) {
		if (!window.confirm('Delete task #' + task.id + '?')) {
			return;
		}
		api('tasks/' + task.id, {}, 'DELETE').then(function () {
			state.tasks = state.tasks.filter(function (t) {
				return t.id !== task.id;
			});
			renderTasks();
			renderPins();
		}, failed);
	}

	function renderTasks() {
		tasksBox.textContent = '';
		var mine = pageTasks();
		if (!state.tasks.length) {
			return;
		}
		var list = state.tab === 'page' ? mine : state.tasks;
		var tabs = el('div', { class: 'g-row g-tabs' }, [
			button('This page (' + mine.length + ')', function () {
				state.tab = 'page';
				renderTasks();
			}, state.tab !== 'page'),
			button('All (' + state.tasks.length + ')', function () {
				state.tab = 'all';
				renderTasks();
			}, state.tab !== 'all')
		]);
		var open = mine.filter(function (task) {
			return task.status === 'open';
		}).length;
		if (open > 1 && !cfg.problem) {
			tabs.appendChild(button('Fix all open (' + open + ')', fixAll));
		}
		tasksBox.appendChild(el('strong', { class: 'g-tasks-head', text: 'Tasks' }));
		tasksBox.appendChild(tabs);
		list.forEach(function (task) {
			var actions = [];
			var here = task.page_id === cfg.pageId;
			if (here && task.status === 'open' && !cfg.problem) {
				actions.push(button('Fix', function () {
					fixTask(task);
				}));
			}
			if (here && task.status === 'review' && state.previewUrl && !cfg.onDraft) {
				actions.push(link('Preview', state.previewUrl, true));
			}
			if (!here) {
				actions.push(link('Open page', task.page_url, true));
			}
			if (task.status !== 'done') {
				actions.push(button('Done', function () {
					setTaskStatus(task, 'done');
				}, true));
			} else {
				actions.push(button('Reopen', function () {
					setTaskStatus(task, 'open');
				}, true));
			}
			actions.push(button('Delete', function () {
				deleteTask(task);
			}, true));
			tasksBox.appendChild(el('div', { class: 'g-task g-' + task.status, id: 'gama-ai-task-' + task.id }, [
				el('div', { class: 'g-task-top' }, [
					el('span', { class: 'g-num', text: '#' + task.id }),
					el('span', { class: 'g-status', text: STATUS_NAMES[task.status] || task.status })
				]),
				el('p', { class: 'g-task-text', text: task.instruction }),
				el('p', { class: 'g-hint', text: (task.pin ? task.pin.label + (task.pin.snippet ? ' \u201C' + task.pin.snippet.slice(0, 30) + '\u201D' : '') : 'Whole page') + (here ? '' : ' \u00b7 ' + task.page_title) }),
				task.summary ? el('p', { class: 'g-hint', text: 'AI: ' + task.summary }) : el('span'),
				task.error && task.status === 'open' ? el('p', { class: 'g-hint g-bad', text: task.error }) : el('span'),
				el('div', { class: 'g-row' }, actions)
			]));
		});
	}

	// Numbered pins on the page for this page's tasks that are not done.
	function renderPins() {
		Array.prototype.forEach.call(document.querySelectorAll('.gama-ai-pin'), function (pin) {
			pin.remove();
		});
		if (!marked().length) {
			return;
		}
		pageTasks().forEach(function (task) {
			if (task.status === 'done' || !task.pin) {
				return;
			}
			var node = document.querySelector('[data-gama-id="' + task.pin.target + '"]');
			if (!node || node.getAttribute('data-gama-type') !== task.pin.type) {
				return;
			}
			var box = node.getBoundingClientRect();
			var pin = el('button', { class: 'gama-ai-pin g-' + task.status, type: 'button', text: String(task.id), title: task.instruction });
			pin.style.top = (box.top + window.scrollY) + 'px';
			pin.style.left = (box.left + window.scrollX) + 'px';
			pin.addEventListener('click', function () {
				open();
				state.tab = 'page';
				renderTasks();
				var card = document.getElementById('gama-ai-task-' + task.id);
				if (card) {
					card.scrollIntoView({ block: 'nearest' });
				}
			});
			document.body.appendChild(pin);
		});
	}

	function setImage(dataUrl) {
		state.image = dataUrl;
		attached.textContent = '';
		attached.hidden = !dataUrl;
		if (dataUrl) {
			attached.appendChild(el('img', { src: dataUrl, alt: 'Attached image' }));
			attached.appendChild(el('span', { class: 'g-hint', text: 'The AI will look at this image with your request.' }));
			attached.appendChild(button('Remove', function () {
				setImage(null);
			}, true));
		}
	}

	function readImage(blob) {
		if (!blob || !/^image\//.test(blob.type)) {
			return;
		}
		var url = URL.createObjectURL(blob);
		var img = new Image();
		img.onload = function () {
			var scale = Math.min(1, IMAGE_MAX_SIDE / Math.max(img.naturalWidth, img.naturalHeight));
			var canvas = document.createElement('canvas');
			canvas.width = Math.max(1, Math.round(img.naturalWidth * scale));
			canvas.height = Math.max(1, Math.round(img.naturalHeight * scale));
			var ctx = canvas.getContext('2d');
			ctx.fillStyle = '#fff'; // JPEG has no transparency.
			ctx.fillRect(0, 0, canvas.width, canvas.height);
			ctx.drawImage(img, 0, 0, canvas.width, canvas.height);
			URL.revokeObjectURL(url);
			setImage(canvas.toDataURL('image/jpeg', 0.85));
		};
		img.onerror = function () {
			URL.revokeObjectURL(url);
			show('error', ['That file could not be read as an image.']);
		};
		img.src = url;
	}

	function contextLine(context) {
		if (!context || typeof context.page_bytes !== 'number') {
			return null;
		}
		var bytes = context.page_bytes + context.settings_bytes;
		return {
			cls: 'g-cost',
			text: 'Sent ' + (bytes / 1024).toFixed(1) + ' KB of page context (' + (context.scope !== null && context.scope !== undefined ? 'one section' : 'whole page') + ', ' + context.settings_count + ' settings).'
		};
	}

	function retryLater(seconds) {
		clearInterval(state.retryTimer);
		var left = Math.max(1, Math.ceil(seconds));
		var again = button('Try again in ' + left + 's', edit);
		again.disabled = true;
		result.appendChild(el('div', { class: 'g-row' }, [again]));
		state.retryTimer = setInterval(function () {
			left -= 1;
			if (left <= 0) {
				clearInterval(state.retryTimer);
				again.disabled = false;
				again.textContent = 'Try again';
				return;
			}
			again.textContent = 'Try again in ' + left + 's';
		}, 1000);
	}

	function undoChange() {
		if (state.busy || !state.draftId) {
			return;
		}
		setBusy(true, 'Undoing\u2026');
		api('drafts/' + state.draftId + '/undo').then(function (data) {
			setBusy(false);
			state.undoSteps = data.undo_steps;
			stopPickingUntilReload();
			show('ok', ['Undid the last change in the draft. The live page did not change.'], draftActions());
		}, failed);
	}

	function edit() {
		var instruction = input.value.trim();
		if (!instruction || state.busy) {
			if (!instruction && state.image) {
				show('error', ['Say in words what to change; the image only shows what you mean.']);
			}
			return;
		}
		clearInterval(state.retryTimer);
		setBusy(true, 'Working\u2026');
		show('ok', ['Checking the request\u2026']);
		var body = { instruction: instruction };
		if (state.target) {
			body.target = state.target.id;
		}
		if (state.image) {
			body.image = state.image;
		}
		api('pages/' + cfg.pageId + '/ai-edit', body).then(function (data) {
			setBusy(false);
			var how = data.quick
				? { cls: 'g-cost', text: 'Done without the AI, so no tokens were used.' }
				: costLine(data.usage);
			if (!data.operations || !data.operations.length) {
				show('ok', [data.summary || 'The AI made no changes.', data.quick ? '' : 'Nothing was changed.', how, contextLine(data.context)], draftActions());
				return;
			}
			state.draftId = data.draft_id;
			state.previewUrl = data.preview_url;
			state.undoSteps = data.undo_steps || 0;
			input.value = '';
			setImage(null);
			stopPickingUntilReload();
			renderNote();
			var count = data.operations.length;
			show('ok', [data.summary, count + (count === 1 ? ' change' : ' changes') + ' saved to the draft.', how, contextLine(data.context)], draftActions());
		}, function (error) {
			setBusy(false);
			var lines = [error.message];
			if (error.data && error.data.summary) {
				lines.push('The AI said: ' + error.data.summary);
				lines.push('Nothing was changed. Try saying it differently.');
			}
			if (error.status === 429) {
				lines.push('Nothing was changed. Your request was not sent again automatically.');
			}
			show('error', lines.concat([costLine(error.data && error.data.usage), contextLine(error.data && error.data.context)]), draftActions());
			if (error.status === 429) {
				retryLater(error.data && error.data.retry_after ? error.data.retry_after : 30);
			}
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
			loadTasks();
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
			loadTasks();
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
	save.addEventListener('click', saveTask);
	pick.addEventListener('click', startPicking);
	attach.addEventListener('click', function () {
		file.click();
	});
	file.addEventListener('change', function () {
		readImage(file.files && file.files[0]);
		file.value = '';
	});
	input.addEventListener('paste', function (event) {
		var items = (event.clipboardData && event.clipboardData.items) || [];
		for (var i = 0; i < items.length; i++) {
			if (items[i].kind === 'file' && /^image\//.test(items[i].type)) {
				event.preventDefault();
				readImage(items[i].getAsFile());
				return;
			}
		}
	});
	input.addEventListener('dragover', function (event) {
		event.preventDefault();
	});
	input.addEventListener('drop', function (event) {
		var files = event.dataTransfer && event.dataTransfer.files;
		if (files && files.length && /^image\//.test(files[0].type)) {
			event.preventDefault();
			readImage(files[0]);
		}
	});
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
		renderPicked();
		loadTasks();
		window.addEventListener('resize', renderPins);
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
