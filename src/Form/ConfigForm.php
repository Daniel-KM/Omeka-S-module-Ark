<?php

namespace Ark\Form;

use Zend\Form\Form;
use Zend\I18n\Translator\TranslatorAwareInterface;
use Zend\I18n\Translator\TranslatorAwareTrait;
use Ark\ArkManager;

class ConfigForm extends Form implements TranslatorAwareInterface
{
    use TranslatorAwareTrait;

    protected $settings;

    /**
     * @var ArkManager
     */
    protected $arkManager;

    public function setSettings($settings)
    {
        $this->settings = $settings;
    }

    public function getSettings()
    {
        return $this->settings;
    }

    public function setArkManager(ArkManager $arkManager)
    {
        $this->arkManager = $arkManager;
    }

    public function getArkManager()
    {
        return $this->arkManager;
    }

    public function init()
    {
        $arkNamePlugin = $this->arkManager->getArkNamePlugin();
        $databaseCreated = $arkNamePlugin->isDatabaseCreated();

        $this->add([
            'name' => 'ark_naan',
            'type' => 'Text',
            'options' => [
                'label' => 'NAAN',
            ],
            'attributes' => [
                'value' => $this->getSettings()->get('ark_naan'),
                'disabled' => $databaseCreated,
            ],
        ]);

        $this->add([
            'name' => 'ark_naa',
            'type' => 'Text',
            'options' => [
                'label' => 'NAA',
            ],
            'attributes' => [
                'value' => $this->getSettings()->get('ark_naa'),
                'disabled' => $databaseCreated,
            ],
        ]);

        $this->add([
            'name' => 'ark_subnaa',
            'type' => 'Text',
            'options' => [
                'label' => 'Sub NAA',
            ],
            'attributes' => [
                'value' => $this->getSettings()->get('ark_subnaa'),
                'disabled' => $databaseCreated,
            ],
        ]);

        $this->add([
            'name' => 'ark_noid_template',
            'type' => 'Text',
            'options' => [
                'label' => 'Noid Template',
            ],
            'attributes' => [
                'value' => $this->getSettings()->get('ark_noid_template'),
                'disabled' => $databaseCreated,
            ],
        ]);

        $this->add([
            'name' => 'ark_note',
            'type' => 'Textarea',
            'options' => [
                'label' => 'Note',
            ],
            'attributes' => [
                'value' => $this->getSettings()->get('ark_note'),
            ],
        ]);

        $this->add([
            'name' => 'ark_policy_statement',
            'type' => 'Textarea',
            'options' => [
                'label' => 'Policy statement',
            ],
            'attributes' => [
                'value' => $this->getSettings()->get('ark_policy_statement'),
            ],
        ]);

        $this->add([
            'name' => 'ark_policy_main',
            'type' => 'Textarea',
            'options' => [
                'label' => 'Main Policy',
            ],
            'attributes' => [
                'value' => $this->getSettings()->get('ark_policy_main'),
            ],
        ]);
    }

    protected function translate($message)
    {
        $translator = $this->getTranslator();
        return $translator->translate($message);
    }
}
