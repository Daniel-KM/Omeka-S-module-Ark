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
