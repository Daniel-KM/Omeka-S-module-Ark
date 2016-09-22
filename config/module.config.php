<?php

return [
    'form_elements' => [
        'factories' => [
            'Ark\Form\ConfigForm' => 'Ark\Service\Form\ConfigFormFactory',
        ],
    ],
    'view_manager' => [
        'template_path_stack' => [
            __DIR__ . '/../view/',
        ],
    ],
    'view_helpers' => [
        'factories' => [
            'ark' => 'Ark\Service\View\Helper\ArkFactory',
        ],
    ],
    'service_manager' => [
        'factories' => [
            'Ark\ArkManager' => 'Ark\Service\ArkManagerFactory',
            'Ark\QualifierPluginManager' => 'Ark\Service\QualifierPluginManagerFactory',
            'Ark\NamePluginManager' => 'Ark\Service\NamePluginManagerFactory',
        ],
    ],
    'controller_plugins' => [
        'factories' => [
            'ark' => 'Ark\Service\ControllerPlugin\ArkFactory',
        ],
    ],
    'controllers' => [
        'invokables' => [
            'Ark\Controller\Index' => 'Ark\Controller\IndexController',
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
    'ark_qualifier_plugins' => [
        'factories' => [
            'internal' => 'Ark\Service\QualifierPlugin\InternalFactory',
        ],
    ],
    'ark_name_plugins' => [
        'factories' => [
            'noid' => 'Ark\Service\NamePlugin\NoidFactory',
        ],
    ],
];
