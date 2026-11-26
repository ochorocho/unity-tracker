/*
 * This file is part of TYPO3 CMS-based extension "unity_tracker" by ochorocho.
 */
import { LitElement, html, nothing } from 'lit';
import { FIELDS, OPERATORS, field, isEmpty, suggestions } from '@ochorocho/unity-tracker/filter.js';

const MAX_OPTIONS = 50;
let instanceCounter = 0;

/**
 * GitLab-style filtered search: pick a field, an operator and a value to add a token, or type text to search.
 *
 * <unity-tracker-filter-bar .filter=${…} .items=${…} .labels=${…} @filter-change=${…}>
 *
 * Keyboard: arrows move through the suggestions, Enter picks one (or searches for the typed text),
 * Backspace in the empty input steps back / removes the last token, Escape closes the suggestions.
 */
// The backend CSS has no Bootstrap position/shadow utilities: those are inline styles.
export class FilterBar extends LitElement {
  static properties = {
    filter: { type: Object },
    items: { type: Array },
    labels: { type: Object },
    text: { state: true },
    pendingField: { state: true },
    pendingOperator: { state: true },
    open: { state: true },
    activeIndex: { state: true },
  };

  constructor() {
    super();
    this.filter = { search: '', tokens: [] };
    this.items = [];
    this.labels = {};
    this.text = '';
    this.pendingField = '';
    this.pendingOperator = '';
    this.open = false;
    this.activeIndex = -1;
    this.idPrefix = `unitytracker-filter-${++instanceCounter}`;
  }

  createRenderRoot() {
    return this;
  }

  label(key, argument = '') {
    return (this.labels[key] ?? key).replace('%s', argument);
  }

  fieldLabel(key) {
    return this.label(`filter.field.${key}`);
  }

  valueLabel(fieldKey, value) {
    return fieldKey === 'draft' ? this.label(`filter.value.${value}`) : value;
  }

  get step() {
    if (!this.pendingField) {
      return 'field';
    }
    return this.pendingOperator ? 'value' : 'operator';
  }

  /**
   * Suggestions of the current step: { label, apply() }.
   */
  get options() {
    const text = this.text.trim();
    const needle = text.toLowerCase();
    if (this.step === 'field') {
      const options = FIELDS
        .filter((candidate) => this.fieldLabel(candidate.key).toLowerCase().includes(needle))
        .map((candidate) => ({ label: this.fieldLabel(candidate.key), apply: () => this.pickField(candidate.key) }));
      if (text !== '') {
        options.unshift({ label: this.label('filter.searchFor', text), apply: () => this.setSearch(text) });
      }
      return options;
    }
    if (this.step === 'operator') {
      return OPERATORS.map((operator) => ({ label: this.label(`filter.operator.${operator}`), apply: () => this.pickOperator(operator) }));
    }
    const values = suggestions(this.items, this.pendingField)
      .filter((value) => this.valueLabel(this.pendingField, value).toLowerCase().includes(needle));
    const options = values.slice(0, MAX_OPTIONS).map((value) => ({
      label: this.valueLabel(this.pendingField, value),
      apply: () => this.addToken(value),
    }));
    const freeText = text !== '' && !field(this.pendingField).fixedValues && !values.some((value) => value.toLowerCase() === needle);
    if (freeText) {
      options.unshift({ label: text, apply: () => this.addToken(text) });
    }
    return options;
  }

  pickField(key) {
    this.pendingField = key;
    this.resetInput();
  }

  pickOperator(operator) {
    this.pendingOperator = operator;
    this.resetInput();
  }

  addToken(value) {
    const token = { field: this.pendingField, operator: this.pendingOperator, value };
    const exists = this.filter.tokens.some((existing) => JSON.stringify(existing) === JSON.stringify(token));
    this.pendingField = '';
    this.pendingOperator = '';
    this.resetInput();
    if (!exists) {
      this.change({ ...this.filter, tokens: [...this.filter.tokens, token] });
    }
  }

  setSearch(text) {
    this.resetInput();
    this.change({ ...this.filter, search: text });
  }

  removeToken(index) {
    this.change({ ...this.filter, tokens: this.filter.tokens.filter((_, position) => position !== index) });
    this.focusInput();
  }

  clear() {
    this.pendingField = '';
    this.pendingOperator = '';
    this.resetInput();
    this.change({ search: '', tokens: [] });
    this.focusInput();
  }

  change(filter) {
    this.dispatchEvent(new CustomEvent('filter-change', { detail: { filter }, bubbles: true }));
  }

  resetInput() {
    this.text = '';
    this.activeIndex = -1;
    this.open = true;
  }

  focusInput() {
    this.updateComplete.then(() => this.querySelector('input')?.focus());
  }

