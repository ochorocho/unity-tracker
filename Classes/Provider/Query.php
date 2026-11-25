<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "unity_tracker" by ochorocho.
 */

namespace Ochorocho\UnityTracker\Provider;

use Ochorocho\UnityTracker\Item\Category;
use Psr\Http\Message\RequestInterface;

/**
 * An API request whose response becomes items of one category.
 */
final readonly class Query
{
    public function __construct(
        public Category $category,
        public RequestInterface $request,
    ) {}
}
