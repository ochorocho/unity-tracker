<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "unity_tracker" by ochorocho.
 */

namespace Ochorocho\UnityTracker\Tests\Unit\Detail;

use Ochorocho\UnityTracker\Detail\ItemReference;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class ItemReferenceTest extends UnitTestCase
{
    #[Test]
    public function nestedGitLabProjectIsAccepted(): void
    {
        $reference = ItemReference::fromArray(['connection' => '3', 'type' => 'merge_request', 'project' => 'group/sub.group/my-project', 'number' => '12', 'todo' => '99']);

        self::assertSame(['connection' => 3, 'type' => 'merge_request', 'project' => 'group/sub.group/my-project', 'number' => 12, 'todo' => '99'], $reference->toArray());
    }

    /**
     * @return array<string, array{array<string, string>}>
     */
    public static function invalidReferences(): array
    {
        $valid = ['connection' => '1', 'type' => 'issue', 'project' => 'acme/app', 'number' => '9', 'todo' => ''];
        return [
            'no connection' => [['connection' => '0'] + $valid],
            'unknown type' => [['type' => 'release'] + $valid],
            'no number' => [['number' => '0'] + $valid],
            'project without namespace' => [['project' => 'app'] + $valid],
            'path traversal' => [['project' => 'acme/../../user'] + $valid],
            'query in project' => [['project' => 'acme/app?x=1'] + $valid],
            'todo not numeric' => [['todo' => '1/../2'] + $valid],
        ];
    }

    /**
     * @param array<string, string> $parameters
     */
    #[Test]
    #[DataProvider('invalidReferences')]
    public function invalidReferenceIsRejected(array $parameters): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ItemReference::fromArray($parameters);
    }
}
