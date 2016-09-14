<?php

namespace Ark\Ark;

class ArkHelper {
    protected $api;

    public function __construct($api)
    {
        $this->api = $api;
    }

    public function getRecordFromArk($ark)
    {
        if (empty($ark)) {
            return null;
        }

        $protocol = 'ark:';
        $naan = '99999';
        $base = $naan ? "$protocol/$naan/" : "$protocol/";

        if (is_string($ark)) {
            // Quick check of format.
            if (strpos($ark, $base) !== 0) {
                return null;
            }

            // This is the ark of the naan.
            if ($ark == $base) {
                return null;
            }

            $fullName = substr($ark, strlen($base));
            if ($fullName == '?' || $fullName == '??') {
                return null;
            }

            // Get the identifier and the qualifier parts.
            $pos = strpos($fullName, '/');
            if ($pos === false) {
                $name = $fullName;
                $qualifier = '';
            }
            else {
                $name = substr($fullName, 0, $pos);
                $qualifier = substr($fullName, $pos + 1);
            }
        }
        elseif (is_array($ark)) {
             if ($ark['naan'] !== $naan
                    || empty($ark['name']) || $ark['name'] == '?' || $ark['name'] == '??'
                ) {
                return null;
            }
            $name = $ark['name'];
            $qualifier = empty($ark['qualifier']) ? null : $ark['qualifier'];
        }
        else {
            return null;
        }

        $properties = $this->api->search('properties', ['term' => 'dcterms:identifier'])->getContent();
        $property = $properties[0];
        if (empty($property)) {
            return null;
        }

        foreach (['items', 'item_sets', 'media'] as $resourceName) {
            $resources = $this->api->search($resourceName, [
                'property' => [
                    $property->id() => [
                        'eq' => [ $base . $name ],
                    ],
                ],
                'limit' => 1,
            ])->getContent();

            if (!empty($resources)) {
                break;
            }
        }
        if (empty($resources)) {
            return null;
        }

        $resource = $resources[0];

        return $resource;
    }

    /**
     * Return the record from the qualifier part of an ark.
     *
     * @param AbstractRecord $record Main record (item).
     * @param string $qualifier The qualifier part of the ark.
     * @return AbstractRecord|null The record, if any.
     */
    protected function _getRecordFromQualifier($record, $qualifier)
    {
        $arkQualifier = new \Ark\Ark\Qualifier\Internal(array(
            'record' => $record,
            'format' => 'omeka_id',
        ));
        return $arkQualifier->getRecordFromQualifier($record, $qualifier);
    }
}
