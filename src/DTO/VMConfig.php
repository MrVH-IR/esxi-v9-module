<?php
declare(strict_types=1);

namespace EsxiV9\DTO;

use EsxiV9\Utils\PasswordGenerator;
use http\Exception\InvalidArgumentException;

class VMConfig {

    private ?string $name = null;
    private int $memory = 1024;
    private int $cpu = 1;
    private int $storage = 10;
    private ?string $username = null;
    private ?string $password = null;
    private array $preInstall = [];

    public function getName(): ?string
    {
        return $this->name;
    }

    public function getMemory(): int
    {
        return $this->memory;
    }

    public function getCpu(): int
    {
        return $this->cpu;
    }

    public function getStorage(): int
    {
        return $this->storage;
    }

    public function getUsername(): ?string
    {
        return $this->username;
    }

    public function getPassword(): ?string
    {
        return $this->password;
    }

    public function getPreInstall(): array
    {
        return $this->preInstall;
    }

    public function setName(string $name): self
    {
        $this->name = $name;
        return $this;
    }

    public function setMemory(int $memory): self
    {
        if ($memory < 1) {
            throw new InvalidArgumentException("Memory must be greater than 1GB");
        }
        $this->memory = $memory;
        return $this;
    }

    public function setCpu(int $cpu): self
    {
        if ($cpu < 1) {
            throw new InvalidArgumentException('CPU must be more than 1 core and cannot be null');
        }
        $this->cpu = $cpu;
        return $this;
    }

    public function setStorage(int $storage): self
    {
        if ($storage < 20) {
            throw new InvalidArgumentException("Storage cannot be less than 20GB");
        }
        $this->storage = $storage;
        return $this;
    }

    public function setUser(?string $username): self
    {
        if ($username !== null) {
            $this->username = $username;

            return $this;
        }

        $this->username = 'user_' . random_int(100000, 999999);

        return $this;
    }

    public function setPassword(?string $password): self
    {
        if ($password !== null) {
            $this->password = $password;

            return $this;
        }

        $this->password = (new PasswordGenerator())();
        return $this;
    }

    public function setPreInstall(array $preInstall): self
    {
        $this->preInstall = $preInstall;
        return $this;
    }

}