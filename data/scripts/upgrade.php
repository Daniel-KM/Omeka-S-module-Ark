<?php declare(strict_types=1);

namespace Ark;

use Common\Stdlib\PsrMessage;

/**
 * @var Module $this
 * @var \Laminas\ServiceManager\ServiceLocatorInterface $services
 * @var string $newVersion
 * @var string $oldVersion
 *
 * @var \Omeka\Api\Manager $api
 * @var \Omeka\View\Helper\Url $url
 * @var \Omeka\Settings\Settings $settings
 * @var \Doctrine\DBAL\Connection $connection
 * @var \Doctrine\ORM\EntityManager $entityManager
 * @var \Omeka\Mvc\Controller\Plugin\Messenger $messenger
 */
$plugins = $services->get('ControllerPluginManager');
$url = $services->get('ViewHelperManager')->get('url');
$api = $plugins->get('api');
$settings = $services->get('Omeka\Settings');
$translate = $plugins->get('translate');
$connection = $services->get('Omeka\Connection');
$messenger = $plugins->get('messenger');
$entityManager = $services->get('Omeka\EntityManager');

if (!method_exists($this, 'checkModuleActiveVersion') || !$this->checkModuleActiveVersion('Common', '3.4.54')) {
    $message = new \Omeka\Stdlib\Message(
        $translate('The module %1$s should be upgraded to version %2$s or later.'), // @translate
        'Common', '3.4.54'
    );
    throw new \Omeka\Module\Exception\ModuleCannotInstallException((string) $message);
}

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

if (version_compare($oldVersion, '3.5.14', '<')) {
    $settings->set('ark_property', 'dcterms:identifier');
    $message = new PsrMessage(
        'It is now possible to define a specific property to store arks. Warning: if you change it, old arks won’t be moved (use module {link}Bulk Edit{link_end} for that).', // @translate
        ['link' => '<a href="https://gitlab.com/Daniel-KM/Omeka-S-module-BulkEdit" target="_blank" rel="noopener">', 'link_end' => '</a>']
    );
    $message->setEscapeHtml(false);
    $messenger->addSuccess($message);
}
