<?php
declare(strict_types=1);

namespace EsxiV9\DTO;

use EsxiV9\Utils\VMwareTypes;

class ServiceInstance
{
    public function __construct(
        private readonly string $type = VMwareTypes::SERVICE_INSTANCE,
        private readonly string $value = VMwareTypes::SERVICE_INSTANCE,
    ) {}

    public function toArray(): array
    {
        return [
            'type' => $this->type,
            '_' => $this->value,
        ];
    }
}