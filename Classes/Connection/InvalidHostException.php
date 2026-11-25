<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "unity_tracker" by ochorocho.
 */

namespace Ochorocho\UnityTracker\Connection;

final class InvalidHostException extends \InvalidArgumentException
{
    public const MALFORMED = 'malformed';
    public const NOT_HTTPS = 'notHttps';
    public const NOT_ALLOWED = 'notAllowed';

    /**
     * @param self::* $reason used as label key suffix in the module
     */
    public function __construct(public readonly string $reason, public readonly string $host, int $code)
    {
        parent::__construct(sprintf('Host "%s" rejected: %s', $host, $reason), $code);
    }
}
