<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "unity_tracker" by ochorocho.
 */

namespace Ochorocho\UnityTracker\Controller;

use Ochorocho\UnityTracker\Service\FetchError;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\Components\ButtonBar;
use TYPO3\CMS\Backend\Template\Components\ComponentFactory;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Imaging\IconSize;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Messaging\FlashMessageService;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Shared plumbing of the module's controllers: current user, labels, links, messages.
 */
final readonly class ModuleContext
{
    public const MODULE = 'unity_tracker';
    private const LABELS = 'LLL:EXT:unity_tracker/Resources/Private/Language/locallang.xlf:';

    public function __construct(
        private UriBuilder $uriBuilder,
        private IconFactory $iconFactory,
        private FlashMessageService $flashMessageService,
        private ComponentFactory $componentFactory,
    ) {}

    public function backendUser(): BackendUserAuthentication
    {
        $backendUser = $GLOBALS['BE_USER'] ?? null;
        if (!$backendUser instanceof BackendUserAuthentication || !is_array($backendUser->user)) {
            throw new \RuntimeException('The Unity Tracker module needs a logged-in backend user.', 1764072301);
        }
        return $backendUser;
    }

    public function backendUserId(): int
    {
        return (int)($this->backendUser()->user['uid'] ?? 0);
    }

    /**
     * All groups of the user, subgroups included.
     *
     * @return list<int>
     */
    public function backendUserGroups(): array
    {
        return array_values(array_map(intval(...), $this->backendUser()->userGroupsUID));
    }

    public function translate(string $key, string|int ...$arguments): string
    {
        $languageService = $GLOBALS['LANG'] ?? null;
        $label = $languageService instanceof LanguageService ? $languageService->sL(self::LABELS . $key) : '';
        $label = $label !== '' ? $label : $key;
        return $arguments === [] ? $label : vsprintf($label, $arguments);
    }

    public function errorMessage(FetchError $error): string
    {
        return $this->translate('error.fetch.' . $error->reason, $error->connection, $error->detail);
    }

    /**
     * @param array<string, string|int> $parameters
     */
    public function uri(string $route = '', array $parameters = []): string
    {
        return (string)$this->uriBuilder->buildUriFromRoute(self::MODULE . ($route !== '' ? '.' . $route : ''), $parameters);
    }

    public function redirect(string $route = '', array $parameters = []): ResponseInterface
    {
        return new RedirectResponse($this->uri($route, $parameters));
    }

    public function addButton(ModuleTemplate $view, string $href, string $labelKey, string $icon, int $group): void
    {
        $button = $this->componentFactory->createLinkButton()
            ->setHref($href)
            ->setTitle($this->translate($labelKey))
            ->setShowLabelText(true)
            ->setIcon($this->iconFactory->getIcon($icon, IconSize::SMALL));
        $view->getDocHeaderComponent()->getButtonBar()->addButton($button, ButtonBar::BUTTON_POSITION_LEFT, $group);
    }

    /**
     * Shown by the module on the next rendered page (after a redirect).
     */
    public function flash(string $message, ContextualFeedbackSeverity $severity = ContextualFeedbackSeverity::OK, string $title = ''): void
    {
        $this->flashMessageService->getMessageQueueByIdentifier()->enqueue(
            GeneralUtility::makeInstance(FlashMessage::class, $message, $title, $severity, true)
        );
    }
}
