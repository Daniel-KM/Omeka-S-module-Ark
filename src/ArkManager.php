<?php

namespace Ark;

use Ark\Name\PluginManager as NamePlugins;
use Ark\Qualifier\PluginManager as QualifierPlugins;
use Omeka\Api\Manager as ApiManager;
use Omeka\Api\Representation\AbstractResourceEntityRepresentation;
use Omeka\Settings\Settings;

class ArkManager
{
    /**
     * @var ApiManager
     */
    protected $api;

    /**
     * @var Settings
     */
    protected $settings;

    /**
     * @var NamePlugins
     */
    protected $namePlugins;

    /**
     * @var QualifierPlugins
     */
    protected $qualifierPlugins;

    /**
     * @param ApiManager $api
     * @param Settings $settings
     * @param NamePlugins $namePlugins
     * @param QualifierPlugins $qualifierPlugins
     */
    public function __construct(
        ApiManager $api,
        Settings $settings,
        NamePlugins $namePlugins,
        QualifierPlugins $qualifierPlugins
    ) {
        $this->api = $api;
        $this->settings = $settings;
        $this->namePlugins = $namePlugins;
        $this->qualifierPlugins = $qualifierPlugins;
    }

    /**
     * Find the resource from an ark.
     *
     * @param string|array $ark
     * @return AbstractResourceEntityRepresentation|null
     */
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

        foreach (['items', 'item_sets', 'media'] as $resourceType) {
            $resources = $this->api->search($resourceType, [
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

    /**
     * Return the ark of a resource, if any.
     *
     * @param AbstractResourceEntityRepresentation $resource
     * @return Ark
     */
    public function getArk(AbstractResourceEntityRepresentation $resource)
    {
        if ($resource->resourceName() == 'media') {
            $media = $resource;
            $resource = $media->item();
        } else {
            $media = null;
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

    /**
     * @return \Ark\Name\Plugin\Noid
     */
    public function getArkNamePlugin()
    {
        return $this->namePlugins->get('noid');
    }

    /**
     * Create the ark for a resource.
     *
     * @param AbstractResourceEntityRepresentation $resource
     * @return string|null
     */
    public function createName(AbstractResourceEntityRepresentation $resource)
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
     * @param AbstractResourceEntityRepresentation $resource
     * @return string
     */
    protected function getQualifier(AbstractResourceEntityRepresentation $resource)
    {
        /** @var \Ark\Qualifier\Plugin\Internal $qualifierPlugin */
        $qualifierPlugin = $this->qualifierPlugins->get('internal');
        return $qualifierPlugin->create($resource);
    }

    protected function getResourceFromQualifier($resource, $qualifier)
    {
        /** @var \Ark\Qualifier\Plugin\Internal $qualifierPlugin */
        $qualifierPlugin = $this->qualifierPlugins->get('internal');

        return $qualifierPlugin->getResourceFromQualifier($resource, $qualifier);
    }

    /**
     * Check if a full ark is a true ark.
     *
     * @param string $ark
     * @return boolean
     */
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
     * Check if an ark exists in the database.
     *
     * @param string $ark The full well formed ark, with "ark:/"
     * @return bool
     */
    protected function arkExists($ark)
    {
        return (bool) $this->find($ark);
    }
}
