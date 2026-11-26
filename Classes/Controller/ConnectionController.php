<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "unity_tracker" by ochorocho.
 */

namespace Ochorocho\UnityTracker\Controller;

use Ochorocho\UnityTracker\Connection\Connection;
use Ochorocho\UnityTracker\Connection\ConnectionRepository;
use Ochorocho\UnityTracker\Connection\HostPolicy;
use Ochorocho\UnityTracker\Connection\InvalidHostException;
use Ochorocho\UnityTracker\Connection\Provider;
use Ochorocho\UnityTracker\Service\FetchFailedException;
use Ochorocho\UnityTracker\Service\ItemFetcher;
use Ochorocho\UnityTracker\Service\OverviewService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;

#[AsController]
final readonly class ConnectionController
{
    public function __construct(
        private ModuleTemplateFactory $moduleTemplateFactory,
        private ModuleContext $context,
        private ConnectionRepository $connectionRepository,
        private HostPolicy $hostPolicy,
        private ItemFetcher $itemFetcher,
        private OverviewService $overviewService,
    ) {}

    public function listAction(ServerRequestInterface $request): ResponseInterface
    {
        $view = $this->moduleTemplateFactory->create($request);
        $view->setTitle($this->context->translate('connections.title'));
        $this->context->addButton($view, $this->context->uri('newConnection'), 'connections.add', 'actions-plus', 1);

        $view->assignMultiple([
            'connections' => $this->connectionRepository->findByBackendUser($this->context->backendUserId()),
            'deleteUri' => $this->context->uri('deleteConnection'),
        ]);
        return $view->renderResponse('Connections');
    }

    public function newAction(ServerRequestInterface $request): ResponseInterface
    {
        $query = $request->getQueryParams();
        $view = $this->moduleTemplateFactory->create($request);
        $view->setTitle($this->context->translate('connections.add'));
        $this->context->addButton($view, $this->context->uri('connections'), 'button.close', 'actions-close', 1);

        $view->assignMultiple([
            'providers' => array_map(static fn(Provider $provider): array => [
                'value' => $provider->value,
                'label' => $provider->label(),
            ], Provider::cases()),
            'allowedHosts' => implode(', ', $this->hostPolicy->allowedHosts()),
            // Prefill after a failed attempt; the token is never sent back.
            'form' => [
                'provider' => is_string($query['provider'] ?? null) ? $query['provider'] : Provider::GitLab->value,
                'host' => is_string($query['host'] ?? null) ? $query['host'] : '',
                'label' => is_string($query['label'] ?? null) ? $query['label'] : '',
            ],
            'addUri' => $this->context->uri('addConnection'),
        ]);
        return $view->renderResponse('ConnectionForm');
    }

    public function addAction(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array)$request->getParsedBody();
        $providerValue = is_string($body['provider'] ?? null) ? $body['provider'] : '';
        $host = is_string($body['host'] ?? null) ? trim($body['host']) : '';
        $label = is_string($body['label'] ?? null) ? mb_substr(trim($body['label']), 0, 255) : '';
        // Not "token": that name carries TYPO3's CSRF token for the route.
        $token = is_string($body['accessToken'] ?? null) ? trim($body['accessToken']) : '';
        $retry = fn(string $message): ResponseInterface => $this->failed($message, ['provider' => $providerValue, 'host' => $host, 'label' => $label]);

        $provider = Provider::tryFrom($providerValue);
        if ($provider === null) {
            return $retry($this->context->translate('error.provider'));
        }
        if ($token === '') {
            return $retry($this->context->translate('error.token'));
        }
        try {
            $host = $this->hostPolicy->resolve($host, $provider);
        } catch (InvalidHostException $exception) {
            return $retry($this->context->translate('error.host.' . $exception->reason, $exception->host));
        }

        $backendUser = $this->context->backendUserId();
        $connection = new Connection(0, $backendUser, $provider, $host, $label, $token);
        try {
            $account = $this->itemFetcher->identify($connection);
        } catch (FetchFailedException $exception) {
            return $retry($this->context->errorMessage($exception->error));
        }

        if ($label === '') {
            $connection = new Connection(0, $backendUser, $provider, $host, $account . '@' . $host, $token);
        }
        $this->connectionRepository->add($connection->withAccount($account));
        $this->overviewService->invalidate($backendUser);
        $this->context->flash($this->context->translate('connections.added', $account));
        return $this->context->redirect('connections');
    }

    public function deleteAction(ServerRequestInterface $request): ResponseInterface
    {
        $backendUser = $this->context->backendUserId();
        $uid = (int)(((array)$request->getParsedBody())['uid'] ?? 0);
        if ($this->connectionRepository->remove($uid, $backendUser)) {
            $this->overviewService->invalidate($backendUser);
            $this->context->flash($this->context->translate('connections.deleted'));
        }
        return $this->context->redirect('connections');
    }

    /**
     * @param array<string, string> $form
     */
    private function failed(string $message, array $form): ResponseInterface
    {
        $this->context->flash($message, ContextualFeedbackSeverity::ERROR);
        return $this->context->redirect('newConnection', $form);
    }
}
