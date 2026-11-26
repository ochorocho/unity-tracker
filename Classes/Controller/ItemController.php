<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "unity_tracker" by ochorocho.
 */

namespace Ochorocho\UnityTracker\Controller;

use Ochorocho\UnityTracker\Connection\Connection;
use Ochorocho\UnityTracker\Connection\ConnectionRepository;
use Ochorocho\UnityTracker\Connection\Provider;
use Ochorocho\UnityTracker\Detail\ImageProxy;
use Ochorocho\UnityTracker\Detail\InvalidActionException;
use Ochorocho\UnityTracker\Detail\ItemAction;
use Ochorocho\UnityTracker\Detail\ItemDetail;
use Ochorocho\UnityTracker\Detail\ItemDetailService;
use Ochorocho\UnityTracker\Detail\ItemReference;
use Ochorocho\UnityTracker\Detail\ProviderHtml;
use Ochorocho\UnityTracker\Detail\Reactions;
use Ochorocho\UnityTracker\Service\FetchError;
use Ochorocho\UnityTracker\Service\FetchFailedException;
use Ochorocho\UnityTracker\Service\OverviewService;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\UploadedFileInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TYPO3\CMS\Core\View\ViewFactoryData;
use TYPO3\CMS\Core\View\ViewFactoryInterface;

/**
 * The detail sheet of one issue, merge request or pull request (opened from the overview in a
 * modal iframe), its actions and the Markdown editor's preview and suggestions.
 *
 * Actions are sent in the background (`ajax=1`): the answer carries the re-rendered sheet body
 * (partial ItemDetail/Body). Without JavaScript they are plain form posts with a redirect.
 */
