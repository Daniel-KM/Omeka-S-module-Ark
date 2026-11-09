<?php declare(strict_types=1);

namespace ArkTest\Stdlib;

/**
 * Noid maintenance with the arks of Omeka defined by the test.
 */
class MockNoidMaintenance extends \Ark\Stdlib\NoidMaintenance
{
    /**
     * Resource ids by ark, without "ark:/".
     *
     * @var array
     */
    public $omekaArks = [];

    public function getSettings(): MockSettings
    {
        return $this->settings;
    }

    public function countArks(): int
    {
        return count($this->omekaArks);
    }

    protected function fetchArks(): array
    {
        return $this->omekaArks;
    }
}
