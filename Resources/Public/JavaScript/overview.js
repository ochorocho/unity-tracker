/*
 * This file is part of TYPO3 CMS-based extension "unity_tracker" by ochorocho.
 */
import { LitElement, html, nothing } from 'lit';
import '@typo3/backend/element/icon-element.js';
import Modal from '@typo3/backend/modal.js';
import '@ochorocho/unity-tracker/filter-bar.js';
import '@ochorocho/unity-tracker/item-table.js';
import { emptyFilter, isEmpty, matches, normalize, sameFilter } from '@ochorocho/unity-tracker/filter.js';

/**
 * The overview: filter bar, saved filters, one tab per category with a sortable item table.
 * Filtering runs here on the loaded items; saving goes to the module's filter form.
 *
 * All attributes are JSON, rendered by OverviewController.
 */
export class Overview extends LitElement {
  static properties = {
    items: { type: Array },
    categories: { type: Array },
    labels: { type: Object },
    filters: { type: Array },
    activeFilter: { type: Number, attribute: 'active-filter' },
    urls: { type: Object },
    filter: { state: true },
    savedFilter: { state: true },
    activeTab: { state: true },
  };

  constructor() {
    super();
    this.items = [];
    this.categories = [];
    this.labels = {};
    this.filters = [];
    this.activeFilter = 0;
    this.urls = {};
    this.filter = emptyFilter();
    this.savedFilter = null;
    this.activeTab = '';
  }

  createRenderRoot() {
    return this;
  }

  willUpdate(changed) {
    if (changed.has('activeFilter') || changed.has('filters')) {
      const saved = this.filters.find((candidate) => candidate.uid === this.activeFilter);
      if (saved) {
        this.applySaved(saved, false);
      }
    }
  }

  label(key) {
    return this.labels[key] ?? key;
  }

  applySaved(saved, updateUrl = true) {
    this.savedFilter = saved;
    this.filter = normalize(saved?.definition ?? emptyFilter());
    if (updateUrl) {
      this.updateUrl(saved?.uid ?? 0);
    }
  }

  updateUrl(uid) {
    const url = new URL(window.location.href);
    if (uid) {
      url.searchParams.set('filter', String(uid));
    } else {
      url.searchParams.delete('filter');
    }
    window.history.replaceState(window.history.state, '', url);
  }

  onFilterChange(event) {
    this.filter = normalize(event.detail.filter);
    if (isEmpty(this.filter) && this.savedFilter) {
      this.applySaved(null);
    }
  }

  onSavedFilterSelect(event) {
    const uid = Number(event.target.value);
    this.applySaved(this.filters.find((candidate) => candidate.uid === uid) ?? null);
  }

  formUrl(base, parameters) {
    const url = new URL(base, window.location.href);
    for (const [name, value] of Object.entries(parameters)) {
      url.searchParams.set(name, value);
    }
    return url.toString();
  }

  save() {
    window.location.href = this.formUrl(this.urls.newFilter, { definition: JSON.stringify(this.filter) });
  }

  updateSavedFilter() {
    window.location.href = this.formUrl(this.urls.editFilter, { uid: String(this.savedFilter.uid), definition: JSON.stringify(this.filter) });
  }

  /**
   * The chosen tab, else the first tab needing attention that has items, else the first with items.
   */
  currentTab(counts) {
    if (this.activeTab) {
      return this.activeTab;
    }
    const withItems = this.categories.filter((category) => counts[category.key] > 0);
    return (withItems.find((category) => category.attention) ?? withItems[0] ?? this.categories[0])?.key ?? '';
  }

  /**
   * Opens the detail sheet like the core's contextual editing (v14: side sheet; v13: large modal).
   * The overview reloads after the sheet closes if an action changed something.
   */
  openItem(event) {
    const { item } = event.detail;
    const url = this.formUrl(this.urls.item, {
      connection: String(item.connectionUid),
      type: item.type,
      project: item.project,
      number: String(item.number),
      todo: item.todoId ?? '',
    });
    const top = window.top ?? window;
    let changed = false;
    const onMessage = (message) => {
      if (message.origin === window.location.origin && message.data?.actionName === 'unitytracker:item-changed') {
        changed = true;
      }
    };
    top.addEventListener('message', onMessage);
    const modal = Modal.advanced({
      type: Modal.types.iframe,
      title: `${item.project} ${item.reference}`.trim(),
      content: url,
      size: Modal.sizes.expand ?? Modal.sizes.large,
      position: Modal.positions?.sheet,
      resizeIdentifier: 'unitytracker.item',
    });
    modal.addEventListener('typo3-modal-hidden', () => {
      top.removeEventListener('message', onMessage);
      if (changed) {
        window.location.reload();
      }
    });
  }

