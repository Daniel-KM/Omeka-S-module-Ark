<?php
return [
    'form_elements' => [
        'factories' => [
            'Ark\Form\ConfigForm' => 'Ark\Service\Form\ConfigFormFactory',
        ],
    ],

    'view_manager' => [
        'template_path_stack' => [
            __DIR__ . '/../view/admin/',

        ],
    ],
    'view_helpers' => [
        'invokables' => [
            'ark' => 'Ark\View\Helper\Ark',
        ],
    ],
    'service_manager' => [
        'factories' => [
            'Ark\ArkHelper' => 'Ark\Service\ArkHelperFactory',
        ],
    ],
    'translator' => [
        'translation_file_patterns' => [
            [
                'type' => 'gettext',
                'base_dir' => __DIR__ . '/../language',
                'pattern' => '%s.mo',
                'text_domain' => null,
            ],
        ],
    ],
];
