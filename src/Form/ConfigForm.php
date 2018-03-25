<?php

namespace Ark\Form;

use Ark\ArkManager;
use Zend\Form\Element;
use Zend\Form\Form;
use Zend\I18n\Translator\TranslatorAwareInterface;
use Zend\I18n\Translator\TranslatorAwareTrait;

class ConfigForm extends Form implements TranslatorAwareInterface
{
    use TranslatorAwareTrait;

    /**
     * @var ArkManager
     */
    protected $arkManager;

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
            'type' => Element\Text::class,
            'options' => [
                'label' => 'NAAN', // @translate
            ],
            'attributes' => [
                'disabled' => $databaseCreated,
            ],
        ]);

        $this->add([
            'name' => 'ark_naa',
            'type' => Element\Text::class,
            'options' => [
                'label' => 'NAA', // @translate
            ],
            'attributes' => [
                'disabled' => $databaseCreated,
            ],
        ]);

        $this->add([
            'name' => 'ark_subnaa',
            'type' => Element\Text::class,
            'options' => [
                'label' => 'Sub NAA', // @translate
            ],
            'attributes' => [
                'disabled' => $databaseCreated,
            ],
        ]);

        $this->add([
            'name' => 'ark_noid_template',
            'type' => Element\Text::class,
            'options' => [
                'label' => 'Noid Template', // @translate
            ],
            'attributes' => [
                'disabled' => $databaseCreated,
            ],
        ]);

        $this->add([
            'name' => 'ark_note',
            'type' => Element\Textarea::class,
            'options' => [
                'label' => 'Note', // @translate
            ],
        ]);

        $this->add([
            'name' => 'ark_policy_statement',
            'type' => Element\Textarea::class,
            'options' => [
                'label' => 'Policy statement', // @translate
            ],
        ]);

        $this->add([
            'name' => 'ark_policy_main',
            'type' => Element\Textarea::class,
            'options' => [
                'label' => 'Main policy', // @translate
            ],
        ]);
    }

    protected function translate($message)
    {
        $translator = $this->getTranslator();

        return $translator->translate($message);
    }
}
