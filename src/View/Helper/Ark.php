<?php

namespace Ark\View\Helper;

use Zend\View\Helper\AbstractHelper;
use Omeka\Api\Representation\AbstractResourceEntityRepresentation;

/**
 * Helper to get or create ark.
 */
class Ark extends AbstractHelper
{
    /**
     * Return the ark of a record.
     *
     * @param AbstractRecord|array $record Record object or array with record
     * type and record id.
     * @param string $type  Optional type: text (default), name, absolute, link,
     * or route.
     * @return string The ark of the record, if any.
     */
    public function __invoke($record, $type = 'text')
    {
        if (!$record instanceof AbstractResourceEntityRepresentation) {
            return '';
        }

        $ark = $this->_getArk($record, $type == 'route');
        if (empty($ark)) {
            return '';
        }

        switch ($type) {
            case 'link':
                return sprintf('<a href="%s">%s</a>', $ark, $ark);
            case 'absolute':
                return $ark;
            case 'name':
                $protocol = 'ark:';
                $naan = '99999';
                return substr($ark, $naan ? strlen("$protocol/$naan/") : strlen("$protocol/"));
            case 'route':
            case 'text':
            default:
                return $ark;
        }
    }

    /**
     * Get the ark for the record.
     *
     * @param AbstractRecord|array $record Record object or array with record
     * type and record id.
     * @param boolean $asRoute Return as array or as string.
     * @return string|array|null The ark of the record, or null.
     */
    private function _getArk($resource, $asArray = false)
    {
        if (empty($resource)) {
            return;
        }

        $file = null;
        if ($resource->resourceName() == 'Media') {
            $media = $resource;
            $resource = $media->item();
        }

        // Unlike controller, the element texts are already loaded here, so this
        // avoids a direct query.
        $identifiers = $resource->value('dcterms:identifier', ['all' => true]);
        $protocol = 'ark:';
        $naan = '99999';
        $base = $naan ? "$protocol/$naan/" : "$protocol/";
        $ark = null;
        foreach ($identifiers as $identifier) {
            if (strpos($identifier->value(), $base) === 0) {
                $ark = $identifier->value();
                break;
            }
        }

        if ($ark) {
            if ($asArray) {
                $ark = [
                    'naan' => $naan,
                    'name' => substr($ark, strlen($base)),
                ];
            }

            if ($media) {
                $qualifier = $this->_getQualifier($media);
                if ($asArray) {
                    $ark['qualifier'] = $qualifier;
                }
                else {
                    $ark .= '/' . $qualifier;
                }
            }
        }

        return $ark;
    }

    /**
     * Return the qualifier part of an ark.
     *
     * @param AbstractRecord $record
     * @return string The qualifier.
     */
    protected function _getQualifier($resource)
    {
        $arkQualifier = new \Ark\Ark\Qualifier\Internal([
            'record' => $resource,
            'format' => 'omeka_id',
        ]);
        return $arkQualifier->create($resource);
    }
}
