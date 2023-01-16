<?php declare(strict_types=1);

namespace Ark;

use Omeka\Stdlib\Message;

/**
 * @var Module $this
 * @var \Laminas\ServiceManager\ServiceLocatorInterface $services
 * @var string $newVersion
 * @var string $oldVersion
 *
 * @var \Omeka\Api\Manager $api
 * @var \Omeka\Settings\Settings $settings
 * @var \Doctrine\DBAL\Connection $connection
 * @var \Doctrine\ORM\EntityManager $entityManager
 * @var \Omeka\Mvc\Controller\Plugin\Messenger $messenger
 */
$plugins = $services->get('ControllerPluginManager');
$api = $plugins->get('api');
$settings = $services->get('Omeka\Settings');
$connection = $services->get('Omeka\Connection');
$messenger = $plugins->get('messenger');
$entityManager = $services->get('Omeka\EntityManager');

if (version_compare($oldVersion, '3.5.7', '<')) {
    $settings->delete('ark_use_admin');
    $settings->delete('ark_use_public');

    $settings->set('ark_name_noid_template', $settings->get('ark_noid_template'));
    $settings->delete('ark_noid_template');

    $settings->set('ark_name', 'noid');
    $settings->set('ark_qualifier', 'internal');
    $settings->set('ark_qualifier_position_format', '');
    $settings->set('ark_qualifier_static', false);
}
