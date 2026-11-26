/*
 * This file is part of TYPO3 CMS-based extension "unity_tracker" by ochorocho.
 */

/**
 * Filter model shared by the filter bar and the overview. Same fields and operators as
 * Classes/Filter/FilterDefinition.php, which validates saved filters.
 *
 * { search: 'text', tokens: [{ field: 'author', operator: 'is' | 'isNot', value: 'jane' }] }
 *
 * All tokens must hold (AND). A field matches when one of the item's values equals the token value,
 * ignoring case; `search` is a substring of title, reference or project.
 */

const single = (value) => (value ? [value] : []);

export const FIELDS = [
  { key: 'author', values: (item) => single(item.author) },
  { key: 'label', values: (item) => item.labels ?? [] },
  { key: 'project', values: (item) => single(item.project) },
  { key: 'connection', values: (item) => single(item.connection) },
  { key: 'provider', values: (item) => single(item.provider) },
  { key: 'type', values: (item) => single(item.type) },
  { key: 'reason', values: (item) => single(item.reason) },
  { key: 'draft', values: (item) => [item.draft ? 'yes' : 'no'], fixedValues: ['yes', 'no'] },
];

export const OPERATORS = ['is', 'isNot'];

export function field(key) {
  return FIELDS.find((candidate) => candidate.key === key);
}

export function emptyFilter() {
  return { search: '', tokens: [] };
}

export function isEmpty(filter) {
  return filter.search === '' && filter.tokens.length === 0;
}

export function sameFilter(a, b) {
  return JSON.stringify(normalize(a)) === JSON.stringify(normalize(b));
}

export function normalize(filter) {
  return {
    search: (filter?.search ?? '').trim(),
    tokens: (filter?.tokens ?? []).map(({ field, operator, value }) => ({ field, operator, value })),
  };
}

export function matches(item, filter) {
  const search = filter.search.toLowerCase();
  if (search !== '' && ![item.title, item.reference, item.project].some((text) => (text ?? '').toLowerCase().includes(search))) {
    return false;
  }
  return filter.tokens.every((token) => {
    const definition = field(token.field);
    if (!definition) {
      return true;
    }
    const value = token.value.toLowerCase();
    const has = definition.values(item).some((candidate) => candidate.toLowerCase() === value);
    return token.operator === 'isNot' ? !has : has;
  });
}

/**
 * Distinct values of a field in the loaded items, for the value suggestions.
 */
export function suggestions(items, key) {
  const definition = field(key);
  if (!definition) {
    return [];
  }
  if (definition.fixedValues) {
    return definition.fixedValues;
  }
  const values = new Set(items.flatMap((item) => definition.values(item)));
  return [...values].sort((a, b) => a.localeCompare(b, undefined, { numeric: true, sensitivity: 'base' }));
}
