<?php declare(strict_types=1);

namespace Ark\Stdlib;

/**
 * Read a BerkeleyDB btree database in pure php, without the extension dba.
 *
 * Only the format of the noid databases is managed: a btree database, version 8
 * (BerkeleyDB 3.x and 4.0) or 9 (BerkeleyDB 4.1 and later), without encryption,
 * checksum, duplicates or sub-databases, in any byte order.
 *
 * Page 0 is the meta page, that contains the page size and the root. Each page
 * starts with a header of 26 bytes, followed by the offsets of its items. The
 * internal pages point to their children, and the leaf pages contain the keys
 * and the values alternately, the long ones being stored in overflow pages.
 * The leaves are chained, so they are read from the first one.
 *
 * @see https://docs.oracle.com/cd/E17276_01/html/programmer_reference/am_conf.html
 */
class BerkeleyDbReader
{
    const MAGIC_BTREE = 0x053162;

    const PAGE_INTERNAL = 3;
    const PAGE_LEAF = 5;
    const PAGE_OVERFLOW = 7;
    const PAGE_META = 9;

    const ITEM_KEYDATA = 1;
    const ITEM_DUPLICATE = 2;
    const ITEM_OVERFLOW = 3;
    const ITEM_DELETED = 0x80;

    const HEADER_SIZE = 26;

    /**
     * @var resource
     */
    protected $handle;

    /**
     * @var int
     */
    protected $pageSize;

    /**
     * Format of the unpacked integers, according to the byte order.
     *
     * @var array
     */
    protected $formats;

    /**
     * Read all the keys and values of a database.
     *
     * @throws \RuntimeException
     */
    public function read(string $file): array
    {
        $this->handle = @fopen($file, 'rb');
        if (!$this->handle) {
            throw new \RuntimeException(sprintf('The file %s cannot be read.', $file));
        }

        try {
            $root = $this->readMeta();
            $data = [];
            $visited = [];
            $pgno = $this->firstLeaf($root);
            while ($pgno) {
                if (isset($visited[$pgno])) {
                    throw new \RuntimeException(sprintf('The page %d is linked twice.', $pgno));
                }
                $visited[$pgno] = true;
                $page = $this->page($pgno);
                $header = $this->header($page);
                if ($header['type'] !== self::PAGE_LEAF) {
                    throw new \RuntimeException(sprintf('The page %d is not a leaf page.', $pgno));
                }
                $items = $this->items($page, $header['entries']);
                for ($i = 0; $i + 1 < count($items); $i += 2) {
                    $key = $this->item($page, $items[$i]);
                    $value = $this->item($page, $items[$i + 1]);
                    if ($key !== null && $value !== null) {
                        $data[$key] = $value;
                    }
                }
                $pgno = $header['next'];
            }
            return $data;
        } finally {
            fclose($this->handle);
        }
    }

    /**
     * Check the meta page and get the root page.
     */
    protected function readMeta(): int
    {
        $meta = fread($this->handle, 512);
        if (strlen((string) $meta) < 92) {
            throw new \RuntimeException('The file is not a BerkeleyDB database.');
        }

        if (unpack('V', $meta, 12)[1] === self::MAGIC_BTREE) {
            $this->formats = ['u16' => 'v', 'u32' => 'V'];
        } elseif (unpack('N', $meta, 12)[1] === self::MAGIC_BTREE) {
            $this->formats = ['u16' => 'n', 'u32' => 'N'];
        } else {
            throw new \RuntimeException('The file is not a BerkeleyDB btree database.');
        }

        $version = $this->u32($meta, 16);
        $this->pageSize = $this->u32($meta, 20);
        $encryption = ord($meta[24]);
        $type = ord($meta[25]);
        $metaFlags = ord($meta[26]);

        if (!in_array($version, [8, 9], true)) {
            throw new \RuntimeException(sprintf('The version %d of the BerkeleyDB btree format is not managed.', $version));
        }
        if ($type !== self::PAGE_META) {
            throw new \RuntimeException('The first page is not a btree meta page.');
        }
        if ($encryption || $metaFlags & 0x01) {
            throw new \RuntimeException('Encrypted databases and databases with checksum are not managed.');
        }
        if ($this->pageSize < 512 || $this->pageSize > 65536 || ($this->pageSize & ($this->pageSize - 1))) {
            throw new \RuntimeException(sprintf('The page size %d is invalid.', $this->pageSize));
        }

        return $this->u32($meta, 88);
    }

