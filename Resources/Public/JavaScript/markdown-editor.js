/*
 * This file is part of TYPO3 CMS-based extension "unity_tracker" by ochorocho.
 */
import { LitElement, html, nothing } from 'lit';
import { unsafeHTML } from 'lit/directives/unsafe-html.js';
import { EMOJI } from '@ochorocho/unity-tracker/emoji.js';

const REMOTE_DELAY = 200;
const MAX_LOCAL = 10;
const MIRROR_STYLES = [
  'boxSizing', 'width', 'overflowX', 'overflowY', 'borderTopWidth', 'borderRightWidth', 'borderBottomWidth',
  'borderLeftWidth', 'paddingTop', 'paddingRight', 'paddingBottom', 'paddingLeft', 'fontStyle', 'fontVariant',
  'fontWeight', 'fontSize', 'lineHeight', 'fontFamily', 'textAlign', 'textTransform', 'textIndent', 'letterSpacing',
  'wordSpacing', 'tabSize',
];
let instanceCounter = 0;

/**
 * Markdown editor like GitLab's: Write/Preview, a formatting toolbar, shortcuts and suggestions.
 *
 * <unity-tracker-markdown-editor name="body" value="…" rows="5" form="…" label="…">
 *
 * Renders a real <textarea name> into the light DOM, so it is submitted with its form.
 * Preview and remote suggestions use the settings in [data-item-detail][data-editor-config]:
 * the provider renders the preview (GitLab/GitHub flavoured, sanitised server side).
 *
 * Suggestions: "@" users, "#" issues (GitHub: and pull requests), "!" merge requests (GitLab),
 * "~" labels (GitLab), ":" emoji. Shortcuts: Ctrl/⌘+B, +I, +K, +Enter submits.
 */
export class MarkdownEditor extends LitElement {
  static properties = {
    name: { type: String },
    value: { type: String },
    rows: { type: Number },
    form: { type: String },
    label: { type: String },
    mode: { state: true },
    preview: { state: true },
    suggestions: { state: true },
    activeIndex: { state: true },
    popupPosition: { state: true },
  };

  constructor() {
    super();
    this.name = '';
    this.value = '';
    this.rows = 5;
    this.form = '';
    this.label = '';
    this.mode = 'write';
    this.preview = { state: 'idle', html: '', text: null };
    this.suggestions = [];
    this.activeIndex = 0;
    this.popupPosition = { top: 0, left: 0 };
    this.trigger = null;
    this.requestCounter = 0;
    this.suggestionCache = new Map();
    this.uid = `unitytracker-editor-${++instanceCounter}`;
  }

  connectedCallback() {
    super.connectedCallback();
    this.initialValue = this.value ?? '';
  }

  createRenderRoot() {
    return this;
  }

  get config() {
    if (!this.cachedConfig) {
      try {
        this.cachedConfig = JSON.parse(this.closest('[data-item-detail]')?.dataset.editorConfig || '{}');
      } catch {
        this.cachedConfig = {};
      }
    }
    return this.cachedConfig;
  }

  get textarea() {
    return this.querySelector('textarea');
  }

  t(key) {
    return this.config.l10n?.[key] ?? key;
  }

  /** Back to the value it was rendered with (cancelled inline editing). */
  reset() {
    this.value = this.initialValue;
    this.mode = 'write';
    this.closeSuggestions();
    if (this.textarea) {
      this.textarea.value = this.initialValue;
    }
  }

  focus() {
    this.updateComplete.then(() => this.textarea?.focus());
  }

  // --- Formatting ---------------------------------------------------------------------------------------------

