<?php declare(strict_types=1);

namespace ArkTest\Name\Plugin;

class Noid extends \Ark\Name\Plugin\Noid
{
    protected function getDatabaseDir()
    {
        return dirname(__DIR__, 2) . '/../files/arkandnoid';
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
