<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "unity_tracker" by ochorocho.
 */

namespace Ochorocho\UnityTracker\Detail;

/**
 * Actions of the detail sheet. Which of them a user gets is decided by the provider from the API's
 * permissions (ItemDetail::$actions) and checked again before an action is sent.
 */
final class ItemAction
{
    public const COMMENT = 'comment';
    public const EDIT = 'edit';
    public const CLOSE = 'close';
    public const REOPEN = 'reopen';
    public const ASSIGN_ME = 'assignMe';
    public const UNASSIGN_ME = 'unassignMe';
    public const ADD_LABEL = 'addLabel';
    public const REMOVE_LABEL = 'removeLabel';
    public const APPROVE = 'approve';
    public const UNAPPROVE = 'unapprove';
    public const REQUEST_CHANGES = 'requestChanges';
    public const MERGE = 'merge';
    public const MARK_DONE = 'markDone';
    /** Only the user's own comments, see ItemDetail::$comments[]['canEdit'] */
    public const EDIT_COMMENT = 'editComment';
    public const DELETE_COMMENT = 'deleteComment';
    /** Threads: GitLab discussions, GitHub review threads */
    public const REPLY = 'reply';
    public const RESOLVE = 'resolve';
    public const UNRESOLVE = 'unresolve';
    /** Toggles an emoji on the item or a comment, see Reactions */
    public const REACT = 'react';
    /** Ticks a task list item of the description or a comment: sent as EDIT / EDIT_COMMENT */
    public const TOGGLE_TASK = 'toggleTask';
    /** Sidebar: anybody as assignee/reviewer (parameter "user"), milestone, due date, confidentiality, lock, notifications */
    public const ADD_ASSIGNEE = 'addAssignee';
    public const REMOVE_ASSIGNEE = 'removeAssignee';
    public const ADD_REVIEWER = 'addReviewer';
    public const REMOVE_REVIEWER = 'removeReviewer';
    public const SET_MILESTONE = 'setMilestone';
    public const SET_DUE_DATE = 'setDueDate';
    public const SET_CONFIDENTIAL = 'setConfidential';
    public const LOCK = 'lock';
    public const UNLOCK = 'unlock';
    public const SUBSCRIBE = 'subscribe';
    public const UNSUBSCRIBE = 'unsubscribe';

    public const ALL = [
        self::COMMENT, self::EDIT, self::CLOSE, self::REOPEN, self::ASSIGN_ME, self::UNASSIGN_ME, self::ADD_LABEL,
        self::REMOVE_LABEL, self::APPROVE, self::UNAPPROVE, self::REQUEST_CHANGES, self::MERGE, self::MARK_DONE,
        self::EDIT_COMMENT, self::DELETE_COMMENT, self::REPLY, self::RESOLVE, self::UNRESOLVE, self::REACT, self::TOGGLE_TASK,
        self::ADD_ASSIGNEE, self::REMOVE_ASSIGNEE, self::ADD_REVIEWER, self::REMOVE_REVIEWER, self::SET_MILESTONE, self::SET_DUE_DATE,
        self::SET_CONFIDENTIAL, self::LOCK, self::UNLOCK, self::SUBSCRIBE, self::UNSUBSCRIBE,
    ];

    public const MAX_COMMENT = 65536;
    public const MAX_TITLE = 255;
    public const MAX_DESCRIPTION = 1048576;
}
