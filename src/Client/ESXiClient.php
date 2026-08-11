<?php
declare(strict_types=1);

namespace EsxiV9\Client;

use EsxiV9\Config\Config;
use EsxiV9\Services\AuthService;
use EsxiV9\Services\DatastoreService;
use EsxiV9\Services\NetworkService;
use EsxiV9\Services\VMResolver;
use EsxiV9\Services\VMService;
use EsxiV9\SOAP\SoapConnector;

class ESXiClient
{
    private SoapConnector $connector;

    public function __construct(
        private readonly Config $config
    ) {
        $this->connector = new SoapConnector($config);
    }

    public function datastore(): DatastoreService
    {
        return new DatastoreService($this->connector);
    }

    public function network(): NetworkService
    {
        return new NetworkService($this->connector);
    }

    public function auth(): AuthService
    {
        return new AuthService(
            $this->connector,
            $this->config
        );
    }

    public function vm(): VMService
    {
        return new VMService(
            $this->connector,
        );
    }

    public function vmResolver(): VMResolver
    {
        return new VMResolver(
            $this->vm()
        );
    }
}
