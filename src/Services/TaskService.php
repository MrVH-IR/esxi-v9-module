<?php
declare(strict_types=1);

namespace EsxiV9\Services;

use EsxiV9\SOAP\SoapConnector;
use EsxiV9\Utils\VMwareTypes;

class TaskService
{

    public function __construct(
        private readonly SoapConnector $connector,
    ) {}

    public function getStatus(string $taskId): string
    {
        $client = $this->connector->getClient();

        $response = $client->GetTaskInfo([
            '_this' => [
                'type' => VMwareTypes::TASK,
                '_' => $taskId
            ]
        ]);

        return $response->returnval->state ?? 'unknown';
    }

    public function wait(string $taskId, int $timeout = 60): bool
    {
        $start = time();

        while (true) {

            $status = $this->getStatus($taskId);

            if ($status === 'success') {
                return true;
            }

            if ($status === 'error') {
                return false;
            }

            if ((time() - $start) > $timeout) {
                throw new \RuntimeException("Task timeout");
            }

            usleep(500000);
        }
    }
}