<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "unity_tracker" by ochorocho.
 */

namespace Ochorocho\UnityTracker\Tests\Unit\Detail;

use Ochorocho\UnityTracker\Detail\ProviderHtml;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class ProviderHtmlTest extends UnitTestCase
{
    private ProviderHtml $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = new ProviderHtml();
    }

    #[Test]
    public function lazyGitLabImageLoadsThroughTheProxy(): void
    {
        $html = '<a href="/-/project/7/uploads/abc/shot.png"><img class="lazy gfm" src="data:image/gif;base64,R0lGOD" data-src="/-/project/7/uploads/abc/shot.png" alt="A &amp; B"></a>';

        self::assertSame(
            '<a href="https://gitlab.com/-/project/7/uploads/abc/shot.png"><img src="proxy:https://gitlab.com/-/project/7/uploads/abc/shot.png" loading="lazy" alt="A &amp; B"></a>',
            $this->subject->prepare($html, 'gitlab.com', $this->proxy(...))
        );
    }

    #[Test]
    public function githubCanonicalSourceIsNotTheSource(): void
    {
        $html = '<img src="https://camo.githubusercontent.com/abc" data-canonical-src="https://img.shields.io/badge.svg" style="max-width: 100%;">';

        self::assertSame(
            '<img src="proxy:https://camo.githubusercontent.com/abc" loading="lazy">',
            $this->subject->prepare($html, 'github.com', $this->proxy(...))
        );
    }

    #[Test]
    public function imageTheBackendCannotLoadShowsItsDescription(): void
    {
        $html = '<p><img src="http://insecure.example.com/a.png" alt="Diagram &lt;1&gt;"></p>';

        self::assertSame('<p>Diagram &lt;1&gt;</p>', $this->subject->prepare($html, 'gitlab.com', static fn(string $url): string => ''));
    }

    #[Test]
    public function taskListCheckboxesStayCheckboxes(): void
    {
        $html = '<li class="task-list-item"><input type="checkbox" data-checkbox-sourcepos="3:4-3:4" class="task-list-item-checkbox" checked disabled aria-label="Check option: Done"> Done</li>'
            . '<li><input type="checkbox" class="task-list-item-checkbox" disabled="" aria-label="Open"> Open</li>'
            . '<li><input type="text" name="x" value="y"> Text</li>';

        self::assertSame(
            '<li class="task-list-item"><input type="checkbox" disabled checked aria-label="Check option: Done"> Done</li>'
            . '<li><input type="checkbox" disabled aria-label="Open"> Open</li>'
            . '<li> Text</li>',
            $this->subject->prepare($html, 'gitlab.com', $this->proxy(...))
        );
    }

    #[Test]
    public function emojiElementsAreUnwrapped(): void
    {
        self::assertSame('<p>Yay 🎉</p>', $this->subject->prepare('<p>Yay <gl-emoji title="party">🎉</gl-emoji></p>', 'gitlab.com', $this->proxy(...)));
    }

    #[Test]
    public function gitLabCodeBlockWidgetsAreRemoved(): void
    {
        self::assertSame(
            '<div class="gl-relative markdown-code-block"><pre><code>x();</code></pre></div>',
            $this->subject->prepare(
                '<div class="gl-relative markdown-code-block"><pre><code>x();</code></pre><copy-code></copy-code><insert-code-snippet></insert-code-snippet></div>',
                'gitlab.com',
                $this->proxy(...)
            )
        );
    }

    private function proxy(string $url): string
    {
        return 'proxy:' . $url;
    }
}
