<?php

namespace Ark\Form;

use Zend\Form\Form;
use Zend\Form\Element\Submit;

class CreateArksForm extends Form
{
    public function init()
    {
        $this->add([
            'name' => 'submit',
            'type' => Submit::class,
            'attributes' => [
                'value' => 'Create ARKs', // @translate
            ],
        ]);
    }
}
