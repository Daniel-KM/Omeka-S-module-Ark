<?php declare(strict_types=1);

namespace ArkTest\Stdlib;

use Ark\Stdlib\BerkeleyDbReader;
use PHPUnit\Framework\TestCase;

/**
 * The fixtures are a test noid database of 60 arks, converted with db_load:
 * pages of 512 bytes (three levels and overflow pages) in little-endian, and
 * pages of 4096 bytes in big-endian. The btree version is 9 with BerkeleyDB 5.3
 * and 8 with BerkeleyDB 4.0.14.
 */
class BerkeleyDbReaderTest extends TestCase
{
    public function fixtureProvider(): array
    {
        return [
            'little-endian' => ['noid-little-endian.bdb'],
            'big-endian' => ['noid-big-endian.bdb'],
            'version 8, little-endian' => ['noid-v8-little-endian.bdb'],
            'version 8, big-endian' => ['noid-v8-big-endian.bdb'],
        ];
    }

    /**
     * @dataProvider fixtureProvider
     */
    public function testReadNoidDatabase(string $fixture): void
    {
        $data = (new BerkeleyDbReader())->read(dirname(__DIR__, 2) . '/fixtures/' . $fixture);

        $this->assertCount(857, $data);
        $this->assertSame('b.rllllk', $data[':/template']);
        $this->assertSame('60', $data[':/oacounter']);
        $this->assertSame('drand48', $data[':/generator_random']);
        $this->assertSame(12000, strlen($data[':/:/long']), 'A value stored in overflow pages is read.');
        $ark = $data[':/:/locations/1'];
        $this->assertSame('1', $data[$ark . "\tlocations"]);

        // The keys are in the order of the btree.
        $keys = array_map('strval', array_keys($data));
        $sorted = $keys;
        sort($sorted, SORT_STRING);
        $this->assertSame($sorted, $keys);
    }

    public function testBothByteOrdersGiveTheSameData(): void
    {
        $reader = new BerkeleyDbReader();
        $fixtures = dirname(__DIR__, 2) . '/fixtures/';
        $this->assertSame(
            $reader->read($fixtures . 'noid-little-endian.bdb'),
            $reader->read($fixtures . 'noid-big-endian.bdb')
        );
    }

    public function testBothVersionsGiveTheSameData(): void
    {
        $reader = new BerkeleyDbReader();
        $fixtures = dirname(__DIR__, 2) . '/fixtures/';
        $this->assertSame(
            $reader->read($fixtures . 'noid-little-endian.bdb'),
            $reader->read($fixtures . 'noid-v8-little-endian.bdb')
        );
    }

    public function testInvalidFileThrows(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'arktest');
        file_put_contents($file, str_repeat('not a database', 100));
        $this->expectException(\RuntimeException::class);
        try {
            (new BerkeleyDbReader())->read($file);
        } finally {
            unlink($file);
        }
    }
}
