<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "unity_tracker" by ochorocho.
 */

namespace Ochorocho\UnityTracker\Detail;

/**
 * What the merge widget of a merge/pull request shows: pipeline, approvals, what blocks the merge and the
 * merge options. Which merge actions the user may do is in ItemDetail::$actions.
 */
final readonly class MergeState
{
    public const PIPELINE_STATES = ['success', 'failed', 'running', 'pending', 'canceled', 'skipped', 'manual'];

    /**
     * @param string $pipeline one of PIPELINE_STATES, "" without pipeline/checks
     * @param string $pipelineText the provider's wording ("passed with warnings"), may be ""
     * @param int $approvalsLeft GitLab approval rules; 0 if none or not supported
     * @param string $reviewDecision GitHub: "approved", "changes_requested", "review_required" or ""
     * @param list<string> $blockers label key suffixes item.blocker.* (GitLab's mergeability check identifiers, lowercase)
     * @param list<string> $methods GitHub merge methods the repository allows ("merge", "squash", "rebase"); [] on GitLab
     * @param bool $squash GitLab: squash by default; $squashLocked: the project decides
     * @param bool $deleteBranch delete the source branch by default (GitLab) or by repository setting (GitHub)
     * @param bool $canDeleteBranch GitLab: whether the user may choose to delete the source branch on merge
     * @param string $headSha the commit the user sees: merging is refused if the branch moved on
     * @param string $branchRef GitHub: node id of the head branch, for deleting it after the merge
     */
    public function __construct(
        public string $pipeline = '',
        public string $pipelineText = '',
        public string $pipelineUrl = '',
        public int $approvalsRequired = 0,
        public int $approvalsLeft = 0,
        public string $reviewDecision = '',
        public array $blockers = [],
        public array $methods = [],
        public bool $squash = false,
        public bool $squashLocked = false,
        public bool $deleteBranch = false,
        public bool $canDeleteBranch = false,
        public bool $autoMerge = false,
        public string $headSha = '',
        public string $branchRef = '',
    ) {}

    /**
     * Badge class suffix of the pipeline state ({merge.pipelineBadge} in templates).
     */
    public function getPipelineBadge(): string
    {
        return match ($this->pipeline) {
            'success' => 'success',
            'failed' => 'danger',
            'running', 'pending' => 'warning',
            default => 'default',
        };
    }

    public function getApprovalsGiven(): int
    {
        return max(0, $this->approvalsRequired - $this->approvalsLeft);
    }
}
