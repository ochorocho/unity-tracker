<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "unity_tracker" by ochorocho.
 */

namespace Ochorocho\UnityTracker\Item;

use Ochorocho\UnityTracker\Connection\Provider;

/**
 * One issue, merge/pull request, to-do or notification, normalised across providers.
 */
final readonly class Item
{
    /**
     * @param string $type merge_request, pull_request, issue, commit, release, ...
     * @param string $reference short reference like "!12" or "#3", empty if there is none
     * @param list<string> $labels
     * @param string $reason why a to-do/notification exists, e.g. "mentioned", "review_requested"
     * @param int $connectionUid the connection the item was loaded through
     * @param int $number issue/merge request/pull request number (GitLab iid), 0 if there is none
     * @param string $todoId GitLab to-do id or GitHub notification thread id, empty for list items
     */
    public function __construct(
        public Category $category,
        public Provider $provider,
        public string $connection,
        public string $type,
        public string $title,
        public string $url,
        public string $project,
        public string $reference,
        public string $author,
        public \DateTimeImmutable $updatedAt,
        public array $labels = [],
        public string $reason = '',
        public bool $draft = false,
        public int $connectionUid = 0,
        public int $number = 0,
        public string $todoId = '',
    ) {}

    /**
     * Copy with what a detail request found out, e.g. for a GitHub notification.
     *
     * @param list<string> $labels
     */
    public function withDetails(string $author, array $labels, bool $draft): self
    {
        return new self(
            $this->category,
            $this->provider,
            $this->connection,
            $this->type,
            $this->title,
            $this->url,
            $this->project,
            $this->reference,
            $author,
            $this->updatedAt,
            $labels,
            $this->reason,
            $draft,
            $this->connectionUid,
            $this->number,
            $this->todoId,
        );
    }
}
