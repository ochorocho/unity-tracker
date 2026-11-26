<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "unity_tracker" by ochorocho.
 */

namespace Ochorocho\UnityTracker\Detail;

/**
 * Ticks task list items ("- [ ] todo") in Markdown. Items are counted in document order, like the
 * checkboxes of the rendered HTML (ProviderHtml::prepare() numbers them the same way).
 */
final class TaskList
{
    /** List marker (also in block quotes), then "[ ]", "[x]" or GitLab's "[~]" (not applicable) */
    private const TASK = '/^((?:[ \t]*>)*[ \t]*(?:[-*+]|\d{1,9}[.)])[ \t]+\[)([ xX~])(\](?:[ \t]|$))/';
    private const FENCE = '/^(?:[ \t]*>)*[ \t]*(`{3,}|~{3,})/';

    /**
     * @param int $index 0-based, counted over all task items outside code blocks
     * @param bool $inapplicable whether "[~]" is a task item (GitLab renders it as a disabled checkbox)
     * @return string|null the changed Markdown, null if there is no such item or it cannot be ticked
     */
    public static function toggle(string $markdown, int $index, bool $checked, bool $inapplicable = false): ?string
    {
        $lines = preg_split('/(\r\n|\n|\r)/', $markdown, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        $fence = '';
        $count = 0;
        // Even keys are lines, odd keys their line breaks.
        for ($key = 0; $key < count($lines); $key += 2) {
            $line = $lines[$key];
            if (preg_match(self::FENCE, $line, $match) === 1) {
                if ($fence === '') {
                    $fence = $match[1];
                } elseif ($match[1][0] === $fence[0] && strlen($match[1]) >= strlen($fence) && trim(substr($line, strpos($line, $match[1]) + strlen($match[1]))) === '') {
                    $fence = '';
                }
                continue;
            }
            if ($fence !== '' || preg_match(self::TASK, $line, $match) !== 1 || ($match[2] === '~' && !$inapplicable)) {
                continue;
            }
            if ($count++ !== $index) {
                continue;
            }
            if ($match[2] === '~') {
                return null;
            }
            $lines[$key] = $match[1] . ($checked ? 'x' : ' ') . $match[3] . substr($line, strlen($match[0]));
            return implode('', $lines);
        }
        return null;
    }
}
