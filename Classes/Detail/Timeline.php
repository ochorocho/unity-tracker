<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "unity_tracker" by ochorocho.
 */

namespace Ochorocho\UnityTracker\Detail;

/**
 * Builds the entries of ItemDetail::$timeline, so both providers produce the same shape.
 *
 * - event: something happened ("jane added label bug"). GitLab sends it as HTML (system note),
 *   GitHub as structured data: `event` is the label key suffix `item.event.*`, `subject` its argument.
 * - thread: comments that belong together. A plain comment is a thread with one comment.
 *
 * Comments carry their reactions; `subject` is the provider's id to react with (GitLab award-emoji
 * awardable id, GitHub node id).
 *
 * @phpstan-import-type Reaction from Reactions
 * @phpstan-type Comment array{id: string, kind: string, number: int, author: string, createdAt: ?\DateTimeImmutable, html: string, body: string, canEdit: bool, canDelete: bool, reactions: list<Reaction>, subject: string, canReact: bool, internal: bool}
 * @phpstan-type Entry array{type: string, id: string, author: string, createdAt: ?\DateTimeImmutable, event: string, subject: string, html: string, review: string, resolvable: bool, resolved: bool, canReply: bool, canResolve: bool, replyTo: string, path: string, line: int, url: string, comments: list<Comment>}
 */
final class Timeline
{
    public const EVENT = 'event';
    public const THREAD = 'thread';

    /**
     * @return Entry
     */
    public static function event(string $id, string $author, ?\DateTimeImmutable $createdAt, string $event, string $subject = '', string $html = ''): array
    {
        return array_merge(self::entry(self::EVENT, $id, $author, $createdAt), ['event' => $event, 'subject' => $subject, 'html' => $html]);
    }

    /**
     * @param list<Comment> $comments
     * @param array{resolvable?: bool, resolved?: bool, canReply?: bool, canResolve?: bool, replyTo?: string, path?: string, line?: int, url?: string, review?: string} $options
     * @return Entry
     */
    public static function thread(string $id, array $comments, array $options = []): array
    {
        $first = $comments[0] ?? null;
        return array_merge(self::entry(self::THREAD, $id, $first['author'] ?? '', $first['createdAt'] ?? null), $options, ['comments' => $comments]);
    }

    /**
     * @param string $kind what the provider needs to edit/delete it (GitLab "note", GitHub "issue", "review", "pullReview")
     * @param list<Reaction> $reactions
     * @return Comment
     */
    public static function comment(
        string $kind,
        int $number,
        string $author,
        ?\DateTimeImmutable $createdAt,
        string $body,
        string $html,
        bool $canEdit,
        bool $canDelete,
        array $reactions = [],
        string $subject = '',
        bool $canReact = false,
        bool $internal = false,
    ): array {
        return [
            // GitLab notes keep their plain id, GitHub ids are only unique per kind.
            'id' => $kind === 'note' || $kind === 'issue' ? (string)$number : $kind . '-' . $number,
            'kind' => $kind,
            'number' => $number,
            'author' => $author,
            'createdAt' => $createdAt,
            'html' => $html,
            'body' => $body,
            'canEdit' => $canEdit,
            'canDelete' => $canDelete,
            'reactions' => $reactions,
            'subject' => $subject,
            'canReact' => $canReact && $subject !== '',
            // GitLab: only visible to members with at least the Reporter role
            'internal' => $internal,
        ];
    }

    /**
     * @return Entry
     */
    private static function entry(string $type, string $id, string $author, ?\DateTimeImmutable $createdAt): array
    {
        return [
            'type' => $type,
            'id' => $id,
            'author' => $author,
            'createdAt' => $createdAt,
            'event' => '',
            'subject' => '',
            'html' => '',
            'review' => '',
            'resolvable' => false,
            'resolved' => false,
            'canReply' => false,
            'canResolve' => false,
            'replyTo' => '',
            'path' => '',
            'line' => 0,
            'url' => '',
            'comments' => [],
        ];
    }
}
