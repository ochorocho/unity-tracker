<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "unity_tracker" by ochorocho.
 */

namespace Ochorocho\UnityTracker\Filter;

/**
 * A filter definition that is not valid JSON, uses unknown fields or operators, or exceeds the limits.
 */
final class InvalidFilterException extends \InvalidArgumentException {}
