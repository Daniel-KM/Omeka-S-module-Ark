<?php declare(strict_types=1);

namespace Ark\Stdlib;

use Noid\Storage\DatabaseInterface;

/**
 * Noid database stored in an array.
 *
 * It is used to read data from any source, then to write them in a storage
 * with its own import, that writes the keys directly, instead of a loop on
 * set(), that checks the existence of each key: on xml, that lookup is a xpath
 * on the whole document, so a loop on a big database takes hours. It allows to
 * mint identifiers in memory too.
 */
class NoidMemoryStorage implements DatabaseInterface
{
    /**
     * @var array
     */
    protected $data;

    public function __construct(array $data = [])
    {
        $this->data = $data;
    }

    public function open($settings, $mode)
    {
        return $this;
    }

    public function close()
    {
    }

    public function isOpen()
    {
        return true;
    }

    public function import($src_db)
    {
        $this->data = $src_db->get_range('');
        return (bool) $this->data;
    }

    public function get($key)
    {
        return $this->data[$key] ?? false;
    }

    public function set($key, $value)
    {
        $this->data[$key] = $value;
        return true;
    }

    public function delete($key)
    {
        unset($this->data[$key]);
        return true;
    }

    public function exists($key)
    {
        return isset($this->data[$key]);
    }

    /**
     * Get the keys starting with a pattern, ordered like in a btree database.
     */
    public function get_range($pattern, $limit = 0)
    {
        $pattern = (string) $pattern;
        if ($pattern === '' && !$limit) {
            return $this->data;
        }

        $result = [];
        foreach ($this->data as $key => $value) {
            if ($pattern === '' || strpos((string) $key, $pattern) === 0) {
                $result[$key] = $value;
            }
        }
        ksort($result, SORT_STRING);

        return $limit > 0 ? array_slice($result, 0, (int) $limit, true) : $result;
    }
}
