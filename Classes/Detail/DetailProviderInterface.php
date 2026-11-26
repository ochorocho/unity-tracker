<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "unity_tracker" by ochorocho.
 */

namespace Ochorocho\UnityTracker\Detail;

use Ochorocho\UnityTracker\Connection\Connection;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

/**
 * Builds the detail and action requests of one provider and parses the answers. No I/O happens here.
 */
interface DetailProviderInterface
{
    /**
     * @param string $before cursor of an older page of the timeline, "" for the newest
     * @return array<string, RequestInterface> sent concurrently; "detail" is required, the others may fail
     */
    public function detailRequests(ItemReference $reference, Connection $connection, string $before = ''): array;

    /**
     * @param array<string, ResponseInterface|null> $responses keyed like detailRequests(), null if that request failed
     * @throws \UnexpectedValueException if the detail cannot be read
     */
    public function parseDetail(ItemReference $reference, array $responses, string $before = ''): ItemDetail;

    /**
     * @param array<string, string> $parameters validated by ItemDetailService
     */
    public function actionRequest(ItemDetail $detail, string $action, array $parameters, Connection $connection): RequestInterface;

    /**
     * Renders Markdown the way the provider does (GitLab/GitHub flavoured, references resolved in the project).
     */
    public function previewRequest(ItemReference $reference, Connection $connection, string $markdown): RequestInterface;

    /**
     * @return string HTML, not sanitised yet
     */
    public function parsePreview(ResponseInterface $response): string;

    /**
     * Uploads a file for a description or comment, null if the provider has no upload API (GitHub).
     * The answer carries the Markdown to insert (see ItemDetailService::upload()).
     */
    public function uploadRequest(ItemReference $reference, Connection $connection, string $filename, StreamInterface $file): ?RequestInterface;

    /**
     * Autocompletion in the Markdown editor, null if the provider has no such references.
     *
     * @param string $kind one of ItemDetailService::SUGGESTION_KINDS
     */
    public function suggestionRequest(ItemReference $reference, Connection $connection, string $kind, string $query): ?RequestInterface;

    /**
     * @return list<array{value: string, label: string}> value is inserted after the trigger character
     */
    public function parseSuggestions(string $kind, ResponseInterface $response): array;
}
