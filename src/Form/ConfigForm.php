<?php

namespace Ark\Form;

use Ark\ArkManager;
use Zend\Form\Element;
use Zend\Form\Form;

class ConfigForm extends Form
{
    /**
     * @var ArkManager
     */
    protected $arkManager;

    public function init()
    {
        $arkNamePlugin = $this->arkManager->getArkNamePlugin();
        $databaseCreated = $arkNamePlugin->isDatabaseCreated();

        $this
            ->add([
                'name' => 'ark_naan',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'NAAN', // @translate
                ],
                'attributes' => [
                    'id' => 'ark_naan',
                    'disabled' => $databaseCreated,
                ],
            ])
            ->add([
                'name' => 'ark_naa',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'NAA', // @translate
                ],
                'attributes' => [
                    'id' => 'ark_naa',
                    'disabled' => $databaseCreated,
                ],
            ])
            ->add([
                'name' => 'ark_subnaa',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'Sub NAA', // @translate
                ],
                'attributes' => [
                    'id' => 'ark_subnaa',
                    'disabled' => $databaseCreated,
                ],
            ])
            ->add([
                'name' => 'ark_noid_template',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'Noid Template', // @translate
                ],
                'attributes' => [
                    'id' => 'ark_noid_template',
                    'disabled' => $databaseCreated,
                ],
            ])
            ->add([
                'name' => 'ark_note',
                'type' => Element\Textarea::class,
                'options' => [
                    'label' => 'Note', // @translate
                ],
                'attributes' => [
                    'id' => 'ark_note',
                    'rows' => 6,
                ],
            ])
            ->add([
                'name' => 'ark_policy_statement',
                'type' => Element\Textarea::class,
                'options' => [
                    'label' => 'Policy statement', // @translate
                ],
                'attributes' => [
                    'id' => 'ark_policy_statement',
                    'rows' => 8,
                ],
            ])
            ->add([
                'name' => 'ark_policy_main',
                'type' => Element\Textarea::class,
                'options' => [
                    'label' => 'Main policy', // @translate
                ],
                'attributes' => [
                    'id' => 'ark_policy_main',
                    'rows' => 10,
                ],
            ])
            ->add([
                'name' => 'ark_qualifier_static',
                'type' => Element\Checkbox::class,
                'options' => [
                    'label' => 'Save the media qualifier', // @translate
                ],
                'attributes' => [
                    'id' => 'ark_qualifier',
                ],
            ]);
    }

    public function setArkManager(ArkManager $arkManager)
    {
        $this->arkManager = $arkManager;
        return $this;
    }

    public function getArkManager()
    {
        return $this->arkManager;
    }
}
