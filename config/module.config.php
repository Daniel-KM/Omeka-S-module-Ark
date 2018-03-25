<?php
namespace Ark;

return [
    'service_manager' => [
        'invokables' => [
            'Ark\MvcListeners' => Mvc\MvcListeners::class,
        ],
        'factories' => [
            'Ark\ArkManager' => Service\ArkManagerFactory::class,
            'Ark\QualifierPluginManager' => Service\QualifierPluginManagerFactory::class,
            'Ark\NamePluginManager' => Service\NamePluginManagerFactory::class,
        ],
    ],
    'listeners' => [
        'Ark\MvcListeners',
    ],
    'view_manager' => [
        'template_path_stack' => [
            dirname(__DIR__) . '/view/',
        ],
    ],
    'view_helpers' => [
        'factories' => [
            'ark' => Service\View\Helper\ArkFactory::class,
        ],
    ],
    'form_elements' => [
        'factories' => [
            'Ark\Form\ConfigForm' => Service\Form\ConfigFormFactory::class,
        ],
    ],
    'controllers' => [
        'invokables' => [
            'Ark\Controller\Index' => Controller\IndexController::class,
        ],
    ],
    'controller_plugins' => [
        'factories' => [
            'ark' => Service\ControllerPlugin\ArkFactory::class,
        ],
    ],
    'router' => [
        'routes' => [
            'site' => [
                'child_routes' => [
                    'ark' => [
                        'type' => 'Literal',
                        'options' => [
                            'route' => '/ark:',
                            'defaults' => [
                                '__NAMESPACE__' => 'Ark\Controller',
                                'controller' => 'Index',
                            ],
                        ],
                        'child_routes' => [
                            'default' => [
                                'type' => 'Segment',
                                'options' => [
                                    'route' => '/:naan/:name[/:qualifier]',
                                    'constraints' => [
                                        'naan' => '\d{5}',
                                        'name' => '[A-Za-z0-9_]+',
                                        'qualifier' => '[A-Za-z0-9_.]+',
                                    ],
                                    'defaults' => [
                                        'action' => 'index',
                                    ],
                                ],
                            ],
                            'policy' => [
                                'type' => 'Segment',
                                'options' => [
                                    'route' => '/:naan',
                                    'defaults' => [
                                        'action' => 'policy',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
    'ark' => [
        'config' => [
            // 12345 means example and 99999 means test.
            'ark_naan' => '99999',
            'ark_naa' => 'example.org',
            'ark_subnaa' => 'sub',
            'ark_noid_template' => '.zek',
            'ark_note' => '',
            'ark_policy_statement' => 'erc-support:
who: Our Institution
what: Permanent: Stable Content:
when: 20160101
where: http://example.com/ark:/99999/',
            // From the policy statement of the California Digital Library.
            'ark_policy_main' => 'Our institution assigns identifiers within the ARK domain under the NAAN 99999 and according to the following principles:

* No ARK shall be re-assigned; that is, once an ARK-to-object association has been made public, that association shall be considered unique into the indefinite future.
* To help them age and travel well, the Name part of our institution-assigned ARKs shall contain no widely recognizable semantic information (to the extent possible).
* Our institution-assigned ARKs shall be generated with a terminal check character that guarantees them against single character errors and transposition errors.',
        ],
    ],
    'ark_qualifier_plugins' => [
        'factories' => [
            'internal' => Service\QualifierPlugin\InternalFactory::class,
        ],
    ],
    'ark_name_plugins' => [
        'factories' => [
            'noid' => Service\NamePlugin\NoidFactory::class,
        ],
    ],
];