    /**
     * Go down from the root to the first leaf.
     */
    protected function firstLeaf(int $pgno): int
    {
        for ($depth = 0; $depth < 64; ++$depth) {
            $page = $this->page($pgno);
            $header = $this->header($page);
            if ($header['type'] === self::PAGE_LEAF) {
                return $pgno;
            }
            if ($header['type'] !== self::PAGE_INTERNAL || !$header['entries']) {
                throw new \RuntimeException(sprintf('The page %d is not a btree page.', $pgno));
            }
            // An internal item: length (2), type (1), unused (1), child (4).
            $offset = $this->items($page, 1)[0];
            $pgno = $this->u32($page, $offset + 4);
        }
        throw new \RuntimeException('The btree is too deep.');
    }

    /**
     * Get the value of a leaf item, or null if it is deleted.
     */
    protected function item(string $page, int $offset): ?string
    {
        $type = ord($page[$offset + 2]);
        if ($type & self::ITEM_DELETED) {
            return null;
        }
        switch ($type) {
            case self::ITEM_KEYDATA:
                return substr($page, $offset + 3, $this->u16($page, $offset));
            case self::ITEM_OVERFLOW:
                // Unused (2), type (1), unused (1), page (4), total length (4).
                return $this->overflow($this->u32($page, $offset + 4), $this->u32($page, $offset + 8));
            case self::ITEM_DUPLICATE:
                throw new \RuntimeException('The databases with duplicate keys are not managed.');
            default:
                throw new \RuntimeException(sprintf('Unknown item type %d.', $type));
        }
    }

    /**
     * Read a value stored in a chain of overflow pages.
     */
    protected function overflow(int $pgno, int $length): string
    {
        $value = '';
        $visited = [];
        while ($pgno && strlen($value) < $length) {
            if (isset($visited[$pgno])) {
                throw new \RuntimeException(sprintf('The overflow page %d is linked twice.', $pgno));
            }
            $visited[$pgno] = true;
            $page = $this->page($pgno);
            $header = $this->header($page);
            if ($header['type'] !== self::PAGE_OVERFLOW) {
                throw new \RuntimeException(sprintf('The page %d is not an overflow page.', $pgno));
            }
            // The offset of the free space is the length of the data.
            $value .= substr($page, self::HEADER_SIZE, $header['free']);
            $pgno = $header['next'];
        }
        if (strlen($value) !== $length) {
            throw new \RuntimeException('An overflow value is truncated.');
        }
        return $value;
    }

    protected function page(int $pgno): string
    {
        if (fseek($this->handle, $pgno * $this->pageSize) !== 0) {
            throw new \RuntimeException(sprintf('The page %d cannot be read.', $pgno));
        }
        $page = fread($this->handle, $this->pageSize);
        if ($page === false || strlen($page) !== $this->pageSize) {
            throw new \RuntimeException(sprintf('The page %d is truncated.', $pgno));
        }
        return $page;
    }

    protected function header(string $page): array
    {
        return [
            'next' => $this->u32($page, 16),
            'entries' => $this->u16($page, 20),
            'free' => $this->u16($page, 22),
            'type' => ord($page[25]),
        ];
    }

    /**
     * Get the offsets of the items of a page.
     */
    protected function items(string $page, int $entries): array
    {
        if (self::HEADER_SIZE + 2 * $entries > $this->pageSize) {
            throw new \RuntimeException('The index of a page is invalid.');
        }
        $items = [];
        for ($i = 0; $i < $entries; ++$i) {
            $offset = $this->u16($page, self::HEADER_SIZE + 2 * $i);
            if ($offset < self::HEADER_SIZE || $offset + 3 > $this->pageSize) {
                throw new \RuntimeException('An item of a page is out of the page.');
            }
            $items[] = $offset;
        }
        return $items;
    }

    protected function u16(string $data, int $offset): int
    {
        return unpack($this->formats['u16'], $data, $offset)[1];
    }

    protected function u32(string $data, int $offset): int
    {
        return unpack($this->formats['u32'], $data, $offset)[1];
    }
}
