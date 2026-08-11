<?php

declare(strict_types=1);

namespace EsxiV9\VM;

use EsxiV9\DTO\VMConfig;
use SoapVar;

/**
 * Builds a real VMware VirtualMachineConfigSpec array suitable for
 * CreateVM_Task / ReconfigVM_Task.
 *
 * IMPORTANT: unlike simple scalar fields (name, numCPUs, memoryMB, guestId,
 * files), virtual hardware (disks, NICs, controllers, CD-ROMs) is NOT set
 * as plain top-level keys. It must go into `deviceChange`, an array of
 * VirtualDeviceConfigSpec entries, each wrapping a polymorphic VirtualDevice
 * subtype (VirtualDisk, VirtualVmxnet3, VirtualLsiLogicController, ...).
 * Because PHP's SoapClient can't infer that polymorphism from a plain array,
 * each device is wrapped in a SoapVar with an explicit xsi:type so the SOAP
 * request encodes correctly against the vim25 schema.
 */
class VMSpecBuilder
{
    private const NS = 'urn:vim25';

    // Device keys just need to be unique negative-or-positive ints within
    // the spec; ESXi assigns real keys once the devices are created.
    private const SCSI_CONTROLLER_KEY = 1000;
    private const DISK_KEY = 2000;
    private const NIC_KEY = 3000;
    private const IDE_CONTROLLER_KEY = 4000;
    private const CDROM_KEY = 5000;

    private array $spec = [];

    public function fromConfig(VMConfig $config): self
    {
        $this->spec['name'] = $config->getName();
        // VMConfig::getMemory() already returns megabytes (see setMemory doc).
        $this->spec['memoryMB'] = $config->getMemory();
        $this->spec['numCPUs'] = $config->getCpu();
        $this->spec['guestId'] = $config->getGuestId();

        return $this;
    }

    public function withDisk(int $sizeGB, string $datastore = 'datastore1'): self
    {
        $this->spec['files'] = [
            'vmPathName' => '[' . $datastore . ']',
        ];

        $this->pushDeviceChange('add', 'VirtualLsiLogicController', [
            'key' => self::SCSI_CONTROLLER_KEY,
            'busNumber' => 0,
            'sharedBus' => 'noSharing',
        ]);

        $this->pushDeviceChange('add', 'VirtualDisk', [
            'key' => self::DISK_KEY,
            'controllerKey' => self::SCSI_CONTROLLER_KEY,
            'unitNumber' => 0,
            'capacityInKB' => $sizeGB * 1024 * 1024,
            'backing' => new SoapVar([
                'diskMode' => 'persistent',
                'thinProvisioned' => true,
                'fileName' => '[' . $datastore . ']',
            ], SOAP_ENC_OBJECT, 'VirtualDiskFlatVer2BackingInfo', self::NS),
        ], ['fileOperation' => 'create']);

        return $this;
    }

    public function withNetwork(string $networkName = 'VM Network'): self
    {
        $this->pushDeviceChange('add', 'VirtualVmxnet3', [
            'key' => self::NIC_KEY,
            'deviceInfo' => [
                'label' => 'Network Adapter 1',
                'summary' => $networkName,
            ],
            'addressType' => 'generated',
            'backing' => new SoapVar([
                'deviceName' => $networkName,
            ], SOAP_ENC_OBJECT, 'VirtualEthernetCardNetworkBackingInfo', self::NS),
            'connectable' => [
                'startConnected' => true,
                'connected' => true,
                'allowGuestControl' => true,
            ],
        ]);

        return $this;
    }

    public function withISO(?string $isoPath = null): self
    {
        if ($isoPath === null) {
            return $this;
        }

        // A freshly-built spec has no controllers unless we add them, so we
        // add a dedicated IDE controller for the CD-ROM.
        $this->pushDeviceChange('add', 'VirtualIDEController', [
            'key' => self::IDE_CONTROLLER_KEY,
            'busNumber' => 0,
        ]);

        $this->pushDeviceChange('add', 'VirtualCdrom', [
            'key' => self::CDROM_KEY,
            'controllerKey' => self::IDE_CONTROLLER_KEY,
            'unitNumber' => 0,
            'connectable' => [
                'startConnected' => true,
                'connected' => true,
                'allowGuestControl' => true,
            ],
            'backing' => new SoapVar([
                'fileName' => $isoPath,
            ], SOAP_ENC_OBJECT, 'VirtualCdromIsoBackingInfo', self::NS),
        ]);

        return $this;
    }

    public function build(): array
    {
        return $this->spec;
    }

    /**
     * @param array<string, mixed> $extra Extra keys on the VirtualDeviceConfigSpec
     *                                    itself (siblings of "device"), e.g. fileOperation.
     */
    private function pushDeviceChange(string $operation, string $deviceType, array $device, array $extra = []): void
    {
        $this->spec['deviceChange'] ??= [];

        $this->spec['deviceChange'][] = new SoapVar(
            array_merge([
                'operation' => $operation,
                'device' => new SoapVar($device, SOAP_ENC_OBJECT, $deviceType, self::NS),
            ], $extra),
            SOAP_ENC_OBJECT,
            'VirtualDeviceConfigSpec',
            self::NS
        );
    }
}