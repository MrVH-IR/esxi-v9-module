<?php
declare(strict_types=1);

namespace EsxiV9\Config;

class Config {

    public function __construct(
        private readonly string $host,
        private readonly string $username,
        private readonly string $password,
        private readonly bool $ssl = true,
        private readonly bool $allowedSelfSigned = true,
        private readonly int $timeout = 30
    )
    {}

    public function getHost(): string
    {
        return $this->host;
    }

    public function getUsername(): string
    {
        return $this->username;
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function isSsl(): bool
    {
        return $this->ssl;
    }

    public function allowedSelfSigned(): bool
    {
        return $this->allowedSelfSigned;
    }

    public function getTimeout(): int
    {
        return $this->timeout;
    }

    public function getWsdlUrl(): string
    {
        $protocol = $this->ssl ? 'https' : 'http';

        return sprintf(
            '%s://%s/sdk/vimService.wsdl',
            $protocol,
            $this->host
        );
    }
}