<?php
declare(strict_types=1);

namespace EsxiV9\SOAP;

use EsxiV9\Config\Config;
use EsxiV9\SOAP\ServiceInstanceResolver;
use EsxiV9\Exceptions\SoapConnectionException;
use SoapClient;
use SoapFault;

class SoapConnector
{
    private SoapClient $client;

    public function __construct(
        private readonly Config $config
    ) {
        $this->connect();
    }

    private function connect(): void
    {
        try {

            $context = stream_context_create([
                'ssl' => [
                    'verify_peer' => !$this->config->allowedSelfSigned(),
                    'verify_peer_name' => !$this->config->allowedSelfSigned(),
                    'allow_self_signed' => $this->config->allowedSelfSigned(),
                ]
            ]);

            $this->client = new SoapClient(
                $this->config->getWsdlUrl(),
                [
                    'trace' => true,
                    'exceptions' => true,
                    'cache_wsdl' => WSDL_CACHE_NONE,
                    'stream_context' => $context,
                    'connection_timeout' => $this->config->getTimeout(),
                ]
            );

        } catch (SoapFault $e) {
            throw new SoapConnectionException(
                $e->getMessage(),
                $e->getCode(),
                $e
            );
        }
    }

    public function getClient(): SoapClient
    {
        return $this->client;
    }

    public function serviceInstance(): ServiceInstanceResolver
    {
        return new ServiceInstanceResolver($this->client);
    }
}