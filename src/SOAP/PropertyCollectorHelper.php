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
     * ComputeResource -> host/resourcePool -> HostSystem/ResourcePool -> vm,
     * plus Datacenter -> datastore/network for inventory listing.
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

        // NOTE: these two were missing before, which is why datastore/network
        // listing always came back empty — nothing in the traversal ever
        // walked a Datacenter's `datastore` / `network` edges.
        $dcToDs = self::traversalSpec('datacenterDatastoreTraversalSpec', 'Datacenter', 'datastore', []);

        $dcToNet = self::traversalSpec('datacenterNetworkTraversalSpec', 'Datacenter', 'network', []);

        $hToVm = self::traversalSpec('hostVmTraversalSpec', 'HostSystem', 'vm', [
            self::selectionSpec('visitFolders'),
        ]);

        $visitFolders = self::traversalSpec('visitFolders', 'Folder', 'childEntity', [
            self::selectionSpec('visitFolders'),
            self::selectionSpec('datacenterHostTraversalSpec'),
            self::selectionSpec('datacenterVmTraversalSpec'),
            self::selectionSpec('datacenterDatastoreTraversalSpec'),
            self::selectionSpec('datacenterNetworkTraversalSpec'),
            self::selectionSpec('computeResourceHostTraversalSpec'),
            self::selectionSpec('computeResourceRpTraversalSpec'),
            self::selectionSpec('resourcePoolTraversalSpec'),
            self::selectionSpec('resourcePoolVmTraversalSpec'),
            self::selectionSpec('hostVmTraversalSpec'),
        ]);

        return [$visitFolders, $dcToHf, $dcToVmf, $dcToDs, $dcToNet, $crToH, $crToRp, $rpToRp, $rpToVm, $hToVm];
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
     * already IS the object you want properties for (e.g. a single known VM
     * or Task), to avoid needlessly traversing the whole tree.
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

        // `skip` means "don't collect properties from $rootObj itself, only
        // from what the traversal reaches". That's correct when $rootObj is
        // just a container we're walking through (e.g. rootFolder while
        // listing VMs) — but WRONG when $rootObj already IS the target
        // (e.g. a specific VM/Task looked up by id with no traversal at
        // all), because then skip=true with nothing to traverse means
        // "collect from nothing" and the result is always empty.
        $objectSet = ['obj' => $rootObj, 'skip' => !empty($selectSet)];

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

    /**
     * Normalize an object's `propSet` for safe iteration.
     *
     * Same PHP SoapClient quirk as retrieveProperties()'s returnval: when a
     * query only returns ONE property (e.g. pathSet with a single entry, or
     * an object that currently only has one of several requested properties
     * set — like a Task that's still running and only has info.state so
     * far), PHP hands back a bare stdClass instead of an array containing
     * one stdClass. Iterating that raw with `(array) $propSet` silently
     * produces the WRONG thing (the object's own fields, not a one-element
     * list), so lookups like "find the prop named vmFolder" fail even
     * though the data is right there. Always go through this helper instead
     * of casting propSet directly.
     */
    public static function normalizePropSet(mixed $propSet): array
    {
        if ($propSet === null) {
            return [];
        }

        if (is_object($propSet)) {
            return [$propSet];
        }

        return $propSet;
    }
}