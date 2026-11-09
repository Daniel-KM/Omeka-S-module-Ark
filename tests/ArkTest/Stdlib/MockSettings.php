<?php declare(strict_types=1);

namespace ArkTest\Stdlib;

/**
 * Settings stored in memory, so the tests do not change the Omeka database.
 */
class MockSettings extends \Omeka\Settings\Settings
{
    /**
     * @var array
     */
    public $values;

    public function __construct(array $values = [])
    {
        $this->values = $values;
    }

    public function get($id, $default = null)
    {
        return array_key_exists($id, $this->values) ? $this->values[$id] : $default;
    }

    public function set($id, $value)
    {
        $this->values[$id] = $value;
    }
}
