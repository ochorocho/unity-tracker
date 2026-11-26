<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "unity_tracker" by ochorocho.
 */

namespace Ochorocho\UnityTracker\Detail;

/**
 * An action the user may not do, or invalid input for it. The message is a label key suffix (error.action.*).
 */
final class InvalidActionException extends \InvalidArgumentException {}
