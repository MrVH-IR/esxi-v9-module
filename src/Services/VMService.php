<?php

declare(strict_types=1);

namespace EsxiV9\Services;

use EsxiV9\DTO\TaskResult;
use EsxiV9\DTO\VMConfig;
use EsxiV9\Exceptions\VMCreationException;
use EsxiV9\SOAP\PropertyCollectorHelper;
use EsxiV9\SOAP\SoapConnector;
use EsxiV9\Utils\VMwareTypes;
use EsxiV9\VM\VMSpecBuilder;
use InvalidArgumentException;
use RuntimeException;
use SoapFault;

class VMService
{
    public function __construct(
        private readonly SoapConnector $connector,
    ) {}

    private function tasks(): TaskService
    {
        return new TaskService($this->connector);
    }

    /**
     * Resolve the real Datacenter.vmFolder and a ResourcePool to create a VM
     * in. CreateVM_Task must be called on a VmFolder specifically — calling
     * it on the raw rootFolder (a more general container that also holds
     * the hostFolder) fails with "The operation is not supported on the
     * object." Hardcoding well-known-looking ids like "ha-folder-root" /
     * "ha-root-pool" is NOT reliable enough here, so this reads the real
     * ManagedObjectReferences from the host instead.
     *
     * @return array{folder: array, pool: array}
     */
    private function resolveCreateVmTarget(\SoapClient $client, array $propertyCollector, array $rootFolder): array
    {
        $dcObjects = PropertyCollectorHelper::retrieveProperties(
            $client,
            $propertyCollector,
            $rootFolder,
            [[
                'type' => VMwareTypes::DATACENTER,
                'pathSet' => ['vmFolder'],
            ]]
        );

        if (empty($dcObjects)) {
            throw new VMCreationException('Could not find a Datacenter on this host to create the VM in.');
        }

        $vmFolderRef = null;
        foreach ((array) ($dcObjects[0]->propSet ?? []) as $prop) {
            if ($prop->name === 'vmFolder') {
                $vmFolderRef = $prop->val;
            }
        }

        if ($vmFolderRef === null) {
            throw new VMCreationException('Datacenter has no vmFolder property.');
        }

        $rpObjects = PropertyCollectorHelper::retrieveProperties(
            $client,
            $propertyCollector,
            $rootFolder,
            [[
                'type' => VMwareTypes::RESOURCE_POOL,
                'pathSet' => ['name'],
            ]]
        );

        if (empty($rpObjects)) {
            throw new VMCreationException('Could not find a ResourcePool on this host to create the VM in.');
        }

        return [
            'folder' => ['type' => $vmFolderRef->type, '_' => $vmFolderRef->_],
            'pool' => ['type' => $rpObjects[0]->obj->type, '_' => $rpObjects[0]->obj->_],
        ];
    }

