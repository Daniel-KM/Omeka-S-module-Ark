<?php

namespace Ark\Ark\Qualifier;

/**
 * Change the format for Ark qualifier.
 *
 * @package Ark
 */
class Internal extends AbstractQualifier
{
    protected function _create($resource)
    {
        return $resource->id();
    }

    protected function _getRecordFromQualifier($record, $qualifier)
    {
        switch ($resource->resourceName()) {
            case 'ItemSet':
                return;

            case 'Item':
                switch ($this->_getParameter('format')) {
                    case 'omeka_id':
                        $qualifier = (integer) $qualifier;
                        if (empty($qualifier)) {
                            return;
                        }
                        $qualifierRecord = $this->api->read('media', $qualifier)->getContent();
                        break;


                    default;
                        return;
                }
                return empty($qualifierRecord) || $qualifierRecord->item()->id() != $resource->id()
                    ? null
                    : $qualifierRecord;
        }
    }
}
