<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "unity_tracker" by ochorocho.
 */

namespace Ochorocho\UnityTracker\Filter;

/**
 * What a filter matches: free text plus field tokens, all combined with AND.
 *
 * Stored as JSON and evaluated in the browser (Resources/Public/JavaScript/filter.js), which knows the
 * same fields and operators: {"search": "…", "tokens": [{"field": "author", "operator": "is", "value": "jane"}]}
 */
final readonly class FilterDefinition implements \JsonSerializable
{
    public const FIELDS = ['author', 'label', 'project', 'connection', 'provider', 'type', 'reason', 'draft'];
    public const OPERATORS = ['is', 'isNot'];
    public const MAX_TOKENS = 20;
    public const MAX_LENGTH = 200;

    /**
     * @param list<array{field: string, operator: string, value: string}> $tokens
     */
    private function __construct(
        public string $search,
        public array $tokens,
    ) {}

    /**
     * @throws InvalidFilterException
     */
    public static function fromJson(string $json): self
    {
        try {
            $data = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new InvalidFilterException('Filter is not valid JSON', 1764160001, $exception);
        }
        if (!is_array($data)) {
            throw new InvalidFilterException('Filter must be a JSON object', 1764160002);
        }

        $search = self::text($data['search'] ?? '');
        $rawTokens = $data['tokens'] ?? [];
        if (!is_array($rawTokens) || !array_is_list($rawTokens)) {
            throw new InvalidFilterException('Filter tokens must be a list', 1764160003);
        }

        $tokens = [];
        foreach ($rawTokens as $token) {
            $token = self::token($token);
            // Duplicates change nothing (AND), drop them.
            if (!in_array($token, $tokens, true)) {
                $tokens[] = $token;
            }
        }
        if (count($tokens) > self::MAX_TOKENS) {
            throw new InvalidFilterException('Filter has more than ' . self::MAX_TOKENS . ' tokens', 1764160004);
        }
        return new self($search, $tokens);
    }

    public function isEmpty(): bool
    {
        return $this->search === '' && $this->tokens === [];
    }

    public function toJson(): string
    {
        return json_encode($this, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @return array{search: string, tokens: list<array{field: string, operator: string, value: string}>}
     */
    public function jsonSerialize(): array
    {
        return ['search' => $this->search, 'tokens' => $this->tokens];
    }

    /**
     * @return array{field: string, operator: string, value: string}
     */
    private static function token(mixed $token): array
    {
        if (!is_array($token)) {
            throw new InvalidFilterException('Filter token must be an object', 1764160005);
        }
        $field = $token['field'] ?? null;
        $operator = $token['operator'] ?? null;
        if (!in_array($field, self::FIELDS, true)) {
            throw new InvalidFilterException('Unknown filter field', 1764160006);
        }
        if (!in_array($operator, self::OPERATORS, true)) {
            throw new InvalidFilterException('Unknown filter operator', 1764160007);
        }
        $value = self::text($token['value'] ?? '');
        if ($value === '' || ($field === 'draft' && !in_array($value, ['yes', 'no'], true))) {
            throw new InvalidFilterException('Invalid value for filter field ' . $field, 1764160008);
        }
        return ['field' => $field, 'operator' => $operator, 'value' => $value];
    }

    private static function text(mixed $value): string
    {
        if (!is_string($value)) {
            throw new InvalidFilterException('Filter values must be strings', 1764160009);
        }
        $value = trim($value);
        if (mb_strlen($value) > self::MAX_LENGTH) {
            throw new InvalidFilterException('Filter value longer than ' . self::MAX_LENGTH . ' characters', 1764160010);
        }
        return $value;
    }
}
