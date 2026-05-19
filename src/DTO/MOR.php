<?php
declare(strict_types=1);

namespace EsxiV9\DTO;

class MOR
{
    public function __construct(
        private readonly string $type,
        private readonly string $value
    ) {}

    public function toArray(): array
    {
        return [
            'type' => $this->type,
            '_' => $this->value,
        ];
    }
}