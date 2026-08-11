<?php

declare(strict_types=1);

namespace EsxiV9\Services;

use EsxiV9\SOAP\PropertyCollectorHelper;
use EsxiV9\SOAP\SoapConnector;
use EsxiV9\Utils\VMwareTypes;
use RuntimeException;
use SoapFault;

class DatastoreService
{
    public function __construct(
        private readonly SoapConnector $connector,
    ) {}

    /**
     * List all datastores visible on this host.
     */
    public function list(): array
    {
        $client = $this->connector->getClient();

        try {
            $content = $this->connector->serviceInstance()->retrieveServiceContent();

            $rootFolder = (array) $content['rootFolder'];
            $propertyCollector = (array) $content['propertyCollector'];

            $objects = PropertyCollectorHelper::retrieveProperties(
                $client,
                $propertyCollector,
                $rootFolder,
                [[
                    'type' => VMwareTypes::DATASTORE,
                    'pathSet' => [
                        'name',
                        'summary.capacity',
                        'summary.freeSpace',
                        'summary.accessible',
                        'summary.type',
                    ],
                ]]
            );

            return $this->normalize($objects);
        } catch (SoapFault $e) {
            throw new RuntimeException("Datastore List Failed: " . $e->getMessage(), (int) $e->getCode());
        }
    }

    /**
     * Convenience: the name of the first accessible datastore. Useful as a
     * fallback default when creating a VM without an explicit datastore.
     */
    public function findDefaultName(): ?string
    {
        foreach ($this->list() as $ds) {
            if ($ds['accessible'] === true) {
                return $ds['name'];
            }
        }

        return null;
    }

    private function normalize(array $objects): array
    {
        $result = [];

        foreach ($objects as $object) {
            $props = [];

            foreach ((array) ($object->propSet ?? []) as $prop) {
                $props[$prop->name] = $prop->val;
            }

            $capacity = (int) ($props['summary.capacity'] ?? 0);
            $free = (int) ($props['summary.freeSpace'] ?? 0);

            $result[] = [
                'id' => $object->obj->_ ?? null,
                'name' => $props['name'] ?? null,
                'type' => $props['summary.type'] ?? null,
                'accessible' => $props['summary.accessible'] ?? null,
                'capacityGB' => $capacity > 0 ? round($capacity / 1024 / 1024 / 1024, 2) : 0,
                'freeSpaceGB' => $free > 0 ? round($free / 1024 / 1024 / 1024, 2) : 0,
            ];
        }

        return $result;
    }
}