<?php

namespace ArkTest\Name\Plugin;

class Noid extends \Ark\Name\Plugin\Noid
{
    protected function getDatabaseDir()
    {
        return dirname(dirname(__DIR__)) . '/../files/arkandnoid';
    }

    public function deleteDatabase()
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
