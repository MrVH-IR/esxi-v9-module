<?php
declare(strict_types=1);

namespace EsxiV9\Services;

use EsxiV9\Config\Config;
use EsxiV9\Exceptions\AuthenticationException;
use EsxiV9\SOAP\SoapConnector;
use EsxiV9\Utils\VMwareTypes;
use SoapFault;

class AuthService
{
    public function __construct(
        private readonly SoapConnector $connector,
        private readonly Config $config
    ) {}

    public function login(): bool
    {
        try {
            $client = $this->connector->getClient();

            $service = $this->connector->serviceInstance();

            $content = $service->retrieveServiceContent();

            $sessionManager = $service->getSessionManager();

            $response = $client->Login([
                '_this' => $sessionManager,
                'username' => $this->config->getUsername(),
                'password' => $this->config->getPassword(),
            ]);

            return isset($response->returnval);
        } catch (SoapFault $e) {
            throw new AuthenticationException(
                "ESXI Login Failed: " .
                $e->getMessage(),
                (int) $e->getCode());
        }
    }
}