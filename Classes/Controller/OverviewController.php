<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "unity_tracker" by ochorocho.
 */

namespace Ochorocho\UnityTracker\Controller;

use Ochorocho\UnityTracker\Connection\ConnectionRepository;
use Ochorocho\UnityTracker\Filter\FilterDefinition;
use Ochorocho\UnityTracker\Filter\FilterRepository;
use Ochorocho\UnityTracker\Filter\SavedFilter;
use Ochorocho\UnityTracker\Item\Category;
use Ochorocho\UnityTracker\Service\FetchError;
use Ochorocho\UnityTracker\Service\Overview;
use Ochorocho\UnityTracker\Service\OverviewService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Page\PageRenderer;

/**
 * Renders the <unity-tracker-overview> web component: filter bar, tabs and tables run in the browser.
 */
#[AsController]
final readonly class OverviewController
{
    /** Label keys the web components need. */
    private const COMPONENT_LABELS = [
        'overview.attention', 'overview.allDone', 'overview.nothing', 'item.draft', 'item.openExternal',
        'column.title', 'column.project', 'column.reason', 'column.author', 'column.updated', 'column.connection',
        'sort.ascending', 'sort.descending',
        'filter.search', 'filter.placeholder', 'filter.clear', 'filter.save', 'filter.update', 'filter.saved',
        'filter.savedNone', 'filter.manage', 'filter.own', 'filter.shared', 'filter.searchFor', 'filter.noResults',
        'filter.removeToken', 'filter.operator.is', 'filter.operator.isNot', 'filter.value.yes', 'filter.value.no',
    ];

    public function __construct(
        private ModuleTemplateFactory $moduleTemplateFactory,
        private ModuleContext $context,
        private ConnectionRepository $connectionRepository,
        private OverviewService $overviewService,
        private FilterRepository $filterRepository,
        private PageRenderer $pageRenderer,
        private ItemTablePresenter $itemTablePresenter,
    ) {}

    public function overviewAction(ServerRequestInterface $request): ResponseInterface
    {
        $query = $request->getQueryParams();
        $backendUser = $this->context->backendUserId();
        $hasConnections = $this->connectionRepository->findByBackendUser($backendUser) !== [];
        $overview = $hasConnections
            ? $this->overviewService->forBackendUser($backendUser, (bool)($query['refresh'] ?? false))
            : null;
        $savedFilters = $this->filterRepository->findVisibleFor($backendUser, $this->context->backendUserGroups());

        $view = $this->moduleTemplateFactory->create($request);
        $view->setTitle($this->context->translate('overview.title'));
        if ($hasConnections) {
            $this->context->addButton($view, $this->context->uri('', ['refresh' => 1]), 'button.refresh', 'actions-refresh', 1);
        }
        $this->context->addButton($view, $this->context->uri('connections'), 'button.connections', 'actions-link', 2);
        $this->context->addButton($view, $this->context->uri('filters'), 'button.filters', 'actions-filter', 2);
        $this->pageRenderer->loadJavaScriptModule('@ochorocho/unity-tracker/overview.js');

        $view->assignMultiple([
            'hasConnections' => $hasConnections,
            'overview' => $overview,
            'errors' => $overview !== null ? $this->errors($overview) : [],
            'component' => $overview !== null ? $this->componentData($overview, $savedFilters, $backendUser, (int)($query['filter'] ?? 0)) : [],
            'newConnectionUri' => $this->context->uri('newConnection'),
        ]);
        return $view->renderResponse('Overview');
    }

    /**
     * Attribute values of <unity-tracker-overview>, each JSON encoded.
     *
     * @param list<SavedFilter> $savedFilters
     * @return array<string, string>
     */
    private function componentData(Overview $overview, array $savedFilters, int $backendUser, int $activeFilter): array
    {
        $categories = array_map(static fn(Category $category): array => [
            'key' => $category->value,
            'attention' => $category->needsAttention(),
        ], Category::cases());

        $labels = [];
        foreach (self::COMPONENT_LABELS as $key) {
            $labels[$key] = $this->context->translate($key);
        }
        foreach (Category::cases() as $category) {
            $labels['category.' . $category->value] = $this->context->translate('category.' . $category->value);
        }
        foreach (FilterDefinition::FIELDS as $field) {
            $labels['filter.field.' . $field] = $this->context->translate('filter.field.' . $field);
        }

        $filters = array_map(static fn(SavedFilter $filter): array => [
            'uid' => $filter->uid,
            'title' => $filter->title,
            'definition' => $filter->definition,
            'own' => $filter->isOwnedBy($backendUser),
            'owner' => $filter->ownerName,
        ], $savedFilters);

        return array_map(static fn(mixed $value): string => json_encode($value, JSON_THROW_ON_ERROR), [
            'items' => $this->itemTablePresenter->rows($overview->items),
            'categories' => $categories,
            'labels' => $labels,
            'filters' => $filters,
            'activeFilter' => $activeFilter,
            'urls' => [
                'overview' => $this->context->uri(),
                'item' => $this->context->uri('item'),
                'newFilter' => $this->context->uri('newFilter'),
                'editFilter' => $this->context->uri('editFilter'),
                'filters' => $this->context->uri('filters'),
            ],
        ]);
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
