<?php

declare(strict_types=1);

namespace EsxiV9\DTO;

class TaskResult
{

    public function __construct(
        private readonly string $taskId,
        private readonly string $state,
        private readonly ?string $error = null,
        private readonly mixed $result = null
    ) {}

    public function getTaskId(): string
    {
        return $this->taskId;
    }

    public function getState(): string
    {
        return $this->state;
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    public function isSuccess(): bool
    {
        return $this->state === 'success';
    }

    /**
     * Raw task result value (e.g. the new VM's ManagedObjectReference after
     * CreateVM_Task succeeds). Shape depends on which task this came from.
     */
    public function getResult(): mixed
    {
        return $this->result;
    }
}