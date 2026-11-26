/*
 * This file is part of TYPO3 CMS-based extension "unity_tracker" by ochorocho.
 */
// Markup triggers (.t3js-modal-trigger): the merge confirmation.
import '@typo3/backend/modal.js';
import Notification from '@typo3/backend/notification.js';
import '@ochorocho/unity-tracker/markdown-editor.js';

/**
 * Detail sheet page:
 * - forms [data-item-form] are sent in the background; the answer's HTML replaces [data-item-body],
 * - inline editing: [data-inline-start=key] swaps [data-inline-view=key] for [data-inline-field=key],
 * - [data-load-older] puts an older page of the timeline in front of [data-timeline],
 * - after a change the overview is told, it reloads once the sheet is closed.
 */
const root = document.querySelector('[data-item-detail]');

function announceChange() {
  if (window.top && window.top !== window) {
    window.top.postMessage({ actionName: 'unitytracker:item-changed' }, window.location.origin);
  }
}

function l10n(key) {
  try {
    return JSON.parse(root?.dataset.editorConfig || '{}').l10n?.[key] ?? key;
  } catch {
    return key;
  }
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

if (root) {
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

  root.addEventListener('submit', (event) => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement) || !form.matches('[data-item-form]')) {
      return;
    }
    event.preventDefault();
    submitInBackground(form, event.submitter);
  });
}