  get tools() {
    return [
      { key: 'bold', text: 'B', style: 'font-weight: bold', run: () => this.wrap('**', '**', 'bold') },
      { key: 'italic', text: 'I', style: 'font-style: italic', run: () => this.wrap('_', '_', 'italic') },
      { key: 'strikethrough', text: 'S', style: 'text-decoration: line-through', run: () => this.wrap('~~', '~~', 'text') },
      { key: 'heading', text: 'H', run: () => this.prefixLines(() => '### ') },
      { key: 'quote', text: '❝', run: () => this.prefixLines(() => '> ') },
      { key: 'code', text: '</>', run: () => this.code() },
      { key: 'link', text: '🔗', run: () => this.link() },
      { key: 'bulletList', text: '•', run: () => this.prefixLines(() => '- ') },
      { key: 'numberedList', text: '1.', run: () => this.prefixLines((index) => `${index + 1}. `) },
      { key: 'taskList', text: '☑', run: () => this.prefixLines(() => '- [ ] ') },
      { key: 'table', text: '▦', run: () => this.insertBlock('| Header | Header |\n| ------ | ------ |\n| Cell   | Cell   |') },
      { key: 'collapsible', text: '▸', run: () => this.wrap('<details><summary>Details</summary>\n\n', '\n\n</details>', 'content') },
    ];
  }

  /** Replaces the selection; execCommand keeps the browser's undo history where it is supported. */
  replaceSelection(text, selectFrom = null, selectTo = null) {
    const textarea = this.textarea;
    const start = textarea.selectionStart;
    textarea.focus();
    // insertText with '' does not just delete the selection in Chrome: removals use "delete".
    const done = text === ''
      ? start === textarea.selectionEnd || document.execCommand?.('delete')
      : document.execCommand?.('insertText', false, text);
    if (!done) {
      textarea.setRangeText(text, start, textarea.selectionEnd, 'end');
    }
    if (selectFrom !== null) {
      textarea.setSelectionRange(start + selectFrom, start + (selectTo ?? selectFrom));
    }
    this.value = textarea.value;
  }

  wrap(before, after, placeholder) {
    const textarea = this.textarea;
    const selected = textarea.value.slice(textarea.selectionStart, textarea.selectionEnd) || placeholder;
    this.replaceSelection(before + selected + after, before.length, before.length + selected.length);
  }

  prefixLines(prefix) {
    const textarea = this.textarea;
    const lineStart = textarea.value.lastIndexOf('\n', textarea.selectionStart - 1) + 1;
    textarea.setSelectionRange(lineStart, textarea.selectionEnd);
    const lines = textarea.value.slice(lineStart, textarea.selectionEnd).split('\n');
    const text = lines.map((line, index) => prefix(index) + line).join('\n');
    this.replaceSelection(text, text.length);
  }

  insertBlock(block) {
    const textarea = this.textarea;
    const before = textarea.value.slice(0, textarea.selectionStart);
    const prefix = before === '' || before.endsWith('\n\n') ? '' : before.endsWith('\n') ? '\n' : '\n\n';
    this.replaceSelection(`${prefix}${block}\n`, prefix.length + block.length + 1);
  }

  code() {
    const textarea = this.textarea;
    const selected = textarea.value.slice(textarea.selectionStart, textarea.selectionEnd);
    if (selected.includes('\n')) {
      this.wrap('```\n', '\n```', '');
    } else {
      this.wrap('`', '`', 'code');
    }
  }

  link() {
    const textarea = this.textarea;
    const selected = textarea.value.slice(textarea.selectionStart, textarea.selectionEnd) || 'text';
    const text = `[${selected}](url)`;
    this.replaceSelection(text, selected.length + 3, selected.length + 6);
  }

