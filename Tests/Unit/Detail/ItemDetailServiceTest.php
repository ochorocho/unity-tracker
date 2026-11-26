<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "unity_tracker" by ochorocho.
 */

namespace Ochorocho\UnityTracker\Tests\Unit\Detail;

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use Ochorocho\UnityTracker\Connection\Provider;
use Ochorocho\UnityTracker\Detail\GitHubDetailProvider;
use Ochorocho\UnityTracker\Detail\GitLabDetailProvider;
use Ochorocho\UnityTracker\Detail\InvalidActionException;
use Ochorocho\UnityTracker\Detail\ItemAction;
use Ochorocho\UnityTracker\Detail\ItemDetailService;
use Ochorocho\UnityTracker\Detail\ItemReference;
use Ochorocho\UnityTracker\Service\FetchFailedException;
use Ochorocho\UnityTracker\Tests\Unit\Fixtures\Fixture;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\RequestInterface;
use Psr\Log\NullLogger;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class ItemDetailServiceTest extends UnitTestCase
{
    /** @var list<string> "METHOD url" of every request */
    private array $sent = [];

    private ?Response $actionResponse = null;

    /** @var list<array<mixed>> decoded bodies of the GraphQL requests */
    private array $graphql = [];

    /** @var list<array<mixed>> decoded bodies of the GraphQL mutations */
    private array $mutations = [];

    #[Test]
    public function allowedActionIsSentAfterReloadingTheDetail(): void
    {
        $this->subject()->perform($this->reference(), Fixture::connection(Provider::GitHub), ItemAction::COMMENT, ['body' => ' Thanks ']);

        self::assertSame([
            'POST https://api.github.com/graphql',
            'POST https://api.github.com/repos/acme/app/issues/42/comments',
        ], $this->sent);
    }

    #[Test]
    public function actionTheUserMayNotDoIsNotSent(): void
    {
        $this->expectException(InvalidActionException::class);
        try {
            // The fixture's pull request is open: reopening is not offered.
            $this->subject()->perform($this->reference(), Fixture::connection(Provider::GitHub), ItemAction::REOPEN, []);
        } finally {
            self::assertSame(['POST https://api.github.com/graphql'], $this->sent);
        }
    }

    #[Test]
    public function unknownLabelIsRejected(): void
    {
        $this->expectException(InvalidActionException::class);
        $this->expectExceptionMessage('label');
        $this->subject()->perform($this->reference(), Fixture::connection(Provider::GitHub), ItemAction::ADD_LABEL, ['label' => 'invented']);
    }

    #[Test]
    public function emptyCommentIsRejected(): void
    {
        $this->expectException(InvalidActionException::class);
        $this->expectExceptionMessage('body');
        $this->subject()->perform($this->reference(), Fixture::connection(Provider::GitHub), ItemAction::COMMENT, ['body' => '  ']);
    }

    #[Test]
    public function commentOfSomebodyElseCannotBeEdited(): void
    {
        $this->expectException(InvalidActionException::class);
        $this->expectExceptionMessage('notAllowed');
        try {
            $this->subject()->perform($this->reference(), Fixture::connection(Provider::GitHub), ItemAction::EDIT_COMMENT, ['comment' => '9001', 'body' => 'Hijacked']);
        } finally {
            self::assertSame(['POST https://api.github.com/graphql'], $this->sent);
        }
    }

    #[Test]
    public function ownCommentIsEdited(): void
    {
        $this->subject()->perform($this->reference(), Fixture::connection(Provider::GitHub), ItemAction::EDIT_COMMENT, ['comment' => '9002', 'body' => 'Fixed in 1a2b3c']);

        self::assertSame('PATCH https://api.github.com/repos/acme/app/issues/comments/9002', $this->sent[1]);
    }

    #[Test]
    public function commentIsDeletedOnlyWhenTheApiAllowsIt(): void
    {
        $this->subject()->perform($this->reference(), Fixture::connection(Provider::GitHub), ItemAction::DELETE_COMMENT, ['comment' => 'review-8002']);
        self::assertSame('DELETE https://api.github.com/repos/acme/app/pulls/comments/8002', $this->sent[1]);

        $this->expectException(InvalidActionException::class);
        $this->subject()->perform($this->reference(), Fixture::connection(Provider::GitHub), ItemAction::DELETE_COMMENT, ['comment' => 'review-8001']);
    }

    #[Test]
    public function replyGoesToTheThreadsFirstComment(): void
    {
        $this->subject()->perform($this->reference(), Fixture::connection(Provider::GitHub), ItemAction::REPLY, ['thread' => 'PRRT_kwDOabc', 'body' => 'Done']);

        self::assertSame('POST https://api.github.com/repos/acme/app/pulls/42/comments/8001/replies', $this->sent[1]);
    }

    #[Test]
    public function unknownThreadIsRejected(): void
    {
        $this->expectException(InvalidActionException::class);
        $this->expectExceptionMessage('notAllowed');
        $this->subject()->perform($this->reference(), Fixture::connection(Provider::GitHub), ItemAction::REPLY, ['thread' => 'PRRT_other', 'body' => 'Hi']);
    }

    #[Test]
    public function unresolvedThreadCannotBeUnresolved(): void
    {
        $this->expectException(InvalidActionException::class);
        $this->subject()->perform($this->reference(), Fixture::connection(Provider::GitHub), ItemAction::UNRESOLVE, ['thread' => 'PRRT_kwDOabc']);
    }

    #[Test]
    public function graphqlErrorOfAMutationFailsTheAction(): void
    {
        $this->actionResponse = new Response(200, [], '{"data": null, "errors": [{"message": "Resource not accessible by integration"}]}');

        try {
            $this->subject()->perform($this->reference(), Fixture::connection(Provider::GitHub), ItemAction::RESOLVE, ['thread' => 'PRRT_kwDOabc']);
            self::fail('Expected FetchFailedException');
        } catch (FetchFailedException $exception) {
            self::assertStringContainsString('Resource not accessible by integration', $exception->error->detail);
        }
    }

    #[Test]
    public function actionOnAnOlderPageIsCheckedAgainstThatPage(): void
    {
        $this->subject()->perform($this->reference(), Fixture::connection(Provider::GitHub), ItemAction::COMMENT, ['body' => 'Hi', 'page' => 'Y3Vyc29y']);
        $this->subject()->perform($this->reference(), Fixture::connection(Provider::GitHub), ItemAction::COMMENT, ['body' => 'Hi', 'page' => '"; drop']);

        self::assertSame('Y3Vyc29y', $this->graphql[0]['variables']['before']);
        self::assertNull($this->graphql[1]['variables']['before'], 'an invalid cursor loads the newest page');
    }

    #[Test]
    public function reactionTheUserHasIsRemovedAnyOtherAdded(): void
    {
        $connection = Fixture::connection(Provider::GitHub);
        $this->subject()->perform($this->reference(), $connection, ItemAction::REACT, ['subject' => '', 'emoji' => 'tada']);
        $this->subject()->perform($this->reference(), $connection, ItemAction::REACT, ['subject' => '9001', 'emoji' => 'rocket']);

        self::assertStringContainsString('removeReaction(', $this->mutations[0]['query']);
        self::assertSame(['id' => 'PR_kwDO42', 'content' => 'HOORAY'], $this->mutations[0]['variables']);
        self::assertStringContainsString('addReaction(', $this->mutations[1]['query']);
        self::assertSame(['id' => 'IC_9001', 'content' => 'ROCKET'], $this->mutations[1]['variables']);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function invalidReactions(): array
    {
        return [
            'comment the user cannot react to' => ['review-8001', 'tada'],
            'unknown comment' => ['123', 'tada'],
            'emoji that is no choice' => ['', 'flag_de'],
            'invalid name' => ['', 'tada"'],
        ];
    }

    #[Test]
    #[DataProvider('invalidReactions')]
    public function invalidReactionIsRejected(string $subject, string $emoji): void
    {
        $this->expectException(InvalidActionException::class);
        try {
            $this->subject()->perform($this->reference(), Fixture::connection(Provider::GitHub), ItemAction::REACT, ['subject' => $subject, 'emoji' => $emoji]);
        } finally {
            self::assertSame([], $this->mutations);
        }
    }

    #[Test]
    public function apiMessageIsPartOfTheError(): void
    {
        $this->actionResponse = new Response(405, [], '{"message": "Pull Request is not mergeable"}');

        try {
            $this->subject()->perform($this->reference(), Fixture::connection(Provider::GitHub), ItemAction::MERGE, []);
            self::fail('Expected FetchFailedException');
        } catch (FetchFailedException $exception) {
            self::assertSame('HTTP 405: Pull Request is not mergeable', $exception->error->detail);
        }
    }

    private function reference(): ItemReference
    {
        return ItemReference::fromArray(['connection' => 1, 'type' => 'pull_request', 'project' => 'acme/app', 'number' => 42, 'todo' => '']);
    }

    private function subject(): ItemDetailService
    {
        $handler = function (RequestInterface $request): PromiseInterface {
            $this->sent[] = $request->getMethod() . ' ' . $request->getUri();
            $body = json_decode((string)$request->getBody(), true);
            if (str_ends_with((string)$request->getUri(), '/graphql') && !str_starts_with($body['query'] ?? '', 'mutation')) {
                $this->graphql[] = $body;
                return Create::promiseFor(new Response(200, ['X-OAuth-Scopes' => 'repo'], Fixture::json('GitHub/graphql_pull_request.json')));
            }
            if (is_array($body)) {
                $this->mutations[] = $body;
            }
            return Create::promiseFor($this->actionResponse ?? new Response(201, [], '{}'));
        };
        return new ItemDetailService(
            new GitLabDetailProvider(),
            new GitHubDetailProvider(),
            new Client(['handler' => HandlerStack::create($handler)]),
            new NullLogger(),
        );
    }
}
