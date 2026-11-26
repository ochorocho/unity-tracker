/*
 * This file is part of TYPO3 CMS-based extension "unity_tracker" by ochorocho.
 */
import { LitElement, html, nothing } from 'lit';
import 'bootstrap';
import '@typo3/backend/element/icon-element.js';

const ASCENDING = 'ascending';
const DETAIL_TYPES = ['issue', 'merge_request', 'pull_request'];
const DESCENDING = 'descending';

/**
 * Sortable table of overview items, newest update first.
 *
 * <unity-tracker-item-table category="todo" items="[…]" labels="{…}">
 *
 * Titles of issues, merge and pull requests dispatch `unitytracker:open-item` (the overview opens the
 * detail sheet); everything else, and the icon next to each title, links to GitLab/GitHub.
 *
 * `items` are prepared by ItemTablePresenter, `labels` (keyed by XLF id) are translated by the controller.
 * Item labels (tags) are data for the filter only, the table does not show them.
 * Renders into the light DOM so the backend's table styles apply. The column headers use the
 * dropdown markup of the core's sortable tables (`@typo3/backend/sortable-table.js`), which
 * itself cannot be used: it reorders rows behind Lit's back.
 */
export class ItemTable extends LitElement {
  static properties = {
    category: { type: String },
    items: { type: Array },
    labels: { type: Object },
    sortColumn: { state: true },
    sortDirection: { state: true },
  };

  constructor() {
    super();
    this.category = '';
    this.items = [];
    this.labels = {};
    this.sortColumn = 'updated';
    this.sortDirection = DESCENDING;
  }

  createRenderRoot() {
    return this;
  }

  get columns() {
    const isTodo = this.category === 'todo';
    return [
      { key: 'title', label: this.labels['column.title'], value: (item) => item.title },
      { key: 'project', label: this.labels['column.project'], value: (item) => `${item.project} ${item.reference}` },
      isTodo
        ? { key: 'reason', label: this.labels['column.reason'], value: (item) => `${item.reason} ${item.author}` }
        : { key: 'author', label: this.labels['column.author'], value: (item) => item.author },
      { key: 'updated', label: this.labels['column.updated'], value: (item) => Date.parse(item.updatedAt) || 0 },
      { key: 'connection', label: this.labels['column.connection'], value: (item) => item.connection },
    ];
  }

  sortedItems() {
    const column = this.columns.find((candidate) => candidate.key === this.sortColumn) ?? this.columns[3];
    const factor = this.sortDirection === ASCENDING ? 1 : -1;
    return [...this.items].sort((a, b) => {
      const left = column.value(a);
      const right = column.value(b);
      const result = typeof left === 'number'
        ? left - right
        : left.localeCompare(right, undefined, { numeric: true, sensitivity: 'base' });
      return result * factor;
    });
  }

  sort(event, column, direction) {
    this.sortColumn = column;
    this.sortDirection = direction;
    // The backend shows dropdowns as popovers, which stay open after a choice.
    event.currentTarget.closest('.dropdown-menu')?.hidePopover?.();
  }

  render() {
    if (this.items.length === 0) {
      return html`<p class="text-body-secondary">${this.labels['overview.nothing']}</p>`;
    }
    const isTodo = this.category === 'todo';
    return html`
      <div class="table-fit">
        <table class="table table-striped table-hover">
          <thead>
            <tr>${this.columns.map((column) => this.renderHeader(column))}</tr>
          </thead>
          <tbody>
            ${this.sortedItems().map((item) => this.renderRow(item, isTodo))}
          </tbody>
        </table>
      </div>
    `;
  }

  renderHeader(column) {
    const active = column.key === this.sortColumn;
    const toggleIcon = !active
      ? 'empty-empty'
      : this.sortDirection === ASCENDING ? 'actions-sort-amount-up' : 'actions-sort-amount-down';
    return html`
      <th role="columnheader" aria-sort=${active ? this.sortDirection : nothing} data-column=${column.key}>
        <button type="button" class="dropdown-toggle dropdown-toggle-link" data-bs-toggle="dropdown" data-sorting-toggle="true" aria-expanded="false">
          ${column.label}
          <div class=${active ? 'text-primary' : ''}>
            <typo3-backend-icon identifier=${toggleIcon} size="small" class=${active ? 'text-primary' : ''}></typo3-backend-icon>
          </div>
        </button>
        <div class="dropdown-menu">
          ${this.renderSortItem(column, ASCENDING, this.labels['sort.ascending'])}
          ${this.renderSortItem(column, DESCENDING, this.labels['sort.descending'])}
        </div>
      </th>
    `;
  }

  renderSortItem(column, direction, label) {
    const selected = column.key === this.sortColumn && direction === this.sortDirection;
    return html`
      <button type="button" class="dropdown-item" title=${label} aria-label=${label} data-sort-direction=${direction}
        @click=${(event) => this.sort(event, column.key, direction)}>
        <span class="dropdown-item-columns">
          <span class="dropdown-item-column dropdown-item-column-icon text-primary">
            <typo3-backend-icon identifier=${selected ? 'actions-dot' : 'empty-empty'} size="small"></typo3-backend-icon>
          </span>
          <span class="dropdown-item-column dropdown-item-column-title">${label}</span>
        </span>
      </button>
    `;
  }

  externalLabel(item) {
    return (this.labels['item.openExternal'] ?? '%s').replace('%s', item.provider);
  }

  hasDetail(item) {
    return DETAIL_TYPES.includes(item.type) && item.number > 0 && item.connectionUid > 0;
  }

  open(event, item) {
    if (event.ctrlKey || event.metaKey || event.shiftKey || event.button === 1) {
      return;
    }
    event.preventDefault();
    this.dispatchEvent(new CustomEvent('unitytracker:open-item', { detail: { item }, bubbles: true }));
  }

  renderRow(item, isTodo) {
    return html`
      <tr>
        <td>
          ${this.hasDetail(item)
            ? html`<a href=${item.url || '#'} @click=${(event) => this.open(event, item)}>${item.title}</a>`
            : item.url ? html`<a href=${item.url} target="_blank" rel="noopener noreferrer">${item.title}</a>` : item.title}
          ${this.hasDetail(item) && item.url ? html`
            <a href=${item.url} target="_blank" rel="noopener noreferrer" class="ms-1" title=${this.externalLabel(item)} aria-label=${this.externalLabel(item)}>
              <typo3-backend-icon identifier="actions-window-open" size="small"></typo3-backend-icon>
            </a>` : nothing}
          ${item.draft ? html`<span class="badge badge-default">${this.labels['item.draft']}</span>` : nothing}
        </td>
        <td class="text-nowrap">
          ${item.project}
          ${item.reference ? html`<span class="text-body-secondary">${item.reference}</span>` : nothing}
        </td>
        <td class="text-nowrap">
          ${isTodo && item.reason ? html`<span class="badge badge-notice">${item.reason}</span> ` : nothing}${item.author}
        </td>
        <td class="text-nowrap">
          ${item.updatedAt ? html`<time datetime=${item.updatedAt}>${item.updatedAtFormatted}</time>` : nothing}
        </td>
        <td class="text-nowrap" title=${item.provider}>${item.connection}</td>
      </tr>
    `;
  }
}

customElements.define('unity-tracker-item-table', ItemTable);