  stepBack() {
    if (this.pendingOperator) {
      this.pendingOperator = '';
    } else if (this.pendingField) {
      this.pendingField = '';
    } else if (this.filter.tokens.length > 0) {
      this.change({ ...this.filter, tokens: this.filter.tokens.slice(0, -1) });
    } else if (this.filter.search !== '') {
      this.change({ ...this.filter, search: '' });
    }
  }

  onKeydown(event) {
    const options = this.options;
    switch (event.key) {
      case 'ArrowDown':
        event.preventDefault();
        this.open = true;
        this.activeIndex = options.length ? (this.activeIndex + 1) % options.length : -1;
        break;
      case 'ArrowUp':
        event.preventDefault();
        this.open = true;
        this.activeIndex = options.length ? (this.activeIndex - 1 + options.length) % options.length : -1;
        break;
      case 'Enter': {
        event.preventDefault();
        const option = options[this.activeIndex] ?? options[0];
        option?.apply();
        break;
      }
      case 'Escape':
        this.open = false;
        this.activeIndex = -1;
        break;
      case 'Backspace':
        if (this.text === '') {
          event.preventDefault();
          this.stepBack();
        }
        break;
      default:
        break;
    }
  }

  onInput(event) {
    this.text = event.target.value;
    this.activeIndex = -1;
    this.open = true;
  }

  onFocusout(event) {
    if (!this.contains(event.relatedTarget)) {
      this.open = false;
      this.activeIndex = -1;
    }
  }

  tokenText(token) {
    return `${this.fieldLabel(token.field)} ${this.label(`filter.operator.${token.operator}`)} ${this.valueLabel(token.field, token.value)}`;
  }

  render() {
    const options = this.open ? this.options : [];
    const listboxId = `${this.idPrefix}-listbox`;
    const activeId = this.activeIndex >= 0 && options[this.activeIndex] ? `${this.idPrefix}-option-${this.activeIndex}` : '';
    return html`
      <div class="flex-grow-1" style="position: relative" @focusout=${this.onFocusout}>
        <div class="form-control d-flex flex-wrap align-items-center gap-1" @click=${() => this.focusInput()}>
          ${this.filter.search !== '' ? this.renderPill(`${this.label('filter.search')}: ${this.filter.search}`, () => this.change({ ...this.filter, search: '' })) : nothing}
          ${this.filter.tokens.map((token, index) => this.renderPill(this.tokenText(token), () => this.removeToken(index)))}
          ${this.pendingField ? html`
            <span class="badge badge-info" data-pending-token>
              ${this.fieldLabel(this.pendingField)}${this.pendingOperator ? ` ${this.label(`filter.operator.${this.pendingOperator}`)}` : ''}
            </span>` : nothing}
          <input
            type="text"
            class="border-0 bg-transparent flex-grow-1"
            style="outline: none; min-width: 12rem"
            role="combobox"
            aria-autocomplete="list"
            aria-expanded=${options.length > 0 ? 'true' : 'false'}
            aria-controls=${listboxId}
            aria-activedescendant=${activeId || nothing}
            aria-label=${this.label('filter.placeholder')}
            placeholder=${this.filter.tokens.length || this.filter.search || this.pendingField ? '' : this.label('filter.placeholder')}
            .value=${this.text}
            @input=${this.onInput}
            @keydown=${this.onKeydown}
            @focus=${() => { this.open = true; }}
          />
          ${!isEmpty(this.filter) || this.pendingField ? html`
            <button type="button" class="btn btn-sm btn-link p-0 ms-auto" title=${this.label('filter.clear')} aria-label=${this.label('filter.clear')}
              @click=${(event) => { event.stopPropagation(); this.clear(); }}>
              <typo3-backend-icon identifier="actions-close" size="small"></typo3-backend-icon>
            </button>` : nothing}
        </div>
        <ul id=${listboxId} role="listbox" class="list-group ${options.length ? '' : 'd-none'}"
          style="position: absolute; z-index: 1000; top: 100%; left: 0; min-width: 16rem; max-height: 20rem; overflow-y: auto; box-shadow: 0 .5rem 1rem rgb(0 0 0 / 15%)">
          ${options.map((option, index) => html`
            <li id="${this.idPrefix}-option-${index}" role="option" aria-selected=${index === this.activeIndex ? 'true' : 'false'}
              class="list-group-item list-group-item-action ${index === this.activeIndex ? 'active' : ''}"
              style="cursor: pointer"
              @mousedown=${(event) => event.preventDefault()}
              @click=${() => { option.apply(); this.focusInput(); }}>${option.label}</li>
          `)}
        </ul>
      </div>
    `;
  }

  renderPill(text, remove) {
    return html`
      <span class="badge badge-default d-inline-flex align-items-center gap-1" data-token>
        ${text}
        <button type="button" class="btn btn-link p-0 lh-1" style="color: inherit" aria-label=${this.label('filter.removeToken', text)}
          @click=${(event) => { event.stopPropagation(); remove(); }}>×</button>
      </span>
    `;
  }
}

customElements.define('unity-tracker-filter-bar', FilterBar);
