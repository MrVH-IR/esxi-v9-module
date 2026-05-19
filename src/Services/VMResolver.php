<?php

declare(strict_types=1);

namespace EsxiV9\Services;

use EsxiV9\SOAP\SoapConnector;

class VMResolver
{
    public function __construct(
        private readonly VMService $vmService
    ) {}

    public function findByName(string $name): ?array
    {
        $vms = $this->vmService->list();

        foreach ($vms as $vm) {
            if ($vm['name'] === $name) {
                return $vm;
            }
        }

        return null;
    }

    public function getIdByName(string $name): ?string
    {
        $vm = $this->findByName($name);
        return $vm['id'] ?? null;
    }
}