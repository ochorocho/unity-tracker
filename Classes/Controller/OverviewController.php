<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "unity_tracker" by ochorocho.
 */

namespace Ochorocho\UnityTracker\Controller;

use Ochorocho\UnityTracker\Connection\ConnectionRepository;
use Ochorocho\UnityTracker\Item\Category;
use Ochorocho\UnityTracker\Service\FetchError;
use Ochorocho\UnityTracker\Service\Overview;
use Ochorocho\UnityTracker\Service\OverviewService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;

#[AsController]
final readonly class OverviewController
{
    public function __construct(
        private ModuleTemplateFactory $moduleTemplateFactory,
        private ModuleContext $context,
        private ConnectionRepository $connectionRepository,
        private OverviewService $overviewService,
    ) {}

    public function overviewAction(ServerRequestInterface $request): ResponseInterface
    {
        $backendUser = $this->context->backendUserId();
        $hasConnections = $this->connectionRepository->findByBackendUser($backendUser) !== [];
        $overview = $hasConnections
            ? $this->overviewService->forBackendUser($backendUser, (bool)($request->getQueryParams()['refresh'] ?? false))
            : null;

        $view = $this->moduleTemplateFactory->create($request);
        $view->setTitle($this->context->translate('overview.title'));
        if ($hasConnections) {
            $this->context->addButton($view, $this->context->uri('', ['refresh' => 1]), 'button.refresh', 'actions-refresh', 1);
        }
        $this->context->addButton($view, $this->context->uri('connections'), 'button.connections', 'actions-link', 2);

        $view->assignMultiple([
            'hasConnections' => $hasConnections,
            'overview' => $overview,
            'errors' => $overview !== null ? $this->errors($overview) : [],
            'attention' => $overview !== null ? $this->sections($overview, true) : [],
            'work' => $overview !== null ? $this->sections($overview, false) : [],
            'attentionCount' => $overview?->attentionCount() ?? 0,
            'connectionsUri' => $this->context->uri('connections'),
        ]);
        return $view->renderResponse('Overview');
    }

    /**
     * @return list<array{category: string, items: list<\Ochorocho\UnityTracker\Item\Item>}>
     */
    private function sections(Overview $overview, bool $needsAttention): array
    {
        $sections = [];
        foreach (Category::cases() as $category) {
            if ($category->needsAttention() === $needsAttention) {
                $sections[] = ['category' => $category->value, 'items' => $overview->byCategory($category)];
            }
        }
        return $sections;
    }

    /**
     * @return list<array{title: string, message: string}>
     */
    private function errors(Overview $overview): array
    {
        return array_map(fn(FetchError $error): array => [
            'title' => $this->context->translate('error.title', $error->connection),
            'message' => $this->context->errorMessage($error),
        ], $overview->errors);
    }
}
