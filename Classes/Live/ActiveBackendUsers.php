<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "unity_tracker" by ochorocho.
 */

namespace Ochorocho\UnityTracker\Live;

use Psr\Clock\ClockInterface;
use TYPO3\CMS\Core\Session\SessionManager;

/**
 * Backend users with a session that has not timed out: the ones whose overview may be open.
 */
final readonly class ActiveBackendUsers
{
    public function __construct(
        private SessionManager $sessionManager,
        private ClockInterface $clock,
    ) {}

    /**
     * @return list<int> be_users uids
     */
    public function ids(): array
    {
        $timeout = (int)($GLOBALS['TYPO3_CONF_VARS']['BE']['sessionTimeout'] ?? 28800);
        $since = $this->clock->now()->getTimestamp() - $timeout;
        $users = [];
        foreach ($this->sessionManager->getSessionBackend('BE')->getAll() as $session) {
            $user = (int)($session['ses_userid'] ?? 0);
            if ($user > 0 && (int)($session['ses_tstamp'] ?? 0) >= $since) {
                $users[$user] = $user;
            }
        }
        return array_values($users);
    }
}