  /**
   * Enter in a list item starts the next item, like GitLab and GitHub: same indentation and marker, numbers count
   * up, task items start unchecked. Enter in an empty item ends the list.
   *
   * @return {boolean} whether Enter was handled
   */
  continueList() {
    const textarea = this.textarea;
    if (textarea.selectionStart !== textarea.selectionEnd) {
      return false;
    }
    const caret = textarea.selectionStart;
    const lineStart = textarea.value.lastIndexOf('\n', caret - 1) + 1;
    const line = textarea.value.slice(lineStart, caret);
    const match = line.match(/^(\s*)(?:([-*+])|(\d+)([.)]))\s+(\[[ xX]\]\s+)?/);
    if (!match) {
      return false;
    }
    if (line.slice(match[0].length).trim() === '' && textarea.value.slice(caret, textarea.value.indexOf('\n', caret) >>> 0).trim() === '') {
      // Nothing after the marker: end the list, the marker goes.
      textarea.setSelectionRange(lineStart, caret);
      this.replaceSelection('');
      return true;
    }
    const [, indent, bullet, number, delimiter, task] = match;
    const marker = bullet ?? `${Number(number) + 1}${delimiter}`;
    this.replaceSelection(`\n${indent}${marker} ${task ? '[ ] ' : ''}`);
    return true;
  }

  // --- Preview ------------------------------------------------------------------------------------------------

  async showPreview() {
    this.mode = 'preview';
    this.closeSuggestions();
    const text = this.textarea?.value ?? this.value;
    if (text.trim() === '') {
      this.preview = { state: 'empty', html: '', text };
      return;
    }
    if (this.preview.text === text && this.preview.state === 'done') {
      return;
    }
    this.preview = { state: 'loading', html: '', text };
    const body = new FormData();
    for (const [key, value] of Object.entries(this.config.reference ?? {})) {
      body.set(key, String(value));
    }
    body.set('text', text);
    try {
      const response = await fetch(this.config.previewUrl, { method: 'POST', body, headers: { Accept: 'application/json' } });
      const result = await response.json();
      if (!response.ok) {
        throw new Error(result.error ?? response.statusText);
      }
      // Sanitised by the server (ProviderHtml::sanitize()).
      this.preview = { state: 'done', html: result.html, text };
    } catch (error) {
      this.preview = { state: 'error', html: '', text: null, message: error.message };
    }
  }

  // --- Suggestions --------------------------------------------------------------------------------------------

  get triggers() {
    const gitlab = this.config.provider === 'gitlab';
    return {
      '@': { kind: 'user', remote: true },
      '#': { kind: 'issue', remote: true },
      ...(gitlab ? { '!': { kind: 'mergeRequest', remote: true }, '~': { kind: 'label' } } : {}),
      ':': { kind: 'emoji', minLength: 2 },
    };
  }

  /** The reference being typed before the caret, e.g. "@jan" → { char: '@', query: 'jan', start }. */
  currentTrigger() {
    const textarea = this.textarea;
    if (!textarea || textarea.selectionStart !== textarea.selectionEnd) {
      return null;
    }
    const before = textarea.value.slice(0, textarea.selectionStart);
    const match = before.match(/(?:^|[\s(])([@#!~:])([^\s@#!~:]*)$/);
    if (!match || !this.triggers[match[1]]) {
      return null;
    }
    const definition = this.triggers[match[1]];
    if (match[2].length < (definition.minLength ?? 0)) {
      return null;
    }
    return { char: match[1], query: match[2], start: before.length - match[2].length - 1, ...definition };
  }

  updateSuggestions() {
    const trigger = this.currentTrigger();
    this.trigger = trigger;
    if (!trigger) {
      this.closeSuggestions();
      return;
    }
    this.popupPosition = this.caretPosition();
    if (trigger.kind === 'emoji') {
      const query = trigger.query.toLowerCase();
      this.showSuggestions(Object.entries(EMOJI)
        .filter(([name]) => name.includes(query))
        .sort(([a], [b]) => Number(!a.startsWith(query)) - Number(!b.startsWith(query)) || a.localeCompare(b))
        .slice(0, MAX_LOCAL)
        .map(([name, emoji]) => ({ label: `${emoji} :${name}:`, insert: `:${name}:` })));
      return;
    }
    if (trigger.kind === 'label') {
      const query = trigger.query.toLowerCase();
      this.showSuggestions((this.config.labels ?? [])
        .filter((label) => label.toLowerCase().includes(query))
        .slice(0, MAX_LOCAL)
        .map((label) => ({ label: `~${label}`, insert: /\s/.test(label) ? `~"${label}"` : `~${label}` })));
      return;
    }
    clearTimeout(this.remoteTimer);
    this.remoteTimer = setTimeout(() => this.loadRemote(trigger), REMOTE_DELAY);
  }

  async loadRemote(trigger) {
    const cacheKey = `${trigger.kind}:${trigger.query}`;
    const requestId = ++this.requestCounter;
    let items = this.suggestionCache.get(cacheKey);
    if (!items) {
      const url = new URL(this.config.suggestUrl, window.location.href);
      for (const [key, value] of Object.entries(this.config.reference ?? {})) {
        url.searchParams.set(key, String(value));
      }
      url.searchParams.set('kind', trigger.kind);
      url.searchParams.set('q', trigger.query);
      try {
        const response = await fetch(url, { headers: { Accept: 'application/json' } });
        items = (await response.json()).items ?? [];
      } catch {
        items = [];
      }
      this.suggestionCache.set(cacheKey, items);
    }
    // Typing went on meanwhile: only the latest request may show its result.
    if (requestId !== this.requestCounter || this.trigger?.kind !== trigger.kind || this.trigger?.query !== trigger.query) {
      return;
    }
    this.showSuggestions(items.map((item) => ({ label: `${trigger.char}${item.label}`, insert: `${trigger.char}${item.value}` })));
  }

  showSuggestions(items) {
    this.suggestions = items;
    this.activeIndex = 0;
  }

  closeSuggestions() {
    clearTimeout(this.remoteTimer);
    this.requestCounter++;
    this.trigger = null;
    this.suggestions = [];
  }

  pickSuggestion(suggestion) {
    const textarea = this.textarea;
    const trigger = this.currentTrigger();
    if (!trigger) {
      return;
    }
    textarea.setSelectionRange(trigger.start, textarea.selectionStart);
    this.replaceSelection(`${suggestion.insert} `);
    this.closeSuggestions();
  }

  /** Caret position inside the editor, measured with a mirror of the textarea (positions the suggestions). */
  caretPosition() {
    const textarea = this.textarea;
    const style = getComputedStyle(textarea);
    const mirror = document.createElement('div');
    for (const property of MIRROR_STYLES) {
      mirror.style[property] = style[property];
    }
    Object.assign(mirror.style, { position: 'absolute', visibility: 'hidden', whiteSpace: 'pre-wrap', overflowWrap: 'break-word', top: '0', left: '-9999px' });
    mirror.textContent = textarea.value.slice(0, textarea.selectionStart);
    const marker = document.createElement('span');
    marker.textContent = '​';
    mirror.append(marker);
    document.body.append(mirror);
    const lineHeight = parseFloat(style.lineHeight) || parseFloat(style.fontSize) * 1.5;
    const position = {
      top: textarea.offsetTop + marker.offsetTop - textarea.scrollTop + lineHeight,
      left: Math.min(textarea.offsetLeft + marker.offsetLeft, Math.max(0, textarea.clientWidth - 240)),
    };
    mirror.remove();
    return position;
  }

  // --- Events -------------------------------------------------------------------------------------------------

  onInput(event) {
    this.value = event.target.value;
    this.updateSuggestions();
  }

  onKeydown(event) {
    if (this.suggestions.length > 0) {
      switch (event.key) {
        case 'ArrowDown':
          event.preventDefault();
          this.activeIndex = (this.activeIndex + 1) % this.suggestions.length;
          return;
        case 'ArrowUp':
          event.preventDefault();
          this.activeIndex = (this.activeIndex - 1 + this.suggestions.length) % this.suggestions.length;
          return;
        case 'Enter':
        case 'Tab':
          event.preventDefault();
          this.pickSuggestion(this.suggestions[this.activeIndex]);
          return;
        case 'Escape':
          // Handled here: the sheet would close otherwise.
          event.preventDefault();
          this.closeSuggestions();
          return;
        default:
          break;
      }
    }
    if (event.key === 'Enter' && !event.shiftKey && !event.altKey && !event.ctrlKey && !event.metaKey && !event.isComposing) {
      if (this.continueList()) {
        event.preventDefault();
      }
      return;
    }
    if (!(event.ctrlKey || event.metaKey)) {
      return;
    }
    const shortcuts = { b: 'bold', i: 'italic', k: 'link' };
    if (shortcuts[event.key.toLowerCase()]) {
      event.preventDefault();
      this.tools.find((tool) => tool.key === shortcuts[event.key.toLowerCase()]).run();
    } else if (event.key === 'Enter') {
      event.preventDefault();
      const form = this.textarea.form;
      const submit = form && [...form.elements].find((element) => element.type === 'submit' && element.name === 'action');
      form?.requestSubmit(submit ?? undefined);
    }
  }

  render() {
    const textareaId = `${this.uid}-textarea`;
    const listboxId = `${this.uid}-suggestions`;
    const writing = this.mode === 'write';
    return html`
      <div class="form-control p-0" style="height: auto">
        <div class="d-flex flex-wrap align-items-center gap-1 px-2 py-1" style="border-bottom: 1px solid var(--typo3-input-border-color, rgb(0 0 0 / 15%))">
          <label class="form-label mb-0 me-2" for=${textareaId}>${this.label}</label>
          <div role="tablist" class="d-flex gap-1">
            ${['write', 'preview'].map((mode) => html`
              <button type="button" role="tab" class="btn btn-sm ${this.mode === mode ? 'btn-default active' : 'btn-link'}" aria-selected=${this.mode === mode ? 'true' : 'false'}
                @click=${() => (mode === 'preview' ? this.showPreview() : this.switchToWrite())}>${this.t(`editor.${mode}`)}</button>
            `)}
          </div>
          <div role="toolbar" class="ms-auto d-flex flex-wrap gap-1" aria-label=${this.t('editor.toolbar')} ?hidden=${!writing}>
            ${this.tools.map((tool) => html`
              <button type="button" class="btn btn-sm btn-link px-1" style=${tool.style ?? ''} title=${this.t(`editor.${tool.key}`)}
                aria-label=${this.t(`editor.${tool.key}`)} data-tool=${tool.key} @mousedown=${(event) => event.preventDefault()}
                @click=${tool.run}>${tool.text}</button>
            `)}
          </div>
        </div>
        <div style="position: relative">
          <textarea id=${textareaId} class="form-control border-0" style="box-shadow: none; resize: vertical" name=${this.name}
            form=${this.form || nothing} rows=${this.rows} ?hidden=${!writing} .value=${this.value}
            role="combobox" aria-autocomplete="list" aria-expanded=${this.suggestions.length ? 'true' : 'false'} aria-controls=${listboxId}
            aria-activedescendant=${this.suggestions.length ? `${listboxId}-${this.activeIndex}` : nothing}
            @input=${this.onInput} @keydown=${this.onKeydown} @click=${() => this.updateSuggestions()}
            @blur=${() => setTimeout(() => this.closeSuggestions(), 150)}></textarea>
          <ul id=${listboxId} role="listbox" class="list-group ${this.suggestions.length ? '' : 'd-none'}"
            style="position: absolute; z-index: 1000; top: ${this.popupPosition.top}px; left: ${this.popupPosition.left}px; min-width: 15rem; max-width: 30rem; max-height: 16rem; overflow-y: auto; box-shadow: 0 .5rem 1rem rgb(0 0 0 / 15%)">
            ${this.suggestions.map((suggestion, index) => html`
              <li id="${listboxId}-${index}" role="option" aria-selected=${index === this.activeIndex ? 'true' : 'false'}
                class="list-group-item list-group-item-action text-truncate ${index === this.activeIndex ? 'active' : ''}" style="cursor: pointer"
                @mousedown=${(event) => { event.preventDefault(); this.pickSuggestion(suggestion); }}>${suggestion.label}</li>
            `)}
          </ul>
          ${writing ? nothing : html`<div class="p-3 text-break" style="min-height: ${this.rows * 1.5}em" data-preview aria-live="polite">${this.renderPreview()}</div>`}
        </div>
        <p class="form-text small mb-0 px-2 pb-1">${this.t('editor.hint')}</p>
      </div>
    `;
  }

  renderPreview() {
    switch (this.preview.state) {
      case 'loading':
        return html`<p>${this.t('editor.loading')}</p>`;
      case 'empty':
        return html`<p>${this.t('editor.nothingToPreview')}</p>`;
      case 'error':
        return html`<p class="text-danger">${this.t('editor.previewFailed')} ${this.preview.message ?? ''}</p>`;
      default:
        return unsafeHTML(this.preview.html);
    }
  }

  switchToWrite() {
    this.mode = 'write';
    this.focus();
  }
}

customElements.define('unity-tracker-markdown-editor', MarkdownEditor);
