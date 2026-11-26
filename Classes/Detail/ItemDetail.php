<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "unity_tracker" by ochorocho.
 */

namespace Ochorocho\UnityTracker\Detail;

/**
 * Everything the detail sheet shows of one issue, merge request or pull request, plus what the
 * current user may do with it. HTML comes from the provider and is sanitised when rendered.
 *
 * @phpstan-import-type Comment from Timeline
 * @phpstan-import-type Entry from Timeline
 * @phpstan-import-type Reaction from Reactions
 */
final readonly class ItemDetail
{
    public const STATE_OPEN = 'open';
    public const STATE_CLOSED = 'closed';
    public const STATE_MERGED = 'merged';

    /**
     * @param list<array{name: string, color: string}> $labels
     * @param list<array{id: string, name: string}> $assignees id is the provider's user id (GitLab) or login (GitHub)
     * @param list<Entry> $timeline one page of events and threads, oldest first
     * @param list<array{name: string, color: string}> $availableLabels labels of the project/repository
     * @param array{id: string, name: string} $viewer the token's user
     * @param list<string> $actions allowed actions, see ItemAction
     * @param string $page cursor this page of the timeline was loaded with ("" = newest)
     * @param string $olderCursor cursor of the page before, "" if there is none
     * @param list<Reaction> $reactions of the item itself
     * @param string $subject the item's id to react with (see Timeline), "" if reactions are not supported
     */
    public function __construct(
        public ItemReference $reference,
        public string $title,
        public string $url,
        public string $state,
        public string $author,
        public ?\DateTimeImmutable $createdAt,
        public string $description,
        public string $descriptionHtml,
        public array $labels,
        public array $assignees,
        public array $timeline,
        public array $availableLabels,
        public array $viewer,
        public array $actions,
        public bool $draft = false,
        public string $sourceBranch = '',
        public string $targetBranch = '',
        public string $mergeStatus = '',
        public bool $approvedByViewer = false,
        public string $missingScope = '',
        public string $page = '',
        public string $olderCursor = '',
        public array $reactions = [],
        public string $subject = '',
        public bool $canReact = false,
    ) {}

    /**
     * "group/project!12", "owner/repository#3"
     */
    public function referenceText(): string
    {
        return $this->reference->project . ($this->reference->type === 'merge_request' ? '!' : '#') . $this->reference->number;
    }

    public function allows(string $action): bool
    {
        return in_array($action, $this->actions, true);
    }

    public function isAssignedToViewer(): bool
    {
        return in_array($this->viewer['id'], array_column($this->assignees, 'id'), true);
    }

    /**
     * @return list<string>
     */
    public function labelNames(): array
    {
        return array_column($this->labels, 'name');
    }

    /**
     * Project labels not set yet, for the "add label" choice.
     *
     * @return list<array{name: string, color: string}>
     */
    public function addableLabels(): array
    {
        $set = array_map(mb_strtolower(...), $this->labelNames());
        return array_values(array_filter($this->availableLabels, static fn(array $label): bool => !in_array(mb_strtolower($label['name']), $set, true)));
    }

    /**
     * @param list<string> $actions
     */
    public function withActions(array $actions): self
    {
        return $this->with(actions: $actions);
    }

    /**
     * Copy without any write action, for tokens that can only read.
     */
    public function readOnly(string $missingScope): self
    {
        return $this->with(
            actions: [],
            timeline: array_map(static fn(array $entry): array => array_merge($entry, [
                'canReply' => false,
                'canResolve' => false,
                'comments' => array_map(
                    static fn(array $comment): array => array_merge($comment, ['canEdit' => false, 'canDelete' => false, 'canReact' => false]),
                    $entry['comments']
                ),
            ]), $this->timeline),
            missingScope: $missingScope,
            canReact: false,
        );
    }

    /**
     * Same detail with the to-do/notification done: it no longer offers "mark as done".
     */
    public function withoutTodo(): self
    {
        return $this->with(
            reference: $this->reference->withoutTodo(),
            actions: array_values(array_diff($this->actions, [ItemAction::MARK_DONE])),
        );
    }

    /**
     * All comments of this page of the timeline.
     *
     * @return list<Comment>
     */
    public function comments(): array
    {
        return array_merge([], ...array_column($this->timeline, 'comments'));
    }

    /**
     * @return Comment|null
     */
    public function comment(string $id): ?array
    {
        foreach ($this->comments() as $comment) {
            if ($id !== '' && $comment['id'] === $id) {
                return $comment;
            }
        }
        return null;
    }

    /**
     * @return Entry|null
     */
    public function thread(string $id): ?array
    {
        foreach ($this->timeline as $entry) {
            if ($id !== '' && $entry['type'] === Timeline::THREAD && $entry['id'] === $id) {
                return $entry;
            }
        }
        return null;
    }

    /**
     * What a reaction goes to: the item ("") or a comment of this page, with its current reactions.
     *
     * @return array{subject: string, reactions: list<Reaction>}|null null if the user cannot react to it
     */
    public function reactionTarget(string $id): ?array
    {
        if ($id === '') {
            return $this->canReact && $this->subject !== '' ? ['subject' => $this->subject, 'reactions' => $this->reactions] : null;
        }
        $comment = $this->comment($id);
        return $comment !== null && $comment['canReact'] ? ['subject' => $comment['subject'], 'reactions' => $comment['reactions']] : null;
    }

    /**
     * Whether any comment or thread of the page has the flag, e.g. "canEdit" or "canReply".
     */
    public function anyTimeline(string $flag): bool
    {
        $records = in_array($flag, ['canEdit', 'canDelete', 'canReact'], true) ? $this->comments() : $this->timeline;
        return in_array(true, array_column($records, $flag), true);
    }

    /**
     * Copy with some properties changed; the promoted properties are the constructor's parameters.
     */
    private function with(mixed ...$changes): self
    {
        return new self(...array_merge(get_object_vars($this), $changes));
    }
}
