<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "unity_tracker" by ochorocho.
 */

namespace Ochorocho\UnityTracker\Controller;

use Ochorocho\UnityTracker\Item\Item;

/**
 * Turns items into the rows the <unity-tracker-item-table> web component renders.
 */
final readonly class ItemTablePresenter
{
    private const DATE_FORMAT = 'd.m.Y H:i';

    /**
     * @param list<Item> $items
     * @return list<array{connectionUid: int, number: int, todoId: string, category: string, type: string, title: string, url: string, project: string, reference: string, author: string, reason: string, labels: list<string>, draft: bool, updatedAt: string, updatedAtFormatted: string, connection: string, provider: string}>
     */
    public function rows(array $items): array
    {
        return array_map(static function (Item $item): array {
            // Providers fall back to the epoch when an API returns no date: show none.
            $hasDate = $item->updatedAt->getTimestamp() !== 0;
            return [
                'connectionUid' => $item->connectionUid,
                'number' => $item->number,
                'todoId' => $item->todoId,
                'category' => $item->category->value,
                'type' => $item->type,
                'title' => $item->title,
                'url' => $item->url,
                'project' => $item->project,
                'reference' => $item->reference,
                'author' => $item->author,
                'reason' => $item->reason,
                'labels' => $item->labels,
                'draft' => $item->draft,
                'updatedAt' => $hasDate ? $item->updatedAt->format(\DateTimeInterface::ATOM) : '',
                'updatedAtFormatted' => $hasDate ? $item->updatedAt->format(self::DATE_FORMAT) : '',
                'connection' => $item->connection,
                'provider' => $item->provider->name,
            ];
        }, $items);
    }
}
