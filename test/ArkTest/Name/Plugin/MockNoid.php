<?php declare(strict_types=1);

namespace ArkTest\Name\Plugin;

class MockNoid extends \Ark\Name\Plugin\Noid
{
    /**
     * Override the default path to the original ark database.
     */
    protected function getDatabaseDir()
    {
        return dirname(__DIR__, 3) . '/files/arkandnoid';
    }

    public function deleteDatabase(): void
    {
        if ($this->isDatabaseCreated()) {
            $this->rrmdir($this->getDatabaseDir());
        }
    }

    protected function rrmdir($dir)
    {
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            if (is_dir("$dir/$file")) {
                $this->rrmdir("$dir/$file");
            } else {
                unlink("$dir/$file");
            }
        }
        return rmdir($dir);
    }
}
