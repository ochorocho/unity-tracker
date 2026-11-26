<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "unity_tracker" by ochorocho.
 */

namespace Ochorocho\UnityTracker\Detail;

use TYPO3\CMS\Core\Html\SanitizerInitiator;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * HTML rendered by GitLab/GitHub, made fit for the backend.
 */
final readonly class ProviderHtml
{
    /**
     * - GitLab's relative links ("/group/project/-/issues/1") become absolute,
     * - custom elements are unwrapped, the sanitiser would show them as text: emoji (<gl-emoji>, <g-emoji>)
     *   keep their content, GitLab's empty code block widgets (<copy-code>, <insert-code-snippet>) vanish,
     * - images load through $imageUrl (ImageProxy): the backend's CSP blocks other origins. GitLab lazy-loads
     *   images (placeholder in src, the image in data-src), so data-src wins,
     * - task list checkboxes become plain disabled checkboxes, any other <input> is dropped
     *   (ProviderHtmlSanitizerBuilder allows exactly these). With $editableTasks they stay enabled and carry
     *   data-task-index, their position for TaskList::toggle(); GitLab's "not applicable" ones stay disabled.
     *
     * Not sanitised: templates use f:sanitize.html with ProviderHtmlSanitizerBuilder, sanitize() does both.
     *
     * @param callable(string): string $imageUrl absolute image URL → URL the backend serves it from, '' if it cannot
     */
    public function prepare(string $html, string $host, callable $imageUrl, bool $editableTasks = false): string
    {
        $index = 0;
        $html = (string)preg_replace('#</?[a-z][a-z0-9]*-[a-z0-9-]*(?:\s[^>]*)?>#i', '', $html);
        $html = (string)preg_replace('#\b(href|src|data-src)="/(?!/)#', '$1="https://' . $host . '/', $html);
        $html = (string)preg_replace_callback('#<input\b[^>]*>#i', static function (array $match) use ($editableTasks, &$index): string {
            if (preg_match('#\stype="checkbox"#i', $match[0]) !== 1) {
                return '';
            }
            $label = preg_match('#\saria-label="([^"]*)"#i', $match[0], $found) === 1 ? ' aria-label="' . $found[1] . '"' : '';
            $checked = preg_match('#\schecked(?:="[^"]*")?(?=[\s/>])#i', $match[0]) === 1 ? ' checked' : '';
            $position = $index++;
            if (!$editableTasks || preg_match('#\sdata-inapplicable\b#i', $match[0]) === 1) {
                return '<input type="checkbox" disabled' . $checked . $label . '>';
            }
            return '<input type="checkbox" data-task-index="' . $position . '"' . $checked . $label . '>';
        }, $html);
        return (string)preg_replace_callback('#<img\b[^>]*>#i', static function (array $match) use ($imageUrl): string {
            $attributes = [];
            // Whole attribute names only: GitHub's data-canonical-src must not count as src.
            preg_match_all('#\s(data-src|src|alt|title|width|height)="([^"]*)"#i', $match[0], $found, PREG_SET_ORDER);
            foreach ($found as [, $name, $value]) {
                $attributes[strtolower($name)] = html_entity_decode($value, ENT_QUOTES | ENT_HTML5);
            }
            $source = $attributes['data-src'] ?? $attributes['src'] ?? '';
            $proxied = str_starts_with($source, 'https://') ? $imageUrl($source) : '';
            if ($proxied === '') {
                // An image the backend cannot show: its description instead.
                return htmlspecialchars($attributes['alt'] ?? '');
            }
            $tag = '<img src="' . htmlspecialchars($proxied) . '" loading="lazy"';
            foreach (['alt', 'title', 'width', 'height'] as $name) {
                if (isset($attributes[$name])) {
                    $tag .= ' ' . $name . '="' . htmlspecialchars($attributes[$name]) . '"';
                }
            }
            return $tag . '>';
        }, $html);
    }

    /**
     * For HTML that is inserted by JavaScript (the Markdown preview).
     *
     * @param callable(string): string $imageUrl
     */
    public function sanitize(string $html, string $host, callable $imageUrl): string
    {
        return GeneralUtility::makeInstance(ProviderHtmlSanitizerBuilder::class)->build()
            ->sanitize($this->prepare($html, $host, $imageUrl), new SanitizerInitiator(self::class));
    }
}
