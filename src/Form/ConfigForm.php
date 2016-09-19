<?php

namespace Ark\Form;

use Zend\Form\Form;
use Zend\I18n\Translator\TranslatorAwareInterface;
use Zend\I18n\Translator\TranslatorAwareTrait;

class ConfigForm extends Form implements TranslatorAwareInterface
{
    use TranslatorAwareTrait;

    protected $settings;

    public function setSettings($settings)
    {
        $this->settings = $settings;
    }

    public function getSettings()
    {
        return $this->settings;
    }

    public function init()
    {
        $this->add([
            'name' => 'ark_naan',
            'type' => 'Text',
            'options' => [
                'label' => 'NAAN',
            ],
            'attributes' => [
                'value' => $this->getSettings()->get('ark_naan'),
            ],
        ]);
    }

    protected function translate($message)
    {
        $translator = $this->getTranslator();
        return $translator->translate($message);
    }
}
