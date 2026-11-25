<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "unity_tracker" by ochorocho.
 */

namespace Ochorocho\UnityTracker\Item;

enum Category: string
{
    /** GitLab to-dos, GitHub notifications */
    case Todo = 'todo';
    case ReviewRequested = 'reviewRequested';
    /** Merge requests / pull requests assigned to the user */
    case AssignedChange = 'assignedChange';
    /** Open merge requests / pull requests opened by the user */
    case AuthoredChange = 'authoredChange';
    case AssignedIssue = 'assignedIssue';

    public function needsAttention(): bool
    {
        return match ($this) {
            self::Todo, self::ReviewRequested => true,
            default => false,
        };
    }
}
