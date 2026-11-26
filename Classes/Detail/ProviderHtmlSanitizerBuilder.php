<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "unity_tracker" by ochorocho.
 */

namespace Ochorocho\UnityTracker\Detail;

use TYPO3\CMS\Core\Html\DefaultSanitizerBuilder;
use TYPO3\HtmlSanitizer\Behavior;
use TYPO3\HtmlSanitizer\Behavior\Attr;
use TYPO3\HtmlSanitizer\Behavior\RegExpAttrValue;
use TYPO3\HtmlSanitizer\Behavior\Tag;

/**
 * The core's default sanitiser plus the checkboxes of task lists ("- [x] done"). ProviderHtml::prepare() has
 * reduced every <input> to `<input type="checkbox" disabled|data-task-index="n" [checked] [aria-label]>`.
 */
final class ProviderHtmlSanitizerBuilder extends DefaultSanitizerBuilder
{
    protected function createBehavior(): Behavior
    {
        return parent::createBehavior()
            ->withName('unity_tracker')
            ->withTags(
                (new Tag('input', Tag::PURGE_WITHOUT_ATTRS))->addAttrs(
                    (new Attr('type'))->addValues(new RegExpAttrValue('#^checkbox$#')),
                    (new Attr('disabled'))->addValues(new RegExpAttrValue('#^(|disabled)$#')),
                    (new Attr('checked'))->addValues(new RegExpAttrValue('#^(|checked)$#')),
                    (new Attr('data-task-index'))->addValues(new RegExpAttrValue('#^\d{1,4}$#')),
                    new Attr('aria-label'),
                    ...$this->globalAttrs,
                )
            );
    }
}
