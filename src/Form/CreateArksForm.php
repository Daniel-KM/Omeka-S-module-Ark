<?php declare(strict_types=1);

namespace Ark\Form;

use Laminas\Form\Element;
use Laminas\Form\Form;

class CreateArksForm extends Form
{
    public function init(): void
    {
        $this->add([
            'name' => 'submit',
            'type' => Element\Submit::class,
            'attributes' => [
                'value' => 'Create ARKs', // @translate
            ],
        ]);
    }
}
