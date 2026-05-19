<?php

declare(strict_types=1);

namespace EsxiV9\DTO;


class VMInfo
{

    public function __construct(
        private readonly string $id,
        private readonly string $name,
        private readonly string $powerState,
        private readonly int $memory,
        private readonly int $cpu
    ) {}

    public function getId(): string
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getPowerState(): string
    {
        return $this->powerState;
    }

    public function getMemory(): int
    {
        return $this->memory;
    }

    public function getCpu(): int
    {
        return $this->cpu;
    }
}