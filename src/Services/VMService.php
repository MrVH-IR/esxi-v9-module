<?php

declare(strict_types=1);

namespace EsxiV9\Services;

use EsxiV9\DTO\MOR;
use EsxiV9\DTO\VMConfig;
use EsxiV9\Exceptions\VMCreationException;
use EsxiV9\SOAP\PropertyCollectorHelper;
use EsxiV9\SOAP\SoapConnector;
use EsxiV9\Utils\VMwareTypes;
use EsxiV9\VM\VMSpecBuilder;
use RuntimeException;
use SoapFault;

class VMService
{
    /**
     * Well-known, fixed ManagedObjectReference id for the root/default
     * resource pool on a STANDALONE ESXi host (no vCenter). This is not a
     * guess: ESXi always exposes it under this exact id (see VMware KB /
     * `vim-cmd hostsvc/rsrc/pool_config_get ha-root-pool`). Once this module
     * supports vCenter-managed hosts, this needs to be resolved dynamically
     * instead (via PropertyCollectorHelper) since vCenter assigns real ids.
     */
    private const DEFAULT_RESOURCE_POOL_ID = 'ha-root-pool';

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
            $content = $this->connector->serviceInstance()->retrieveServiceContent();

            $rootFolder = (array) $content['rootFolder'];

            $spec = (new VMSpecBuilder())
                ->fromConfig($config)
                ->withDisk($config->getStorage(), $config->getDatastore())
                ->withNetwork($config->getNetwork())
                ->withISO($config->getIso())
                ->build();

            $folder = new MOR($rootFolder['type'], $rootFolder['_']);
            $pool = new MOR(VMwareTypes::RESOURCE_POOL, self::DEFAULT_RESOURCE_POOL_ID);

            $response = $client->CreateVM_Task([
                '_this' => $folder->toArray(),
                'pool' => $pool->toArray(),
                'config' => $spec,
            ]);

            $taskId = $response->returnval->_ ?? null;

            if ($taskId === null) {
                throw new VMCreationException('CreateVM_Task did not return a task reference.');
            }

            return $taskId;
        } catch (SoapFault $e) {
            throw new VMCreationException(
                "VM Creation Failed: " . $e->getMessage(),
                (int) $e->getCode()
            );
        }
    }

    /**
     * Delete a VM permanently. The VM must be powered off first — ESXi
     * rejects Destroy_Task on a running VM with a SoapFault (InvalidState).
     */
    public function remove(string $vmId): string
    {
        $client = $this->connector->getClient();

        try {
            $response = $client->Destroy_Task([
                '_this' => [
                    'type' => VMwareTypes::VIRTUAL_MACHINE,
                    '_' => $vmId,
                ],
            ]);

            return $response->returnval->_ ?? '';
        } catch (SoapFault $e) {
            throw new RuntimeException(
                "VM Delete Failed: " . $e->getMessage(),
                (int) $e->getCode()
            );
        }
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

    /**
     * Fetch a single VM's properties directly by id (no tree traversal
     * needed since we already know exactly which object we want).
     */
    public function get(string $vmId): array
    {
        $client = $this->connector->getClient();

        try {
            $content = $this->connector->serviceInstance()->retrieveServiceContent();
            $propertyCollector = (array) $content['propertyCollector'];

            $objects = PropertyCollectorHelper::retrieveProperties(
                $client,
                $propertyCollector,
                ['type' => VMwareTypes::VIRTUAL_MACHINE, '_' => $vmId],
                [$this->vmPropSpec()],
                [] // no traversal: obj IS the VM we want
            );

            $normalized = $this->normalizeVMList($objects);

            if (empty($normalized)) {
                throw new RuntimeException("VM not found: {$vmId}");
            }

            return $normalized[0];
        } catch (SoapFault $e) {
            throw new RuntimeException("Get VM Failed: " . $e->getMessage(), (int) $e->getCode());
        }
    }

    /**
     * List every VM visible from the root inventory folder.
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
                [$this->vmPropSpec()]
            // selectSet omitted -> PropertyCollectorHelper uses the full
            // traversal spec, so VMs are found regardless of nesting.
            );

            return $this->normalizeVMList($objects);
        } catch (SoapFault $e) {
            throw new RuntimeException("VM List Failed: " . $e->getMessage(), (int) $e->getCode());
        }
    }

    private function vmPropSpec(): array
    {
        return [
            'type' => VMwareTypes::VIRTUAL_MACHINE,
            'pathSet' => [
                'name',
                'runtime.powerState',
                'config.hardware.numCPU',
                'config.hardware.memoryMB',
                'guest.ipAddress',
                'guest.hostName',
                'guest.toolsRunningStatus',
            ],
        ];
    }

    private function normalizeVMList(array $objects): array
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
                'powerState' => $props['runtime.powerState'] ?? null,
                'cpu' => $props['config.hardware.numCPU'] ?? null,
                'memoryMB' => $props['config.hardware.memoryMB'] ?? null,
                'ip' => $props['guest.ipAddress'] ?? null,
                'hostname' => $props['guest.hostName'] ?? null,
                'toolsStatus' => $props['guest.toolsRunningStatus'] ?? null,
            ];
        }

        return $result;
    }
}