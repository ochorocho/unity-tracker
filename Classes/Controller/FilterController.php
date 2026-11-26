<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "unity_tracker" by ochorocho.
 */

namespace Ochorocho\UnityTracker\Controller;

use Ochorocho\UnityTracker\Filter\FilterDefinition;
use Ochorocho\UnityTracker\Filter\FilterRepository;
use Ochorocho\UnityTracker\Filter\InvalidFilterException;
use Ochorocho\UnityTracker\Filter\SavedFilter;
use Ochorocho\UnityTracker\Filter\ShareableGroups;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;

/**
 * Saved filters: built live in the overview, named and shared with backend groups here.
 */
#[AsController]
final readonly class FilterController
{
    public function __construct(
        private ModuleTemplateFactory $moduleTemplateFactory,
        private ModuleContext $context,
        private FilterRepository $filterRepository,
        private ShareableGroups $shareableGroups,
    ) {}

    public function listAction(ServerRequestInterface $request): ResponseInterface
    {
        $backendUser = $this->context->backendUserId();
        $groups = $this->shareableGroups->forUser($this->context->backendUser());
        $own = [];
        $shared = [];
        foreach ($this->filterRepository->findVisibleFor($backendUser, $this->context->backendUserGroups()) as $filter) {
            $entry = [
                'filter' => $filter,
                'tokens' => $this->describe($filter->definition),
                'groups' => implode(', ', array_map(static fn(int $uid): string => $groups[$uid] ?? '#' . $uid, $filter->groups)),
                'applyUri' => $this->context->uri('', ['filter' => $filter->uid]),
                'editUri' => $this->context->uri('editFilter', ['uid' => $filter->uid]),
            ];
            if ($filter->isOwnedBy($backendUser)) {
                $own[] = $entry;
            } else {
                $shared[] = $entry;
            }
        }

        $view = $this->moduleTemplateFactory->create($request);
        $view->setTitle($this->context->translate('filters.title'));
        $view->assignMultiple([
            'own' => $own,
            'shared' => $shared,
            'deleteUri' => $this->context->uri('deleteFilter'),
        ]);
        return $view->renderResponse('Filters');
    }

    public function newAction(ServerRequestInterface $request): ResponseInterface
    {
        $query = $request->getQueryParams();
        $definition = $this->definition($query['definition'] ?? null);
        if ($definition === null) {
            return $this->context->redirect();
        }
        $title = is_string($query['title'] ?? null) ? $query['title'] : '';
        return $this->form($request, new SavedFilter(0, $this->context->backendUserId(), $title, $definition));
    }

    public function editAction(ServerRequestInterface $request): ResponseInterface
    {
        $query = $request->getQueryParams();
        $filter = $this->filterRepository->findOwned((int)($query['uid'] ?? 0), $this->context->backendUserId());
        if ($filter === null) {
            $this->context->flash($this->context->translate('filters.error.notFound'), ContextualFeedbackSeverity::ERROR);
            return $this->context->redirect('filters');
        }
        // Coming from the overview with a changed filter: save that instead of the stored one.
        if (isset($query['definition'])) {
            $definition = $this->definition($query['definition']);
            if ($definition === null) {
                return $this->context->redirect();
            }
            $filter = new SavedFilter($filter->uid, $filter->owner, $filter->title, $definition, $filter->groups);
        }
        return $this->form($request, $filter);
    }

    public function saveAction(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array)$request->getParsedBody();
        $uid = (int)($body['uid'] ?? 0);
        $definitionJson = is_string($body['definition'] ?? null) ? $body['definition'] : '';
        $title = is_string($body['title'] ?? null) ? mb_substr(trim($body['title']), 0, 255) : '';
        $requestedGroups = is_array($body['groups'] ?? null) ? array_map(intval(...), array_values($body['groups'])) : [];

        $definition = $this->definition($definitionJson);
        if ($definition === null) {
            return $this->context->redirect();
        }
        if ($title === '') {
            $this->context->flash($this->context->translate('filters.error.title'), ContextualFeedbackSeverity::ERROR);
            return $uid > 0
                ? $this->context->redirect('editFilter', ['uid' => $uid, 'definition' => $definitionJson])
                : $this->context->redirect('newFilter', ['definition' => $definitionJson]);
        }

        $backendUser = $this->context->backendUserId();
        $groups = $this->shareableGroups->restrict($this->context->backendUser(), $requestedGroups);
        $savedUid = $this->filterRepository->save(new SavedFilter($uid, $backendUser, $title, $definition, $groups));
        if ($savedUid === 0) {
            $this->context->flash($this->context->translate('filters.error.notFound'), ContextualFeedbackSeverity::ERROR);
            return $this->context->redirect('filters');
        }
        $this->context->flash($this->context->translate('filters.saved', $title));
        return $this->context->redirect('', ['filter' => $savedUid]);
    }

    public function deleteAction(ServerRequestInterface $request): ResponseInterface
    {
        $uid = (int)(((array)$request->getParsedBody())['uid'] ?? 0);
        if ($this->filterRepository->remove($uid, $this->context->backendUserId())) {
            $this->context->flash($this->context->translate('filters.deleted'));
        }
        return $this->context->redirect('filters');
    }

    private function form(ServerRequestInterface $request, SavedFilter $filter): ResponseInterface
    {
        $view = $this->moduleTemplateFactory->create($request);
        $view->setTitle($this->context->translate($filter->uid > 0 ? 'filters.edit' : 'filters.new'));
        $this->context->addButton($view, $this->context->uri('filters'), 'button.close', 'actions-close', 1);

        $groups = [];
        foreach ($this->shareableGroups->forUser($this->context->backendUser()) as $uid => $title) {
            $groups[] = ['uid' => $uid, 'title' => $title, 'checked' => in_array($uid, $filter->groups, true)];
        }
        $view->assignMultiple([
            'filter' => $filter,
            'definition' => $filter->definition->toJson(),
            'tokens' => $this->describe($filter->definition),
            'groups' => $groups,
            'saveUri' => $this->context->uri('saveFilter'),
        ]);
        return $view->renderResponse('FilterForm');
    }

    /**
     * Readable tokens, e.g. [{field: "Author", operator: "is not", value: "bot"}].
     *
     * @return list<array{field: string, operator: string, value: string}>
     */
    private function describe(FilterDefinition $definition): array
    {
        $tokens = [];
        if ($definition->search !== '') {
            $tokens[] = ['field' => $this->context->translate('filter.search'), 'operator' => '', 'value' => $definition->search];
        }
        foreach ($definition->tokens as $token) {
            $tokens[] = [
                'field' => $this->context->translate('filter.field.' . $token['field']),
                'operator' => $this->context->translate('filter.operator.' . $token['operator']),
                'value' => $token['value'],
            ];
        }
        return $tokens;
    }

    /**
     * Null (with a flash message) for a definition that is missing, empty or invalid.
     */
    private function definition(mixed $json): ?FilterDefinition
    {
        try {
            $definition = FilterDefinition::fromJson(is_string($json) ? $json : '');
        } catch (InvalidFilterException) {
            $definition = null;
        }
        if ($definition === null || $definition->isEmpty()) {
            $this->context->flash($this->context->translate('filters.error.definition'), ContextualFeedbackSeverity::ERROR);
            return null;
        }
        return $definition;
    }
}
