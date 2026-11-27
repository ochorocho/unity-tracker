/*
 * This file is part of TYPO3 CMS-based extension "unity_tracker" by ochorocho.
 */
// Markup triggers (.t3js-modal-trigger): the merge confirmation.
import '@typo3/backend/modal.js';
import Notification from '@typo3/backend/notification.js';
import ImmediateAction from '@typo3/backend/action-button/immediate-action.js';
import '@ochorocho/unity-tracker/markdown-editor.js';

/**
 * Detail sheet page:
 * - forms [data-item-form] are sent in the background; the answer's HTML replaces [data-item-body],
 * - inline editing: [data-inline-start=key] swaps [data-inline-view=key] for [data-inline-field=key],
 * - [data-load-older] puts an older page of the timeline in front of [data-timeline],
 * - a task list checkbox [data-task-index] sends the [data-task-form] of its [data-task-scope],
 * - user fields [data-user-suggest] fill their <datalist> with the provider's users as you type,
 * - after a change the overview is told, it reloads once the sheet is closed,
 * - live updates ([data-live-url], EXT:mercure): a change made in another sheet of the item re-renders the body,
 *   or offers a reload while something is being edited. The sheet's own changes carry its origin.
 */
const root = document.querySelector('[data-item-detail]');
// Not crypto.randomUUID(): it exists in secure contexts only (the backend may run on plain http).
const origin = Array.from(crypto.getRandomValues(new Uint8Array(16)), (byte) => byte.toString(16).padStart(2, '0')).join('');

function announceChange() {
  if (window.top && window.top !== window) {
    window.top.postMessage({ actionName: 'unitytracker:item-changed' }, window.location.origin);
  }
}

function editorConfig() {
  try {
    return JSON.parse(root?.dataset.editorConfig || '{}');
  } catch {
    return {};
  }
}

function l10n(key) {
  return editorConfig().l10n?.[key] ?? key;
}

let suggestTimer;
function suggestUsers(input) {
  clearTimeout(suggestTimer);
  suggestTimer = setTimeout(async () => {
    const config = editorConfig();
    const list = input.list;
    if (!config.suggestUrl || !list) {
      return;
    }
    const url = new URL(config.suggestUrl, window.location.href);
    Object.entries(config.reference ?? {}).forEach(([key, value]) => url.searchParams.set(key, String(value)));
    url.searchParams.set('kind', 'user');
    url.searchParams.set('q', input.value.trim());
    try {
      const response = await fetch(url, { headers: { Accept: 'application/json' } });
      const items = (await response.json()).items ?? [];
      list.replaceChildren(...items.map((item) => {
        const option = document.createElement('option');
        option.value = item.value;
        option.label = item.label;
        return option;
      }));
    } catch {
      // Suggestions are a convenience.
    }
  }, 250);
}

function setEditing(name, editing) {
  const key = CSS.escape(name);
  root.querySelectorAll(`[data-inline-view="${key}"], [data-inline-start="${key}"]`).forEach((element) => { element.hidden = editing; });
  const fields = root.querySelectorAll(`[data-inline-field="${key}"]`);
  fields.forEach((element) => { element.hidden = !editing; });
  if (editing) {
    const first = fields[0];
    (first.matches('input, textarea') ? first : first.querySelector('unity-tracker-markdown-editor, input:not([type="hidden"]), textarea'))?.focus();
    return;
  }
  fields.forEach((field) => {
    const elements = field.matches('input') ? [field] : field.querySelectorAll('input:not([type="hidden"])');
    elements.forEach((input) => { input.value = input.defaultValue; });
    field.querySelectorAll('unity-tracker-markdown-editor').forEach((editor) => editor.reset());
  });
  root.querySelector(`[data-inline-start="${key}"]`)?.focus();
}

function setBusy(form, busy) {
  for (const element of form.elements) {
    if (element.type === 'submit') {
      element.disabled = busy;
    }
  }
  form.setAttribute('aria-busy', busy ? 'true' : 'false');
}

async function submitInBackground(form, submitter) {
  const data = new FormData(form, submitter);
  data.set('ajax', '1');
  data.set('origin', origin);
  setBusy(form, true);
  try {
    // Not form.action: the submit buttons are named "action" and shadow that property.
    const response = await fetch(form.getAttribute('action'), { method: 'POST', body: data, headers: { Accept: 'application/json' } });
    const result = await response.json();
    if (!result.ok) {
      Notification.error(result.message);
      return;
    }
    Notification.success(result.message);
    announceChange();
    if (result.html) {
      root.querySelector('[data-item-body]').innerHTML = result.html;
    }
  } catch {
    Notification.error(l10n('editor.requestFailed'));
  } finally {
    if (form.isConnected) {
      setBusy(form, false);
    }
  }
}

