<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "unity_tracker" by ochorocho.
 */

namespace Ochorocho\UnityTracker\Detail;

/**
 * Which issue, merge request or pull request to show, as passed in the module URL.
 */
final readonly class ItemReference
{
    public const TYPES = ['issue', 'merge_request', 'pull_request'];

    private function __construct(
        public int $connection,
        public string $type,
        public string $project,
        public int $number,
        public string $todoId,
    ) {}

    /**
     * @param array<mixed> $parameters
     * @throws \InvalidArgumentException
     */
    public static function fromArray(array $parameters): self
    {
        $connection = (int)($parameters['connection'] ?? 0);
        $type = (string)($parameters['type'] ?? '');
        $project = (string)($parameters['project'] ?? '');
        $number = (int)($parameters['number'] ?? 0);
        $todoId = (string)($parameters['todo'] ?? '');

        if ($connection <= 0
            || !in_array($type, self::TYPES, true)
            || $number <= 0
            // GitLab: group/subgroup/project, GitHub: owner/repository
            || preg_match('#^[\w.-]+(/[\w.-]+)+$#', $project) !== 1
            || array_intersect(['.', '..'], explode('/', $project)) !== []
            || strlen($project) > 255
            || preg_match('/^\d{0,20}$/', $todoId) !== 1
        ) {
            throw new \InvalidArgumentException('Invalid item reference', 1764240001);
        }
        return new self($connection, $type, $project, $number, $todoId);
    }

    /**
     * @return array{connection: int, type: string, project: string, number: int, todo: string}
     */
    public function toArray(): array
    {
        return ['connection' => $this->connection, 'type' => $this->type, 'project' => $this->project, 'number' => $this->number, 'todo' => $this->todoId];
    }

    public function withoutTodo(): self
    {
        return new self($this->connection, $this->type, $this->project, $this->number, '');
    }

    public function isChange(): bool
    {
        return $this->type !== 'issue';
    }
}
