<?php

namespace Ark;

use Omeka\Api\Representation\AbstractResourceEntityRepresentation;
use Ark\Name\Noid;
use Ark\Qualifier\Internal;

class ArkManager
{
    protected $api;
    protected $settings;
    protected $qualifierPlugins;
    protected $namePlugins;

    public function __construct($api, $settings, $qualifierPlugins, $namePlugins)
    {
        $this->api = $api;
        $this->settings = $settings;
        $this->qualifierPlugins = $qualifierPlugins;
        $this->namePlugins = $namePlugins;
    }

    public function find($ark)
    {
        if (empty($ark)) {
            return null;
        }

        $protocol = 'ark:';
        $naan = $this->settings->get('ark_naan');
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
            } else {
                $name = substr($fullName, 0, $pos);
                $qualifier = substr($fullName, $pos + 1);
            }
        } elseif (is_array($ark)) {
            if ($ark['naan'] !== $naan
                    || empty($ark['name']) || $ark['name'] == '?' || $ark['name'] == '??'
                ) {
                return null;
            }
            $name = $ark['name'];
            $qualifier = empty($ark['qualifier']) ? null : $ark['qualifier'];
        } else {
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
                    [
                        'property' => $property->id(),
                        'type' => 'eq',
                        'text' => $base . $name,
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

        if ($qualifier) {
            $qualifierResource = $this->getResourceFromQualifier($resource, $qualifier);
            if ($qualifierResource) {
                $resource = $qualifierResource;
            }
        }

        return $resource;
    }

    public function getArk(AbstractResourceEntityRepresentation $resource)
    {
        $media = null;
        if ($resource->resourceName() == 'media') {
            $media = $resource;
            $resource = $media->item();
        }

        $identifiers = $resource->value('dcterms:identifier', ['all' => true, 'type' => 'literal']);
        $protocol = 'ark:';
        $naan = $this->settings->get('ark_naan');
        $base = $naan ? "$protocol/$naan/" : "$protocol/";
        $ark = null;
        if (!empty($identifiers)) {
            foreach ($identifiers as $identifier) {
                if (strpos($identifier->value(), $base) === 0) {
                    $ark = $identifier->value();
                    break;
                }
            }
        }

        if ($ark) {
            $ark = new Ark($naan, substr($ark, strlen($base)));

            if ($media) {
                $qualifier = $this->getQualifier($media);
                $ark->setQualifier($qualifier);
            }
        }

        return $ark;
    }

    public function getArkNamePlugin()
    {
        return $this->namePlugins->get('noid');
    }

    public function createName($resource)
    {
        if (empty($resource)) {
            return;
        }

        $naan = $this->settings->get('ark_naan');

        $namePlugin = $this->namePlugins->get('noid');
        $ark = $namePlugin->create($resource);

        // Check the result.
        if (empty($ark)) {
            $message = sprintf('No Ark created: check your format "%s" [%s #%d].',
                get_class($namePlugin), get_class($resource), $resource->id());
            error_log('[Ark&Noid] ' . $message);

            return;
        }

        // Complete partial ark.
        $mainPart = $ark;
        // Check ark (useful only for external process).
        if (!$this->checkFullArk($ark)) {
            $message = sprintf('Ark "%s" is not correct: check your format "%s" and your processor [%s].', $ark, get_class($namePlugin), get_class($resource));
            error_log('[Ark&Noid] ' . $message);

            return;
        }

        // Add the protocol.
        $ark = 'ark:/' . $ark;

        // Check if the ark is single.
        if ($this->arkExists($ark)) {
            if ($namePlugin->isFullArk()) {
                $message = sprintf('The proposed ark "%s" is not unique [%s #%d].',
                    $ark, get_class($resource), $resource->id());
                error_log('[Ark&Noid] ' . $message);

                return;
            }

            $message = 'Unable to create a unique ark.'
                . ' ' . sprintf('Check parameters of the format "%s" [%s #%d].',
                get_class($namePlugin), get_class($resource), $resource->id());
            error_log('[Ark&Noid] ' . $message);

            return;
        }

        return $ark;
    }

    /**
     * Return the qualifier part of an ark.
     *
     * @return string The qualifier
     */
    protected function getQualifier($resource)
    {
        $qualifierPlugin = $this->qualifierPlugins->get('internal');

        return $qualifierPlugin->create($resource);
    }

    protected function getResourceFromQualifier($resource, $qualifier)
    {
        $qualifierPlugin = $this->qualifierPlugins->get('internal');

        return $qualifierPlugin->getResourceFromQualifier($resource, $qualifier);
    }

    protected function checkFullArk($ark)
    {
        $ark = trim($ark);
        $result = explode('/', $ark);

        $naan = $this->settings->get('ark_naan');

        if ($naan) {
            if (count($result) != 2) {
                return false;
            }
            if ($result[0] != $naan) {
                return false;
            }

            $clean = preg_replace('/[^a-zA-Z0-9]/', '', $result[1]);

            return $clean == $result[1];
        }

        // Else no naan.
        if (strpos($ark, '/') !== false) {
            return false;
        }

        $clean = preg_replace('/[^a-zA-Z0-9]/', '', $ark);

        return $clean == $ark;
    }

    /**
     * Check if an ark exists in the base.
     *
     * @param string $ark The full well formed ark, with "ark:/"
     *
     * @return bool
     */
    protected function arkExists($ark)
    {
        return (bool) $this->find($ark);
    }
}
