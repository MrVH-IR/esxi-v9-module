<?php
declare(strict_types=1);

namespace EsxiV9\SOAP;

use EsxiV9\DTO\ServiceInstance;
use SoapClient;

class ServiceInstanceResolver
{
    private array $serviceContent = [];

    public function __construct(
        private readonly SoapClient $client
    ) {}

    /**
     * Fetch Service Content From ESXI
     */

    public function retrieveServiceContent(): array
    {
        $response = $this->client->RetrieveServiceContent([
            '_this' => (new ServiceInstance())->toArray()
        ]);

        $this->serviceContent = (array) $response->returnval;

        return $this->serviceContent;
    }

    /**
     * Step 2: Get SessionManager reference
     */
    public function getSessionManager(): array
    {
        if (empty($this->serviceContent)) {
            $this->retrieveServiceContent();
        }

        return (array) $this->serviceContent['sessionManager'];
    }

    /**
     * Debug helper
     */
    public function getServiceContent(): array
    {
        return $this->serviceContent;
    }
}