<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "unity_tracker" by ochorocho.
 */

namespace Ochorocho\UnityTracker\Service;

final class FetchFailedException extends \RuntimeException
{
    public function __construct(public readonly FetchError $error, ?\Throwable $previous = null)
    {
        parent::__construct(sprintf('Loading "%s" failed: %s %s', $error->connection, $error->reason, $error->detail), 1764072201, $previous);
    }
}
