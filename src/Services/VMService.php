<?php

declare(strict_types=1);

namespace EsxiV9\Services;

use EsxiV9\Config\Config;
use EsxiV9\DTO\MOR;
use EsxiV9\DTO\VMConfig;
use EsxiV9\Exceptions\VMCreationException;
use EsxiV9\Soap\SoapConnector;
use EsxiV9\Utils\VMwareTypes;
use EsxiV9\VM\VMSpecBuilder;
use RuntimeException;
use SoapFault;

class VMService
{
    public function __construct(
        private readonly SoapConnector $connector,
    ) {}


    /**
     * @throws VMCreationException
     */
    public function create(VMConfig $config): string
    {
        $client = $this->connector->getClient();

        try {
            $spec = (new VMSpecBuilder())
                ->fromConfig($config)
                ->withDisk($config->getStorage())
                ->withNetwork()
                ->build();

            $folder = new MOR(VMwareTypes::FOLDER, 'ha-folder-root');
            $pool   = new MOR(VMwareTypes::RESOURCE_POOL, 'ha-root-pool');

            $response = $client->CreateVM_Task([
                '_this'  => $folder->toArray(),
                'pool'   => $pool->toArray(),
                'config' => $spec,
            ]);

            return $response->returnval->_ ?? 'task-created';
        } catch (SoapFault $e) {
            throw new VMCreationException(
                "VM Creation Failed: " . $e->getMessage(), (int) $e->getCode()
            );
        }
    }

    public function remove(string $vmId): void
    {
    }

    /**
     * Power On VM
     */
    public function powerOn(string $vmId): void
    {
        $client = $this->connector->getClient();

        try {
            $client->PowerOnVM_Task([
                '_this' => [
                    'type' => VMwareTypes::VIRTUAL_MACHINE,
                    '_' => $vmId
                ]
            ]);

        } catch (SoapFault $e) {
            throw new RuntimeException(
                "Power On Failed: " . $e->getMessage(),
                (int) $e->getCode()
            );
        }
    }

    /**
     * Power Off VM
     */
    public function powerOff(string $vmId): void
    {
        $client = $this->connector->getClient();

        try {
            $client->PowerOffVM_Task([
                '_this' => [
                    'type' => VMwareTypes::VIRTUAL_MACHINE,
                    '_' => $vmId
                ]
            ]);

        } catch (SoapFault $e) {
            throw new RuntimeException(
                "Power Off Failed: " . $e->getMessage(),
                (int) $e->getCode()
            );
        }
    }

    /**
     * Reset VM
     */
    public function reboot(string $vmId): void
    {
        $client = $this->connector->getClient();

        try {
            $client->ResetVM_Task([
                '_this' => [
                    'type' => VMwareTypes::VIRTUAL_MACHINE,
                    '_' => $vmId
                ]
            ]);

        } catch (SoapFault $e) {
            throw new RuntimeException(
                "Reset Failed: " . $e->getMessage(),
                (int) $e->getCode()
            );
        }
    }

    public function get(string $vmId): void
    {
    }

    public function list(): array
    {
        $client = $this->connector->getClient();

        try {
            $response = $client->RetrieveServiceContent([
                '_this'  => [
                    'type' => VMwareTypes::SERVICE_INSTANCE,
                    '_' => VMwareTypes::SERVICE_INSTANCE,
                ]
            ]);

            $content = $response->returnval ?? null;

            if (!$content) {
                return [];
            }

            $rootFolder = $content->rootFolder;
            $vmList = $client->RetrieveProperties([
                '_this' => [
                    'type' => VMwareTypes::PROPERTY_COLLECTOR,
                    '_' => 'propertyCollector',
                ],
                'specSet' => [
                    [
                        'propSet' => [
                            [
                                'type' => VMwareTypes::VIRTUAL_MACHINE,
                                'pathSet' => ['name' , 'runtime.powerState' , 'config.hardware.numCPU' , 'config.hardware.memoryMB']
                            ]
                        ],
                        'objectSet' => [
                            'obj' => $rootFolder,
                            'skip' => false
                        ]
                    ]
                ]
            ]);

            return $this->normalizeVMList($vmList);

        } catch (SoapFault $e) {
            throw new RuntimeException("VM List Failed: " . $e->getMessage(), (int) $e->getCode());
        }
    }

    private function normalizeVMList($response): array
    {
        $result = [];

        $objects = $response->returnval->objects ?? [];

        foreach ($objects as $object) {

            $props = [];

            foreach ($object->propSet as $prop) {
                $props[$prop->name] = $prop->val;
            }

            $result[] = [
                'id' => $object->obj->_ ?? null,
                'name' => $props['name'] ?? null,
                'powerState' => $props['runtime.powerState'] ?? null,
                'cpu' => $props['config.hardware.numCPU'] ?? null,
                'memory' => $props['config.hardware.memoryMB'] ?? null,
            ];
        }

        return $result;
    }
}