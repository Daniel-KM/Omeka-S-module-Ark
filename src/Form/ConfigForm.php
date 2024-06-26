<?php declare(strict_types=1);

namespace Ark\Form;

use Ark\ArkManager;
use Omeka\Form\Element as OmekaElement;
use Laminas\Form\Element;
use Laminas\Form\Form;

class ConfigForm extends Form
{
    /**
     * @var ArkManager
     */
    protected $arkManager;

    public function init(): void
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
                'name' => 'ark_name',
                'type' => Element\Radio::class,
                'options' => [
                    'label' => 'Name processor for resource', // @translate
                    'value_options' => [
                        'internal' => 'Internal resource id', // @translate
                        'noid' => 'Noid', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'ark_name',
                    'required' => false,
                ],
            ])
            ->add([
                'name' => 'ark_name_noid_template',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'Noid Template', // @translate
                ],
                'attributes' => [
                    'id' => 'ark_name_noid_template',
                    'disabled' => $databaseCreated,
                ],
            ])
            ->add([
                'name' => 'ark_qualifier',
                'type' => Element\Radio::class,
                'options' => [
                    'label' => 'Qualifier for media', // @translate
                    'value_options' => [
                        'internal' => 'Internal media id', // @translate
                        'position' => 'Position of the media', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'ark_qualifier',
                    'required' => false,
                ],
            ])
            ->add([
                'name' => 'ark_qualifier_position_format',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'Format of position for qualifier', // @translate
                    'info' => 'A "sprintf" string that will format the media position. It is recommended to use a format with a leading letter to avoid confusion with numeric media id. Furthermore, the position may not be stable: a scanned image may be missing. Finally, if the first media is not marked "1" in the database, use module "Bulk Check" to fix positions.', // @translate
                ],
                'attributes' => [
                    'id' => 'ark_qualifier_position_format',
                    'placeholder' => 'p%d',
                ],
            ])
            ->add([
                'name' => 'ark_qualifier_static',
                'type' => Element\Checkbox::class,
                'options' => [
                    'label' => 'Save the ark with qualifier for media', // @translate
                ],
                'attributes' => [
                    'id' => 'ark_qualifier_static',
                ],
            ])
            ->add([
                'name' => 'ark_property',
                'type' => OmekaElement\PropertySelect::class,
                'options' => [
                    'label' => 'Property where to store the identifier (usually dcterms:identifier)', // @translate
                    'info' => 'If changed, you will need to move all existing identifiers to the new property via module Bulk Edit.', // @translate
                    'term_as_value' => true,
                ],
                'attributes' => [
                    'id' => 'ark_property',
                    'class' => 'chosen-select',
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
                'name' => 'ark_note',
                'type' => Element\Textarea::class,
                'options' => [
                    'label' => 'Note', // @translate
                ],
                'attributes' => [
                    'id' => 'ark_note',
                    'rows' => 6,
                ],
            ]);

        $this->getInputFilter()
            ->add([
                'name' => 'ark_qualifier',
                'required' => false,
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
