<?php

declare(strict_types=1);

namespace EsxiV9\Services;

use EsxiV9\DTO\TaskResult;
use EsxiV9\SOAP\PropertyCollectorHelper;
use EsxiV9\SOAP\SoapConnector;
use EsxiV9\Utils\VMwareTypes;
use RuntimeException;

class TaskService
{
    public function __construct(
        private readonly SoapConnector $connector,
    ) {}

    /**
     * Read a Task's current info (state/error/result).
     *
     * NOTE: vim25 has no "GetTaskInfo" SOAP method. A Task managed object
     * only exposes `info` as a PROPERTY, so this must go through
     * PropertyCollector, not a direct method call.
     */
    public function getInfo(string $taskId): TaskResult
    {
        $client = $this->connector->getClient();
        $content = $this->connector->serviceInstance()->retrieveServiceContent();
        $propertyCollector = (array) $content['propertyCollector'];

        $objects = PropertyCollectorHelper::retrieveProperties(
            $client,
            $propertyCollector,
            ['type' => VMwareTypes::TASK, '_' => $taskId],
            [[
                'type' => VMwareTypes::TASK,
                'pathSet' => ['info.state', 'info.error', 'info.result'],
            ]],
            [] // no traversal: obj IS the task we want
        );

        $props = [];
        foreach ((array) ($objects[0]->propSet ?? []) as $prop) {
            $props[$prop->name] = $prop->val;
        }

        $state = $props['info.state'] ?? 'unknown';
        $error = null;

        if (isset($props['info.error'])) {
            $errObj = $props['info.error'];
            $error = is_object($errObj) && isset($errObj->localizedMessage)
                ? $errObj->localizedMessage
                : (is_string($errObj) ? $errObj : 'Unknown task error');
        }

        return new TaskResult($taskId, $state, $error, $props['info.result'] ?? null);
    }

    public function getStatus(string $taskId): string
    {
        return $this->getInfo($taskId)->getState();
    }

    /**
     * Poll until the task reaches a terminal state (success/error) or times out.
     * Returns the final TaskResult (check ->isSuccess() / ->getError() / ->getResult()).
     */
    public function wait(string $taskId, int $timeoutSeconds = 60, int $pollIntervalMs = 500): TaskResult
    {
        $start = time();

        while (true) {
            $result = $this->getInfo($taskId);

            if (in_array($result->getState(), ['success', 'error'], true)) {
                return $result;
            }

            if ((time() - $start) > $timeoutSeconds) {
                throw new RuntimeException(
                    "Task {$taskId} timed out after {$timeoutSeconds}s (last state: {$result->getState()})"
                );
            }

            usleep($pollIntervalMs * 1000);
        }
    }
}