    /**
     * Kick off VM creation. Returns the Task id immediately (does not wait
     * for it to finish) — pass it to TaskService::wait() yourself, or use
     * createAndWait() below.
     *
     * @throws VMCreationException
     */
    public function create(VMConfig $config): string
    {
        $client = $this->connector->getClient();

        try {
            $content = $this->connector->serviceInstance()->retrieveServiceContent();

            $rootFolder = (array) $content['rootFolder'];
            $propertyCollector = (array) $content['propertyCollector'];

            $spec = (new VMSpecBuilder())
                ->fromConfig($config)
                ->withDisk($config->getStorage(), $config->getDatastore())
                ->withNetwork($config->getNetwork())
                ->withISO($config->getIso())
                ->build();

            $target = $this->resolveCreateVmTarget($client, $propertyCollector, $rootFolder);

            $response = $client->CreateVM_Task([
                '_this' => $target['folder'],
                'pool' => $target['pool'],
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
     * Create the VM and block until ESXi finishes, returning the NEW VM's id
     * (taken straight from the task's result, no extra list()/find needed).
     *
     * @throws VMCreationException
     */
    public function createAndWait(VMConfig $config, int $timeoutSeconds = 120): string
    {
        $taskId = $this->create($config);
        $result = $this->tasks()->wait($taskId, $timeoutSeconds);

        if (!$result->isSuccess()) {
            throw new VMCreationException('VM Creation Failed: ' . ($result->getError() ?? 'unknown error'));
        }

        $vmRef = $result->getResult();
        $vmId = is_object($vmRef) ? ($vmRef->_ ?? null) : null;

        if ($vmId === null) {
            throw new VMCreationException('VM was created but no VM reference was returned by the task.');
        }

        return $vmId;
    }

    /**
     * Delete a VM permanently. The VM must be powered off first — ESXi
     * rejects Destroy_Task on a running VM with a SoapFault (InvalidState).
     * Returns the Task id.
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

    public function removeAndWait(string $vmId, int $timeoutSeconds = 60): TaskResult
    {
        return $this->tasks()->wait($this->remove($vmId), $timeoutSeconds);
    }

    /**
     * Power On VM. Returns the Task id.
     */
    public function powerOn(string $vmId): string
    {
        return $this->runTaskOp('PowerOnVM_Task', $vmId, 'Power On Failed');
    }

    public function powerOnAndWait(string $vmId, int $timeoutSeconds = 60): TaskResult
    {
        return $this->tasks()->wait($this->powerOn($vmId), $timeoutSeconds);
    }

    /**
     * Power Off VM. Returns the Task id.
     */
    public function powerOff(string $vmId): string
    {
        return $this->runTaskOp('PowerOffVM_Task', $vmId, 'Power Off Failed');
    }

    public function powerOffAndWait(string $vmId, int $timeoutSeconds = 60): TaskResult
    {
        return $this->tasks()->wait($this->powerOff($vmId), $timeoutSeconds);
    }

    /**
     * Reset (hard reboot) VM. Returns the Task id.
     */
    public function reboot(string $vmId): string
    {
        return $this->runTaskOp('ResetVM_Task', $vmId, 'Reset Failed');
    }

    public function rebootAndWait(string $vmId, int $timeoutSeconds = 60): TaskResult
    {
        return $this->tasks()->wait($this->reboot($vmId), $timeoutSeconds);
    }

    /**
     * Change CPU and/or memory on a VM. ESXi will reject this with a
     * SoapFault if the VM is powered on and the resource in question does
     * not support hot-add (hot-add is off by default), so we proactively
     * check power state first for a clear, actionable error message.
     * Returns the Task id.
     */
    public function resize(string $vmId, ?int $cpu = null, ?int $memoryMB = null): string
    {
        if ($cpu === null && $memoryMB === null) {
            throw new InvalidArgumentException('resize() needs at least $cpu or $memoryMB.');
        }

        $current = $this->get($vmId);

        if ($current['powerState'] === 'poweredOn') {
            throw new RuntimeException(
                "Cannot resize VM {$vmId}: it is powered on. Power it off first (hot-add is not enabled)."
            );
        }

        $client = $this->connector->getClient();

        $spec = [];
        if ($cpu !== null) {
            $spec['numCPUs'] = $cpu;
        }
        if ($memoryMB !== null) {
            $spec['memoryMB'] = $memoryMB;
        }

        try {
            $response = $client->ReconfigVM_Task([
                '_this' => [
                    'type' => VMwareTypes::VIRTUAL_MACHINE,
                    '_' => $vmId,
                ],
                'spec' => $spec,
            ]);

            return $response->returnval->_ ?? '';
        } catch (SoapFault $e) {
            throw new RuntimeException(
                "VM Resize Failed: " . $e->getMessage(),
                (int) $e->getCode()
            );
        }
    }

    public function resizeAndWait(string $vmId, ?int $cpu = null, ?int $memoryMB = null, int $timeoutSeconds = 60): TaskResult
    {
        return $this->tasks()->wait($this->resize($vmId, $cpu, $memoryMB), $timeoutSeconds);
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

    private function runTaskOp(string $operation, string $vmId, string $errorPrefix): string
    {
        $client = $this->connector->getClient();

        try {
            $response = $client->{$operation}([
                '_this' => [
                    'type' => VMwareTypes::VIRTUAL_MACHINE,
                    '_' => $vmId,
                ],
            ]);

            return $response->returnval->_ ?? '';
        } catch (SoapFault $e) {
            throw new RuntimeException("{$errorPrefix}: " . $e->getMessage(), (int) $e->getCode());
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