#[AsController]
final readonly class ItemController
{
    private const EDITOR_LABELS = [
        'editor.write', 'editor.preview', 'editor.bold', 'editor.italic', 'editor.strikethrough', 'editor.heading',
        'editor.quote', 'editor.code', 'editor.link', 'editor.bulletList', 'editor.numberedList', 'editor.taskList',
        'editor.table', 'editor.collapsible', 'editor.nothingToPreview', 'editor.previewFailed', 'editor.loading',
        'editor.toolbar', 'editor.hint', 'editor.requestFailed', 'editor.attach', 'editor.uploading', 'editor.uploadFailed',
    ];

    public function __construct(
        private ModuleTemplateFactory $moduleTemplateFactory,
        private ModuleContext $context,
        private ConnectionRepository $connectionRepository,
        private ItemDetailService $itemDetailService,
        private OverviewService $overviewService,
        private ProviderHtml $providerHtml,
        private ImageProxy $imageProxy,
        private ResponseFactoryInterface $responseFactory,
        private StreamFactoryInterface $streamFactory,
        private ViewFactoryInterface $viewFactory,
        private PageRenderer $pageRenderer,
    ) {}

    public function showAction(ServerRequestInterface $request): ResponseInterface
    {
        $query = $request->getQueryParams();
        $view = $this->moduleTemplateFactory->create($request);
        $view->getDocHeaderComponent()->disable();
        $this->pageRenderer->loadJavaScriptModule('@ochorocho/unity-tracker/item-detail.js');

        $detail = null;
        $connection = null;
        $error = '';
        try {
            $reference = ItemReference::fromArray($query);
            $connection = $this->connection($reference);
            $detail = $this->itemDetailService->load($reference, $connection);
        } catch (\InvalidArgumentException) {
            $error = $this->context->translate('item.error.reference');
        } catch (FetchFailedException $exception) {
            $error = $this->context->errorMessage($exception->error);
        }

        // The sheet's header shows the page title: the reference, the page itself shows the title.
        $view->setTitle($detail !== null ? $detail->referenceText() : $this->context->translate('item.title'));
        $view->assignMultiple($this->variables($detail, $connection) + [
            'error' => $error,
            'changed' => (bool)($query['changed'] ?? false),
            'editorConfig' => $detail !== null && $connection !== null ? $this->editorConfig($detail, $connection) : '',
        ]);
        return $view->renderResponse('ItemDetail');
    }

    public function performAction(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array)$request->getParsedBody();
        $background = ($body['ajax'] ?? '') === '1';
        $action = is_string($body['action'] ?? null) ? $body['action'] : '';
        try {
            $reference = ItemReference::fromArray($body);
            $connection = $this->connection($reference);
        } catch (\InvalidArgumentException|FetchFailedException) {
            return $this->answer($background, false, $this->context->translate('item.error.reference'));
        }

        try {
            if (!in_array($action, ItemAction::ALL, true)) {
                throw new InvalidActionException('notAllowed', 1764240501);
            }
            $this->itemDetailService->perform($reference, $connection, $action, $body);
        } catch (InvalidActionException $exception) {
            return $this->answer($background, false, $this->context->translate('item.error.' . $exception->getMessage()), $reference);
        } catch (FetchFailedException $exception) {
            return $this->answer($background, false, $this->context->errorMessage($exception->error), $reference);
        }

        $this->overviewService->invalidate($this->context->backendUserId());
        $reference = $action === ItemAction::MARK_DONE ? $reference->withoutTodo() : $reference;
        $message = $this->context->translate('item.done.' . $action);
        if (!$background) {
            return $this->answer(false, true, $message, $reference);
        }
        // The sheet shows the new state: load it again and send the re-rendered body.
        try {
            $html = $this->renderBody($request, $this->itemDetailService->load($reference, $connection), $connection);
        } catch (FetchFailedException) {
            $html = '';
        }
        return new JsonResponse(['ok' => true, 'message' => $message, 'html' => $html]);
    }

    /**
     * An older page of the timeline: rendered entries plus the cursor of the page before that.
     */
    public function activityAction(ServerRequestInterface $request): ResponseInterface
    {
        $query = $request->getQueryParams();
        $before = ItemDetailService::cursor($query['before'] ?? null);
        try {
            $reference = ItemReference::fromArray($query);
            $connection = $this->connection($reference);
            if ($before === '') {
                throw new \InvalidArgumentException('No cursor', 1764240503);
            }
            $detail = $this->itemDetailService->load($reference, $connection, $before);
        } catch (\InvalidArgumentException) {
            return new JsonResponse(['error' => $this->context->translate('item.error.reference')], 400);
        } catch (FetchFailedException $exception) {
            return new JsonResponse(['error' => $this->context->errorMessage($exception->error)], 502);
        }
        $view = $this->viewFactory->create(new ViewFactoryData(
            templateRootPaths: ['EXT:unity_tracker/Resources/Private/Templates'],
            partialRootPaths: ['EXT:unity_tracker/Resources/Private/Partials'],
            request: $request,
        ));
        $view->assignMultiple($this->variables($detail, $connection));
        return new JsonResponse(['html' => $view->render('ItemActivityFragment'), 'cursor' => $detail->olderCursor]);
    }

    /**
     * A file pasted, dropped or attached in the Markdown editor: uploaded to the provider, answers its Markdown.
     */
    public function uploadAction(ServerRequestInterface $request): ResponseInterface
    {
        $file = $request->getUploadedFiles()['file'] ?? null;
        try {
            $reference = ItemReference::fromArray((array)$request->getParsedBody());
            $connection = $this->connection($reference);
            if (!$file instanceof UploadedFileInterface) {
                throw new InvalidActionException('upload', 1764240504);
            }
            return new JsonResponse(['markdown' => $this->itemDetailService->upload($reference, $connection, $file)]);
        } catch (InvalidActionException $exception) {
            return new JsonResponse(['error' => $this->context->translate('item.error.' . $exception->getMessage())], 400);
        } catch (\InvalidArgumentException) {
            return new JsonResponse(['error' => $this->context->translate('item.error.reference')], 400);
        } catch (FetchFailedException $exception) {
            return new JsonResponse(['error' => $this->context->errorMessage($exception->error)], 502);
        }
    }

    public function previewAction(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array)$request->getParsedBody();
        try {
            $reference = ItemReference::fromArray($body);
            $connection = $this->connection($reference);
            $text = is_string($body['text'] ?? null) ? $body['text'] : '';
            $html = $this->itemDetailService->preview($reference, $connection, $text);
            return new JsonResponse(['html' => $this->providerHtml->sanitize($html, $connection->host, $this->imageUrl($connection))]);
        } catch (\InvalidArgumentException) {
            return new JsonResponse(['error' => $this->context->translate('item.error.reference')], 400);
        } catch (FetchFailedException $exception) {
            return new JsonResponse(['error' => $this->context->errorMessage($exception->error)], 502);
        }
    }

    public function suggestAction(ServerRequestInterface $request): ResponseInterface
    {
        $query = $request->getQueryParams();
        try {
            $reference = ItemReference::fromArray($query);
            $connection = $this->connection($reference);
            $kind = is_string($query['kind'] ?? null) ? $query['kind'] : '';
            $search = is_string($query['q'] ?? null) ? $query['q'] : '';
            return new JsonResponse(['items' => $this->itemDetailService->suggest($reference, $connection, $kind, $search)]);
        } catch (\InvalidArgumentException) {
            return new JsonResponse(['items' => []], 400);
        } catch (FetchFailedException) {
            // Suggestions are a convenience: no error message while typing.
            return new JsonResponse(['items' => []]);
        }
    }

    /**
     * Serves an image of a description or comment (see ImageProxy, ProviderHtml::prepare()).
     */
    public function imageAction(ServerRequestInterface $request): ResponseInterface
    {
        $query = $request->getQueryParams();
        $url = is_string($query['url'] ?? null) ? $query['url'] : '';
        $connection = $this->connectionRepository->findOne((int)($query['connection'] ?? 0), $this->context->backendUserId());
        try {
            if ($connection === null || !$connection->hasToken()) {
                throw new \RuntimeException('Unknown connection', 1764320101);
            }
            $image = $this->imageProxy->fetch($connection, $url);
        } catch (\RuntimeException) {
            return $this->responseFactory->createResponse(404);
        }
        return $this->responseFactory->createResponse()
            ->withHeader('Content-Type', $image['type'])
            ->withHeader('Cache-Control', 'private, max-age=3600')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            // SVG may contain scripts: never run them, also when the image is opened on its own.
            ->withHeader('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'; sandbox")
            ->withBody($this->streamFactory->createStream($image['body']));
    }

    /**
     * @return callable(string): string
     */
    private function imageUrl(Connection $connection): callable
    {
        return fn(string $url): string => $this->imageProxy->isAllowed($connection, $url)
            ? $this->context->uri('itemImage', ['connection' => $connection->uid, 'url' => $url])
            : '';
    }

    /**
     * JSON for background requests, otherwise a flash message and the sheet page again.
     */
    private function answer(bool $background, bool $ok, string $message, ?ItemReference $reference = null): ResponseInterface
    {
        if ($background) {
            return new JsonResponse(['ok' => $ok, 'message' => $message], $ok ? 200 : 422);
        }
        $this->context->flash($message, $ok ? ContextualFeedbackSeverity::OK : ContextualFeedbackSeverity::ERROR);
        return $this->context->redirect('item', ($reference?->toArray() ?? []) + ($ok ? ['changed' => 1] : []));
    }

    private function renderBody(ServerRequestInterface $request, ItemDetail $detail, Connection $connection): string
    {
        $view = $this->viewFactory->create(new ViewFactoryData(
            templateRootPaths: ['EXT:unity_tracker/Resources/Private/Templates'],
            partialRootPaths: ['EXT:unity_tracker/Resources/Private/Partials'],
            request: $request,
        ));
        $view->assignMultiple($this->variables($detail, $connection));
        return $view->render('ItemDetailFragment');
    }

    /**
     * Variables of the partial ItemDetail/Body, shared by the page and the background answers.
     *
     * @return array<string, mixed>
     */
    private function variables(?ItemDetail $detail, ?Connection $connection): array
    {
        if ($detail === null || $connection === null) {
            return ['detail' => null];
        }
        return [
            'detail' => $detail,
            'provider' => $connection->provider->label(),
            'actionUri' => $this->context->uri('itemAction'),
            'activityUri' => $this->context->uri('itemActivity', $detail->reference->toArray()),
            'description' => $this->providerHtml->prepare(
                $detail->descriptionHtml,
                $connection->host,
                $this->imageUrl($connection),
                $detail->allows(ItemAction::TOGGLE_TASK) && $detail->allows(ItemAction::EDIT)
            ),
            'timeline' => $this->timeline($detail, $connection),
            'can' => array_fill_keys($detail->actions, true),
            'reactionChoices' => Reactions::CHOICES,
            'linked' => $this->linked($detail),
        ];
    }

    /**
     * Linked items with the URL that opens them in the sheet (same connection); "" if it cannot.
     *
     * @return list<array<string, mixed>>
     */
    private function linked(ItemDetail $detail): array
    {
        return array_map(function (array $item) use ($detail): array {
            try {
                $reference = ItemReference::fromArray(['connection' => $detail->reference->connection] + $item);
                $sheetUrl = $this->context->uri('item', $reference->toArray());
            } catch (\InvalidArgumentException) {
                $sheetUrl = '';
            }
            return $item + ['sheetUrl' => $sheetUrl, 'reference' => $item['project'] . ($item['type'] === 'merge_request' ? '!' : '#') . $item['number']];
        }, $detail->linked);
    }

    /**
     * The timeline with provider HTML prepared for rendering (images through the proxy, see ProviderHtml).
     *
     * @return list<array<string, mixed>>
     */
    private function timeline(ItemDetail $detail, Connection $connection): array
    {
        $prepare = fn(string $html, bool $editable = false): string => $this->providerHtml->prepare($html, $connection->host, $this->imageUrl($connection), $editable);
        return array_map(static fn(array $entry): array => array_merge($entry, [
            'html' => $prepare($entry['html']),
            'comments' => array_map(
                static fn(array $comment): array => array_merge($comment, ['html' => $prepare($comment['html'], $comment['canEdit'])]),
                $entry['comments']
            ),
        ]), $detail->timeline);
    }

    /**
     * Shared settings of the Markdown editors of one sheet (JSON on the sheet's root element).
     */
    private function editorConfig(ItemDetail $detail, Connection $connection): string
    {
        $labels = [];
        foreach (self::EDITOR_LABELS as $key) {
            $labels[$key] = $this->context->translate($key);
        }
        return json_encode([
            'provider' => $connection->provider->value,
            'reference' => $detail->reference->toArray(),
            'previewUrl' => $this->context->uri('itemPreview'),
            'suggestUrl' => $this->context->uri('itemSuggest'),
            // GitLab only: GitHub has no upload API.
            'uploadUrl' => $connection->provider === Provider::GitLab && ($detail->allows(ItemAction::COMMENT) || $detail->allows(ItemAction::EDIT))
                ? $this->context->uri('itemUpload')
                : '',
            'labels' => array_column($detail->availableLabels, 'name'),
            'l10n' => $labels,
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * @throws \InvalidArgumentException if the connection is not the user's
     * @throws FetchFailedException if its token cannot be decrypted
     */
    private function connection(ItemReference $reference): Connection
    {
        $connection = $this->connectionRepository->findOne($reference->connection, $this->context->backendUserId());
        if ($connection === null) {
            throw new \InvalidArgumentException('Unknown connection', 1764240502);
        }
        if (!$connection->hasToken()) {
            throw new FetchFailedException(new FetchError($connection->name(), FetchError::MISSING_TOKEN));
        }
        return $connection;
    }
}
