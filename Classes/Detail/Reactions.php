<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "unity_tracker" by ochorocho.
 */

namespace Ochorocho\UnityTracker\Detail;

/**
 * Emoji reactions of the item and its comments. Names are GitLab's award emoji names; GitHub's
 * eight reactions are mapped onto them. GitLab items may carry any other emoji as well.
 *
 * @phpstan-type Reaction array{name: string, emoji: string, count: int, reacted: bool}
 */
final class Reactions
{
    /** The reactions both providers offer, in GitHub's order: name → emoji */
    public const CHOICES = [
        'thumbsup' => '👍', 'thumbsdown' => '👎', 'laughing' => '😄', 'tada' => '🎉',
        'confused' => '😕', 'heart' => '❤️', 'rocket' => '🚀', 'eyes' => '👀',
    ];

    /** GitHub ReactionContent → name */
    public const GITHUB = [
        'THUMBS_UP' => 'thumbsup', 'THUMBS_DOWN' => 'thumbsdown', 'LAUGH' => 'laughing', 'HOORAY' => 'tada',
        'CONFUSED' => 'confused', 'HEART' => 'heart', 'ROCKET' => 'rocket', 'EYES' => 'eyes',
    ];

    /**
     * GitLab awards (one per user and emoji) → reactions, counted per name, the eight choices first.
     *
     * @param list<array{name: string, emoji: string, user: string}> $awards
     * @return list<Reaction>
     */
    public static function fromAwards(array $awards, string $viewer): array
    {
        $reactions = [];
        foreach ($awards as $award) {
            $name = $award['name'];
            if (!self::isName($name)) {
                continue;
            }
            $reactions[$name] ??= self::reaction($name, 0, false, $award['emoji']);
            $reactions[$name]['count']++;
            $reactions[$name]['reacted'] = $reactions[$name]['reacted'] || ($viewer !== '' && $award['user'] === $viewer);
        }
        return self::sorted(array_values($reactions));
    }

    /**
     * @return Reaction
     */
    public static function reaction(string $name, int $count, bool $reacted, string $emoji = ''): array
    {
        // Only one emoji (a few code points) is shown: GitLab's "emoji" field is provider data.
        $emoji = mb_strlen($emoji) <= 8 ? $emoji : '';
        return ['name' => $name, 'emoji' => self::CHOICES[$name] ?? ($emoji !== '' ? $emoji : ':' . $name . ':'), 'count' => $count, 'reacted' => $reacted];
    }

    /**
     * @param list<Reaction> $reactions
     * @return list<Reaction>
     */
    public static function sorted(array $reactions): array
    {
        $order = array_flip(array_keys(self::CHOICES));
        usort($reactions, static fn(array $a, array $b): int => ($order[$a['name']] ?? 99) <=> ($order[$b['name']] ?? 99));
        return $reactions;
    }

    /**
     * Award emoji names as GitLab uses them ("thumbsup", "+1", "flag_de").
     */
    public static function isName(string $name): bool
    {
        return preg_match('/^[a-z0-9_+-]{1,64}$/', $name) === 1;
    }
}
