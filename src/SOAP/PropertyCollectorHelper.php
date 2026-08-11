<?php

declare(strict_types=1);

namespace EsxiV9\SOAP;

use SoapClient;
use SoapVar;

/**
 * Helper for building PropertyCollector traversal specs and querying
 * the vSphere inventory tree.
 *
 * Why this exists: RetrieveProperties() will NOT recurse into folders/
 * datacenters/hosts on its own. Without an explicit traversal (selectSet),
 * a query rooted at rootFolder returns an empty result even if VMs exist.
 * This builds the classic "full traversal spec" used across VMware SDKs
 * so it works for both standalone ESXi and vCenter-managed inventories.
 */
class PropertyCollectorHelper
{
    private const NS = 'urn:vim25';

    /**
     * Full traversal spec: Folder -> childEntity -> (Datacenter, Folder) ->
     * hostFolder/vmFolder -> ComputeResource/VirtualMachine, plus
     * ComputeResource -> host/resourcePool -> HostSystem/ResourcePool -> vm.
     */
    public static function fullTraversal(): array
    {
        $rpToRp = self::traversalSpec('resourcePoolTraversalSpec', 'ResourcePool', 'resourcePool', [
            self::selectionSpec('resourcePoolTraversalSpec'),
            self::selectionSpec('resourcePoolVmTraversalSpec'),
        ]);

        $rpToVm = self::traversalSpec('resourcePoolVmTraversalSpec', 'ResourcePool', 'vm', []);

        $crToRp = self::traversalSpec('computeResourceRpTraversalSpec', 'ComputeResource', 'resourcePool', [
            self::selectionSpec('resourcePoolTraversalSpec'),
            self::selectionSpec('resourcePoolVmTraversalSpec'),
        ]);

        $crToH = self::traversalSpec('computeResourceHostTraversalSpec', 'ComputeResource', 'host', []);

        $dcToHf = self::traversalSpec('datacenterHostTraversalSpec', 'Datacenter', 'hostFolder', [
            self::selectionSpec('visitFolders'),
        ]);

        $dcToVmf = self::traversalSpec('datacenterVmTraversalSpec', 'Datacenter', 'vmFolder', [
            self::selectionSpec('visitFolders'),
        ]);

        $hToVm = self::traversalSpec('hostVmTraversalSpec', 'HostSystem', 'vm', [
            self::selectionSpec('visitFolders'),
        ]);

        $visitFolders = self::traversalSpec('visitFolders', 'Folder', 'childEntity', [
            self::selectionSpec('visitFolders'),
            self::selectionSpec('datacenterHostTraversalSpec'),
            self::selectionSpec('datacenterVmTraversalSpec'),
            self::selectionSpec('computeResourceHostTraversalSpec'),
            self::selectionSpec('computeResourceRpTraversalSpec'),
            self::selectionSpec('resourcePoolTraversalSpec'),
            self::selectionSpec('resourcePoolVmTraversalSpec'),
            self::selectionSpec('hostVmTraversalSpec'),
        ]);

        return [$visitFolders, $dcToHf, $dcToVmf, $crToH, $crToRp, $rpToRp, $rpToVm, $hToVm];
    }

    private static function traversalSpec(string $name, string $type, string $path, array $selectSet): SoapVar
    {
        return new SoapVar([
            'name' => $name,
            'type' => $type,
            'path' => $path,
            'skip' => false,
            'selectSet' => $selectSet,
        ], SOAP_ENC_OBJECT, 'TraversalSpec', self::NS);
    }

    private static function selectionSpec(string $name): SoapVar
    {
        return new SoapVar([
            'name' => $name,
        ], SOAP_ENC_OBJECT, 'SelectionSpec', self::NS);
    }

    /**
     * Query the inventory. Pass an empty $selectSet (not null) when $rootObj
     * already IS the object you want properties for (e.g. a single known VM),
     * to avoid needlessly traversing the whole tree.
     *
     * @param array $propertyCollector MOR array (type + _) for the PropertyCollector,
     *                                  read from serviceContent.propertyCollector.
     * @param array $rootObj MOR array (type + _) to start the query from.
     * @param array $propSet e.g. [['type' => 'VirtualMachine', 'pathSet' => ['name']]]
     * @param array|null $selectSet Traversal spec list. Defaults to fullTraversal().
     */
    public static function retrieveProperties(
        SoapClient $client,
        array $propertyCollector,
        array $rootObj,
        array $propSet,
        ?array $selectSet = null
    ): array {
        $selectSet ??= self::fullTraversal();

        $objectSet = ['obj' => $rootObj, 'skip' => true];

        if (!empty($selectSet)) {
            $objectSet['selectSet'] = $selectSet;
        }

        $response = $client->RetrieveProperties([
            '_this' => $propertyCollector,
            'specSet' => [
                [
                    'propSet' => $propSet,
                    'objectSet' => [$objectSet],
                ],
            ],
        ]);

        $returnval = $response->returnval ?? [];

        if ($returnval === null) {
            return [];
        }

        // PHP's SoapClient does not wrap a single result in an array.
        return is_array($returnval) ? $returnval : [$returnval];
    }
}