async function loadOlder(button) {
  button.disabled = true;
  try {
    const url = new URL(button.dataset.loadOlder, window.location.href);
    url.searchParams.set('before', button.dataset.cursor);
    const response = await fetch(url, { headers: { Accept: 'application/json' } });
    const result = await response.json();
    if (!response.ok) {
      Notification.error(result.error || l10n('editor.requestFailed'));
      return;
    }
    root.querySelector('[data-timeline]').insertAdjacentHTML('afterbegin', result.html);
    if (result.cursor) {
      button.dataset.cursor = result.cursor;
    } else {
      button.remove();
    }
  } catch {
    Notification.error(l10n('editor.requestFailed'));
  } finally {
    button.disabled = false;
  }
}

/**
 * Input that a re-render would throw away: an open inline field or a Markdown editor with text.
 */
function isEditing() {
  const openField = [...root.querySelectorAll('[data-inline-field]')].some((field) => !field.hidden);
  // Hidden editors (closed inline fields) hold the saved text, the visible ones (new comment) start empty.
  const typed = [...root.querySelectorAll('textarea')]
    .some((textarea) => !textarea.closest('[hidden]') && textarea.value.trim() !== textarea.defaultValue.trim());
  return openField || typed || root.querySelector('form[aria-busy="true"]') !== null;
}

async function onForeignChange() {
  if (isEditing()) {
    Notification.info(l10n('item.live.changed'), '', 0, [
      { label: l10n('item.live.reload'), action: new ImmediateAction(() => window.location.reload()) },
    ]);
    return;
  }
  try {
    const response = await fetch(window.location.href, { headers: { Accept: 'text/html' } });
    const page = new DOMParser().parseFromString(await response.text(), 'text/html');
    const body = page.querySelector('[data-item-body]');
    if (!response.ok || !body || isEditing()) {
      return;
    }
    root.querySelector('[data-item-body]').innerHTML = body.innerHTML;
    Notification.info(l10n('item.live.updated'));
  } catch {
    // The next change or a reload shows the new state.
  }
}

function listen(url) {
  const source = new EventSource(url, { withCredentials: true });
  source.addEventListener('message', (event) => {
    let update;
    try {
      update = JSON.parse(event.data);
    } catch {
      return;
    }
    if (update.type === 'item-changed' && update.origin !== origin) {
      onForeignChange();
    }
  });
  window.addEventListener('pagehide', () => source.close());
}

if (root) {
  if (root.dataset.liveUrl) {
    listen(root.dataset.liveUrl);
  }

  if (root.hasAttribute('data-item-changed')) {
    announceChange();
  }

  root.addEventListener('click', (event) => {
    const start = event.target.closest('[data-inline-start]');
    const cancel = event.target.closest('[data-inline-cancel]');
    const older = event.target.closest('[data-load-older]');
    if (older) {
      loadOlder(older);
    } else if (start) {
      setEditing(start.dataset.inlineStart, true);
    } else if (cancel) {
      setEditing(cancel.dataset.inlineCancel, false);
    }
  });

  root.addEventListener('input', (event) => {
    if (event.target.matches('input[data-user-suggest]')) {
      suggestUsers(event.target);
    }
  });

  root.addEventListener('change', (event) => {
    const checkbox = event.target.closest('input[data-task-index]');
    const scope = checkbox?.closest('[data-task-scope]');
    const form = scope?.querySelector('[data-task-form]');
    if (!form) {
      return;
    }
    form.elements.index.value = checkbox.dataset.taskIndex;
    form.elements.checked.value = checkbox.checked ? '1' : '';
    // One change at a time: the answer re-renders the text with the new state.
    scope.querySelectorAll('input[data-task-index]').forEach((input) => { input.disabled = true; });
    submitInBackground(form).finally(() => {
      if (checkbox.isConnected) {
        checkbox.checked = !checkbox.checked;
        scope.querySelectorAll('input[data-task-index]').forEach((input) => { input.disabled = false; });
      }
    });
  });

  root.addEventListener('submit', (event) => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement) || !form.matches('[data-item-form]')) {
      return;
    }
    event.preventDefault();
    submitInBackground(form, event.submitter);
  });
}
