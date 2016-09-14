<?php
namespace Ark\Form;

use Omeka\Form\Element\ResourceSelect;
use Omeka\Form\Element\Ckeditor;
use Zend\Form\Element;
use Zend\Form\Form;
use Zend\I18n\Translator\TranslatorAwareInterface;
use Zend\I18n\Translator\TranslatorAwareTrait;
use Omeka\Form\Element\PropertySelect;

class ConfigForm extends Form implements TranslatorAwareInterface
{
    use TranslatorAwareTrait;

    protected $local_storage = '';
    protected $formElementManager;

    public function setLocalStorage($local_storage)
    {
        $this->local_storage = $local_storage;
    }

    public function setFormElementManager($formElementManager)
    {
        $this->formElementManager = $formElementManager;
    }

    public function setSettings($settings)
    {
        $this->settings = $settings;
    }

    public function init() {
        $this->setAttribute('id', 'config-form');



    }


    protected function getSetting($name) {
        return $this->settings->get($name);
    }

    protected function translate($args) {
        $translator = $this->getTranslator();
        return $translator->translate($args);
    }


}
