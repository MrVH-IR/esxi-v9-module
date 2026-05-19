<?php
declare(strict_types=1);

namespace EsxiV9\VM;

use EsxiV9\DTO\VMConfig;

class VMSpecBuilder
{
    private array $spec = [];

    public function fromConfig(VMConfig $config): self
    {
        $this->spec['name'] = $config->getName();
        $this->spec['memoryMB'] = $config->getMemory();
        $this->spec['numCPUs'] = $config->getCpu();
        $this->spec['guestId'] = 'otherGuest64Bit';
        return $this;
    }

    public function withDisk(int $sizeGB , string $datastore = "datastore1"): self
    {
        $this->spec['files'] = [
            'vmPathName' => '[' . $datastore . ']'
        ];

        $this->spec['disk'] = [
            'sizeGB' => $sizeGB,
            'type' => 'thin'
        ];

        return $this;
    }

    public function withNetwork(string $networkName = "VM Network"): self
    {
        $this->spec['network'] = [
            'networkName' => $networkName,
            'adapter'   => 'vmxnet3'
        ];

        return $this;
    }

    public function withISO(?string $isoPath = null): self
    {
        $this->spec['iso'] = [
            'iso' => $isoPath,
            'connected' => $isoPath !== null
        ];

        return $this;
    }

    public function build(): array
    {
        return $this->spec;
    }
}