  render() {
    const filtered = this.items.filter((item) => matches(item, this.filter));
    const counts = Object.fromEntries(this.categories.map((category) => [category.key, 0]));
    filtered.forEach((item) => { counts[item.category] = (counts[item.category] ?? 0) + 1; });
    const attentionKeys = new Set(this.categories.filter((category) => category.attention).map((category) => category.key));
    // A review request that is also a to-do counts once.
    const attentionCount = new Set(filtered.filter((item) => attentionKeys.has(item.category)).map((item) => item.url || item.title)).size;
    const tab = this.currentTab(counts);

    return html`
      <div class="d-flex flex-wrap gap-2 align-items-start mb-3">
        <unity-tracker-filter-bar class="d-flex flex-grow-1" style="min-width: 20rem"
          .filter=${this.filter} .items=${this.items} .labels=${this.labels}
          @filter-change=${this.onFilterChange}></unity-tracker-filter-bar>
        ${this.renderSavedFilters()}
        ${this.renderSaveButton()}
      </div>

      <p>
        <strong>${this.label('overview.attention')}</strong>
        <span class="badge badge-${attentionCount ? 'warning' : 'success'}" data-attention-count=${attentionCount}>${attentionCount}</span>
        ${attentionCount ? nothing : this.label('overview.allDone')}
      </p>

      <div class="tab-wrapper">
        <ul class="nav nav-tabs" role="tablist">
          ${this.categories.map((category) => this.renderTab(category, counts[category.key], tab))}
        </ul>
        <div class="tab-content pt-3" @unitytracker:open-item=${this.openItem}>
          <div class="tab-pane active" role="tabpanel" id="unitytracker-panel-${tab}" aria-labelledby="unitytracker-tab-${tab}">
            <unity-tracker-item-table class="d-block" data-category=${tab} category=${tab}
              .items=${filtered.filter((item) => item.category === tab)} .labels=${this.labels}></unity-tracker-item-table>
          </div>
        </div>
      </div>
    `;
  }

  renderTab(category, count, current) {
    const active = category.key === current;
    const warn = category.attention && count > 0;
    return html`
      <li class="nav-item" role="presentation">
        <button type="button" class="nav-link ${active ? 'active' : ''}" role="tab" id="unitytracker-tab-${category.key}"
          aria-selected=${active ? 'true' : 'false'} aria-controls="unitytracker-panel-${category.key}"
          @click=${() => { this.activeTab = category.key; }}>
          ${this.label(`category.${category.key}`)}
          <span class="badge badge-${warn ? 'warning' : 'default'}">${count}</span>
        </button>
      </li>
    `;
  }

  renderSavedFilters() {
    const own = this.filters.filter((saved) => saved.own);
    const shared = this.filters.filter((saved) => !saved.own);
    const selected = this.savedFilter?.uid ?? 0;
    const option = (saved) => html`<option value=${saved.uid} .selected=${saved.uid === selected}>${saved.title}${saved.own ? '' : ` (${saved.owner})`}</option>`;
    return html`
      <select class="form-select w-auto" aria-label=${this.label('filter.saved')} @change=${this.onSavedFilterSelect}>
        <option value="0" .selected=${selected === 0}>${this.filters.length ? this.label('filter.saved') : this.label('filter.savedNone')}</option>
        ${own.length ? html`<optgroup label=${this.label('filter.own')}>${own.map(option)}</optgroup>` : nothing}
        ${shared.length ? html`<optgroup label=${this.label('filter.shared')}>${shared.map(option)}</optgroup>` : nothing}
      </select>
    `;
  }

  renderSaveButton() {
    if (isEmpty(this.filter)) {
      return nothing;
    }
    const saved = this.savedFilter;
    if (saved?.own && !sameFilter(saved.definition, this.filter)) {
      return html`
        <button type="button" class="btn btn-default" @click=${this.updateSavedFilter}>${this.label('filter.update')}</button>
        <button type="button" class="btn btn-default" @click=${this.save}>${this.label('filter.save')}</button>
      `;
    }
    if (saved && sameFilter(saved.definition, this.filter)) {
      return nothing;
    }
    return html`<button type="button" class="btn btn-default" @click=${this.save}>${this.label('filter.save')}</button>`;
  }
}

customElements.define('unity-tracker-overview', Overview);
