<?php

declare(strict_types=1);

namespace EsxiV9\Services;

use EsxiV9\SOAP\PropertyCollectorHelper;
use EsxiV9\SOAP\SoapConnector;
use EsxiV9\Utils\VMwareTypes;
use RuntimeException;
use SoapFault;

class DatastoreService
{
    public function __construct(
        private readonly SoapConnector $connector,
    ) {}

    /**
     * List all datastores visible on this host.
     */
    public function list(): array
    {
        $client = $this->connector->getClient();

        try {
            $content = $this->connector->serviceInstance()->retrieveServiceContent();

            $rootFolder = (array) $content['rootFolder'];
            $propertyCollector = (array) $content['propertyCollector'];

            $objects = PropertyCollectorHelper::retrieveProperties(
                $client,
                $propertyCollector,
                $rootFolder,
                [[
                    'type' => VMwareTypes::DATASTORE,
                    'pathSet' => [
                        'name',
                        'summary.capacity',
                        'summary.freeSpace',
                        'summary.accessible',
                        'summary.type',
                    ],
                ]]
            );

            return $this->normalize($objects);
        } catch (SoapFault $e) {
            throw new RuntimeException("Datastore List Failed: " . $e->getMessage(), (int) $e->getCode());
        }
    }

    /**
     * Convenience: the name of the first accessible datastore. Useful as a
     * fallback default when creating a VM without an explicit datastore.
     */
    public function findDefaultName(): ?string
    {
        foreach ($this->list() as $ds) {
            if ($ds['accessible'] === true) {
                return $ds['name'];
            }
        }

        return null;
    }

    /**
     * Recursively search a datastore for .iso files and return their full
     * bracket-notation paths (e.g. "[datastore1] isos/ubuntu.iso"), ready
     * to hand straight to VMConfig::setIso().
     */
    public function listIsoFiles(string $datastoreName, int $timeoutSeconds = 30): array
    {
        $client = $this->connector->getClient();

        try {
            $browserRef = $this->resolveBrowser($client, $datastoreName);

            $task = $client->SearchDatastoreSubFolders_Task([
                '_this' => $browserRef,
                'datastorePath' => "[{$datastoreName}]",
                'searchSpec' => [
                    'matchPattern' => ['*.iso'],
                    'searchCaseInsensitive' => true,
                ],
            ]);

            $taskId = $task->returnval->_ ?? null;

            if ($taskId === null) {
                throw new RuntimeException('SearchDatastoreSubFolders_Task did not return a task reference.');
            }

            $result = (new TaskService($this->connector))->wait($taskId, $timeoutSeconds);

            if (!$result->isSuccess()) {
                throw new RuntimeException('ISO search failed: ' . ($result->getError() ?? 'unknown error'));
            }

            return $this->normalizeIsoResults($result->getResult());
        } catch (SoapFault $e) {
            throw new RuntimeException("ISO Search Failed: " . $e->getMessage(), (int) $e->getCode());
        }
    }

    /**
     * Find the given datastore's HostDatastoreBrowser reference (needed to
     * search its files) by name.
     */
    private function resolveBrowser(\SoapClient $client, string $datastoreName): array
    {
        $content = $this->connector->serviceInstance()->retrieveServiceContent();
        $rootFolder = (array) $content['rootFolder'];
        $propertyCollector = (array) $content['propertyCollector'];

        $objects = PropertyCollectorHelper::retrieveProperties(
            $client,
            $propertyCollector,
            $rootFolder,
            [[
                'type' => VMwareTypes::DATASTORE,
                'pathSet' => ['name', 'browser'],
            ]]
        );

        foreach ($objects as $object) {
            $props = [];
            foreach ((array) ($object->propSet ?? []) as $prop) {
                $props[$prop->name] = $prop->val;
            }

            if (($props['name'] ?? null) === $datastoreName && isset($props['browser'])) {
                return ['type' => $props['browser']->type, '_' => $props['browser']->_];
            }
        }

        throw new RuntimeException("Datastore not found or has no browser: {$datastoreName}");
    }

    /**
     * SearchDatastoreSubFolders_Task returns one HostDatastoreBrowserSearchResults
     * per folder visited, each with a folderPath and its own file[] array.
     * Flatten that into a single list of full "[ds] path/to/file.iso" paths.
     */
    private function normalizeIsoResults(mixed $searchResults): array
    {
        if ($searchResults === null) {
            return [];
        }

        $folders = is_array($searchResults) ? $searchResults : [$searchResults];
        $isos = [];

        foreach ($folders as $folder) {
            $folderPath = rtrim((string) ($folder->folderPath ?? ''), '/');
            $files = $folder->file ?? [];
            $files = is_array($files) ? $files : [$files];

            foreach ($files as $file) {
                $fileName = $file->path ?? null;

                if ($fileName === null) {
                    continue;
                }

                $isos[] = [
                    'path' => "{$folderPath}/{$fileName}",
                    'name' => $fileName,
                    'sizeBytes' => $file->fileSize ?? null,
                ];
            }
        }

        return $isos;
    }

    private function normalize(array $objects): array
    {
        $result = [];

        foreach ($objects as $object) {
            $props = [];

            foreach ((array) ($object->propSet ?? []) as $prop) {
                $props[$prop->name] = $prop->val;
            }

            $capacity = (int) ($props['summary.capacity'] ?? 0);
            $free = (int) ($props['summary.freeSpace'] ?? 0);

            $result[] = [
                'id' => $object->obj->_ ?? null,
                'name' => $props['name'] ?? null,
                'type' => $props['summary.type'] ?? null,
                'accessible' => $props['summary.accessible'] ?? null,
                'capacityGB' => $capacity > 0 ? round($capacity / 1024 / 1024 / 1024, 2) : 0,
                'freeSpaceGB' => $free > 0 ? round($free / 1024 / 1024 / 1024, 2) : 0,
            ];
        }

        return $result;
    }
}