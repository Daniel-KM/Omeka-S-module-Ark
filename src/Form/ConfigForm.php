<?php declare(strict_types=1);

namespace Ark\Form;

use Ark\ArkManager;
use Ark\Name\Plugin\Noid;
use Ark\Stdlib\NoidMaintenance;
use Ark\Stdlib\NoidStore;
use Omeka\Form\Element as OmekaElement;
use Laminas\Form\Element;
use Laminas\Form\Form;

class ConfigForm extends Form
{
    /**
     * @var ArkManager
     */
    protected $arkManager;

    /**
     * @var NoidMaintenance
     */
    protected $noidMaintenance;

    public function init(): void
    {
        $arkNamePlugin = $this->arkManager->getArkNamePlugin();
        $isNoid = $arkNamePlugin instanceof Noid;
        // The settings of a BerkeleyDB database to migrate are kept too.
        $databaseCreated = $arkNamePlugin->isDatabaseCreated()
            || ($isNoid && $this->noidMaintenance->isMigrationNeeded());

        if ($isNoid) {
            $this->addNoidMaintenance();
        }

        $storeOptions = [
            '' => 'Default (lmdb if available, else xml)', // @translate
            'lmdb' => 'Lmdb (fast, bdb replacement)', // @translate
            'xml' => 'Xml (portable)', // @translate
            'pdo' => 'Omeka database (simpler)', // @translate
            'sqlite' => 'Sqlite (portable)', // @translate
            'bdb' => 'BerkeleyDb (deprecated, no more fully free)', // @translate
        ];

        // Disable the backends whose php extension or dba handler is missing.
        foreach ($storeOptions as $store => $label) {
            if ($store !== '' && !NoidStore::isAvailable($store)) {
                $storeOptions[$store] = [
                    'value' => $store,
                    'label' => $label,
                    'attributes' => ['disabled' => true],
                ];
            }
        }

        $this
            ->add([
                'name' => 'ark_store',
                'type' => Element\Radio::class,
                'options' => [
                    'label' => 'Storage backend for the noid database', // @translate
                    'info' => 'The backends that require a missing php extension or dba handler are disabled.', // @translate
                    'value_options' => $storeOptions,
                ],
                'attributes' => [
                    'id' => 'ark_store',
                    'disabled' => $databaseCreated,
                ],
            ])
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
                    'info' => $databaseCreated ? null : 'A prefix before the dot is recommended, for example "b" in "b.rllllk". It allows to create parallel series, for example for several libraries or archive services, and, if the noid database is lost, a new prefix ensures that the new arks will never be the same than existing ones.', // @translate
                ],
                'attributes' => [
                    'id' => 'ark_name_noid_template',
                    'disabled' => $databaseCreated,
                ],
            ])
            ->add([
                'name' => 'ark_noid_no_prefix',
                'type' => Element\Checkbox::class,
                'options' => [
                    'label' => 'Create the noid database with a template without prefix', // @translate
                    'info' => 'Required to create the database when the template has no prefix: the template cannot be changed later.', // @translate
                ],
                'attributes' => [
                    'id' => 'ark_noid_no_prefix',
                ],
            ])
            ->add([
                'name' => 'ark_name_noid_generator',
                'type' => Element\Radio::class,
                'options' => [
                    'label' => 'Random generator for the noid database when template is random', // @translate
                    'value_options' => [
                        'mt_rand' => 'Mersenne Twister, better random distribution', // @translate
                        'drand48' => 'Linear Congruential Generator, better compatibility', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'ark_name_noid_generator',
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
                    'info' => 'A `sprintf` string that will format the media position. It is recommended to use a format with a leading letter to avoid confusion with numeric media id. Furthermore, the position may not be stable: a scanned image may be missing. Finally, if the first media is not marked "1" in the database, use module Easy Admin to fix positions.', // @translate
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
            ])
        ;

        // The confirmation is useless once the template is fixed.
        if ($databaseCreated || !$isNoid) {
            $this->remove('ark_noid_no_prefix');
        }

        $this->getInputFilter()
            ->add([
                'name' => 'ark_noid_maintenance',
                'required' => false,
            ])
            ->add([
                'name' => 'ark_noid_rebuild_margin',
                'required' => false,
            ])
            ->add([
                'name' => 'ark_noid_no_prefix',
                'required' => false,
            ])
            ->add([
                'name' => 'ark_store',
                'required' => false,
            ])
            ->add([
                'name' => 'ark_name_noid_generator',
                'required' => false,
            ])
            ->add([
                'name' => 'ark_qualifier',
                'required' => false,
            ]);
    }

    /**
     * Add the actions to repair the noid database, if needed.
     *
     * The action is not a setting: it is processed on save.
     */
    protected function addNoidMaintenance(): self
    {
        $actions = [];

        if ($this->noidMaintenance->isMigrationNeeded()) {
            $labels = [
                'db4' => 'Migrate the BerkeleyDB database with the extension php-dba', // @translate
                'db_dump' => 'Migrate the BerkeleyDB database with the command db_dump', // @translate
                'dump_file' => 'Migrate the BerkeleyDB database from the file noid.dump', // @translate
                'json' => 'Migrate the BerkeleyDB database from the file noid_data.json', // @translate
            ];
            foreach (array_filter($this->noidMaintenance->migrationMethods()) as $method => $available) {
                $actions['migrate_' . $method] = $labels[$method];
            }
        }

        $rebuild = $this->noidMaintenance->isMigrationNeeded() || !$this->noidMaintenance->isConsistent();
        if ($rebuild && !$this->noidMaintenance->isMigrationNeeded() && $this->noidMaintenance->counters() !== null) {
            $actions['reserve'] = 'Reserve the arks stored in Omeka that the noid database does not know, so they will never be issued (the current database is kept as a backup)'; // @translate
        }
        if ($rebuild) {
            $actions['rebuild'] = 'Rebuild the noid database from the arks stored in Omeka, in a background job (the current database is kept as a backup)'; // @translate
        }

        if (!$actions) {
            return $this;
        }

        if ($rebuild) {
            $this
                ->add([
                    'name' => 'ark_noid_rebuild_margin',
                    'type' => Element\Number::class,
                    'options' => [
                        'label' => 'Identifiers to reserve after the last ark when rebuilding', // @translate
                        'info' => 'The arks of the last removed resources cannot be known, so they may be issued again. The identifiers reserved after the last ark stored in Omeka will never be issued, so set a number greater than the number of resources with an ark that may have been removed recently.', // @translate
                    ],
                    'attributes' => [
                        'id' => 'ark_noid_rebuild_margin',
                        'min' => 0,
                        'value' => 0,
                    ],
                ]);
        }

        $this
            ->add([
                'name' => 'ark_noid_maintenance',
                'type' => Element\Radio::class,
                'options' => [
                    'label' => 'Maintenance of the noid database', // @translate
                    'info' => 'The action is processed when the config is saved. See the state of the database above.', // @translate
                    'value_options' => ['' => 'No action'] + $actions, // @translate
                ],
                'attributes' => [
                    'id' => 'ark_noid_maintenance',
                    'value' => '',
                ],
            ]);

        return $this;
    }

    public function setNoidMaintenance(NoidMaintenance $noidMaintenance): self
    {
        $this->noidMaintenance = $noidMaintenance;
        return $this;
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
