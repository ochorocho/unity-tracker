<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "unity_tracker" by ochorocho.
 */

namespace Ochorocho\UnityTracker\Tests\Unit\Filter;

use Ochorocho\UnityTracker\Filter\FilterDefinition;
use Ochorocho\UnityTracker\Filter\InvalidFilterException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class FilterDefinitionTest extends UnitTestCase
{
    #[Test]
    public function validDefinitionIsNormalised(): void
    {
        $definition = FilterDefinition::fromJson(json_encode([
            'search' => '  login ',
            'tokens' => [
                ['field' => 'author', 'operator' => 'is', 'value' => ' jane ', 'extra' => 'dropped'],
                ['field' => 'label', 'operator' => 'isNot', 'value' => 'wontfix'],
                ['field' => 'author', 'operator' => 'is', 'value' => 'jane'],
                ['field' => 'draft', 'operator' => 'is', 'value' => 'no'],
            ],
        ], JSON_THROW_ON_ERROR));

        self::assertSame(
            '{"search":"login","tokens":[{"field":"author","operator":"is","value":"jane"},'
            . '{"field":"label","operator":"isNot","value":"wontfix"},{"field":"draft","operator":"is","value":"no"}]}',
            $definition->toJson()
        );
    }

    #[Test]
    public function missingPartsMeanEmpty(): void
    {
        self::assertTrue(FilterDefinition::fromJson('{}')->isEmpty());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidDefinitions(): array
    {
        $token = static fn(string $field, string $operator, mixed $value): string => json_encode(
            ['tokens' => [['field' => $field, 'operator' => $operator, 'value' => $value]]],
            JSON_THROW_ON_ERROR
        );
        return [
            'no JSON' => ['author:jane'],
            'not an object' => ['"jane"'],
            'tokens not a list' => ['{"tokens": {"a": 1}}'],
            'unknown field' => [$token('password', 'is', 'x')],
            'unknown operator' => [$token('author', 'contains', 'x')],
            'empty value' => [$token('author', 'is', ' ')],
            'value not a string' => [$token('author', 'is', ['x'])],
            'invalid draft value' => [$token('draft', 'is', 'maybe')],
            'too long' => [$token('label', 'is', str_repeat('x', FilterDefinition::MAX_LENGTH + 1))],
            'too many tokens' => [json_encode(['tokens' => array_map(
                static fn(int $index): array => ['field' => 'label', 'operator' => 'is', 'value' => 'l' . $index],
                range(0, FilterDefinition::MAX_TOKENS)
            )], JSON_THROW_ON_ERROR)],
        ];
    }

    #[Test]
    #[DataProvider('invalidDefinitions')]
    public function invalidDefinitionIsRejected(string $json): void
    {
        $this->expectException(InvalidFilterException::class);
        FilterDefinition::fromJson($json);
    }
}
