<?php

declare(strict_types=1);

namespace EsxiV9\Services;

use EsxiV9\SOAP\PropertyCollectorHelper;
use EsxiV9\SOAP\SoapConnector;
use EsxiV9\Utils\VMwareTypes;
use RuntimeException;
use SoapFault;

class NetworkService
{
    public function __construct(
        private readonly SoapConnector $connector,
    ) {}

    /**
     * List all port groups / networks visible on this host.
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
                    'type' => VMwareTypes::NETWORK,
                    'pathSet' => ['name', 'summary.accessible'],
                ]]
            );

            return $this->normalize($objects);
        } catch (SoapFault $e) {
            throw new RuntimeException("Network List Failed: " . $e->getMessage(), (int) $e->getCode());
        }
    }

    /**
     * Convenience: the name of the first accessible network. Useful as a
     * fallback default when creating a VM without an explicit network.
     */
    public function findDefaultName(): ?string
    {
        foreach ($this->list() as $net) {
            if ($net['accessible'] === true) {
                return $net['name'];
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

            $result[] = [
                'id' => $object->obj->_ ?? null,
                'name' => $props['name'] ?? null,
                'accessible' => $props['summary.accessible'] ?? null,
            ];
        }

        return $result;
    }
}