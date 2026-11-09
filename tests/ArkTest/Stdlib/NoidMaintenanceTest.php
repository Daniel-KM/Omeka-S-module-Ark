<?php declare(strict_types=1);

namespace ArkTest\Stdlib;

use Ark\Stdlib\NoidMemoryStorage;
use Ark\Stdlib\NoidStore;
use CommonTest\AbstractHttpControllerTestCase;
use Noid\Lib\Db;
use Noid\Lib\Log;
use Noid\Noid as NoidLib;
use Noid\Storage\DatabaseInterface;

/**
 * Migration, check and rebuild of the noid database.
 */
class NoidMaintenanceTest extends AbstractHttpControllerTestCase
{
    /**
     * @var string
     */
    protected $dir;

    public function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/arktest-maintenance-' . getmypid();
        $this->removeDir($this->dir);
        mkdir($this->dir . '/NOID', 0775, true);
    }

    public function tearDown(): void
    {
        $this->removeDir($this->dir);
        parent::tearDown();
    }

    public function migrationProvider(): array
    {
        return [
            'json' => ['json'],
            'dump file' => ['dump_file'],
        ];
    }

    /**
     * @dataProvider migrationProvider
     */
    public function testMigrateKeepsDatabaseAndMinting(string $method): void
    {
        $arks = $this->createSource([11, 22, 33]);
        $source = $this->readAll('xml');
        if ($method === 'json') {
            file_put_contents($this->dir . '/NOID/noid_data.json', json_encode($source));
        } else {
            file_put_contents($this->dir . '/NOID/noid.dump', $this->toDump($source));
        }
        unlink($this->dir . '/NOID/noid.xml');
        touch($this->dir . '/NOID/noid.bdb');

        $maintenance = $this->maintenance(['ark_store' => '']);
        $this->assertTrue($maintenance->isMigrationNeeded());
        $this->assertTrue($maintenance->migrationMethods()[$method]);

        $store = $maintenance->migrate($method);

        $this->assertContains($store, ['lmdb', 'xml']);
        $this->assertSame($store, $maintenance->getSettings()->values['ark_store']);
        $this->assertEquals($source, $this->readAll($store));
        $this->assertFalse($maintenance->isMigrationNeeded());

        $noid = Db::dbopen(NoidStore::settings($store, $this->dir), DatabaseInterface::DB_WRITE);
        foreach ($arks as $resourceId => $ark) {
            $this->assertSame($ark, Log::get_note($noid, 'locations/' . $resourceId));
        }
        $this->assertNotContains(NoidLib::mint($noid, 'test@example.org'), $arks);
        Db::dbclose($noid);
    }

    public function testMigrateWithPhpReader(): void
    {
        copy(dirname(__DIR__, 2) . '/fixtures/noid-little-endian.bdb', $this->dir . '/NOID/noid.bdb');
        $expected = (new \Ark\Stdlib\BerkeleyDbReader())->read($this->dir . '/NOID/noid.bdb');

        $maintenance = $this->maintenance(['ark_store' => '']);
        $this->assertTrue($maintenance->migrationMethods()['php']);

        $store = $maintenance->migrate('php');

        $this->assertEquals($expected, $this->readAll($store));
        $this->assertSame(60, $maintenance->counters()['minted']);
        $this->assertSame('drand48', $maintenance->getSettings()->values['ark_name_noid_generator']);

        $noid = Db::dbopen(NoidStore::settings($store, $this->dir), DatabaseInterface::DB_WRITE);
        $this->assertSame($expected[':/:/locations/1'], Log::get_note($noid, 'locations/1'));
        $this->assertArrayNotHasKey(NoidLib::mint($noid, 'test@example.org') . "\t:/c", $expected);
        Db::dbclose($noid);
    }

    /**
     * The web server and the command line may have different extensions, so
     * an existing database is used when the store is not set.
     */
    public function testStoreIsTheExistingDatabase(): void
    {
        $maintenance = $this->maintenance(['ark_store' => '']);
        $this->assertSame(NoidStore::resolve(''), $maintenance->getStore(), 'Default store without database.');
        $this->assertNull($maintenance->syncStore());

        $this->createSource([1]);
        $this->assertSame('xml', $maintenance->getStore());
        $this->assertSame('xml', $maintenance->syncStore());
        $this->assertSame('xml', $maintenance->getSettings()->values['ark_store']);
    }

    public function testIdentifiersWithoutResource(): void
    {
        // A working database: the removed resources remain bound.
        $arks = $this->createSource([11, 22, 33, 44]);
        $maintenance = $this->maintenance(['ark_store' => 'xml']);
        $maintenance->omekaArks = [$arks[11] => [11], $arks[33] => [33]];
        $rows = $maintenance->identifiersWithoutResource();
        $this->assertSame([$arks[22], $arks[44]], array_column($rows, 'ark'));
        $this->assertSame(['22', '44'], array_column($rows, 'resources'));
        $this->assertSame(['removed', 'removed'], array_column($rows, 'type'));

        // A rebuilt database: removed resources and margin are not bound.
        $result = $maintenance->rebuild(null, 3);
        $rows = $maintenance->identifiersWithoutResource();
        $this->assertSame(1, $result['minted'] - $result['replayed']);
        $this->assertSame(['removed', 'margin', 'margin', 'margin'], array_column($rows, 'type'));
        $this->assertSame($arks[22], $rows[0]['ark']);
    }

    public function testSyncGeneratorWithTheDatabase(): void
    {
        // A database created before version 3.5.16, with the default of the
        // library, and the default setting of the module.
        $this->mintSource('b.rllllk', 4, 'drand48');
        $maintenance = $this->maintenance(['ark_store' => 'xml', 'ark_name_noid_generator' => 'mt_rand']);
        $this->assertSame('drand48', $maintenance->syncGenerator());
        $this->assertSame('drand48', $maintenance->getSettings()->values['ark_name_noid_generator']);

        // The generator of a sequential template is useless.
        unlink($this->dir . '/NOID/noid.xml');
        $this->mintSource('.zek', 4);
        $maintenance = $this->maintenance(['ark_store' => 'xml', 'ark_name_noid_generator' => 'mt_rand']);
        $this->assertNull($maintenance->syncGenerator());
        $this->assertSame('mt_rand', $maintenance->getSettings()->values['ark_name_noid_generator']);
    }

    /**
     * The reader in pure php reads the same data than the extension dba.
     */
    public function testPhpReaderReadsLikeTheExtensionDba(): void
    {
        if (!\Ark\Stdlib\NoidStore::isAvailable('bdb')) {
            $this->markTestSkipped('The extension dba with the handler db4 is not available.');
        }
        foreach (['noid-little-endian.bdb', 'noid-big-endian.bdb', 'noid-v8-little-endian.bdb'] as $fixture) {
            copy(dirname(__DIR__, 2) . '/fixtures/' . $fixture, $this->dir . '/NOID/noid.bdb');
            $storage = new \Noid\Storage\BerkeleyDB();
            $storage->open(NoidStore::settings('bdb', $this->dir), DatabaseInterface::DB_RDONLY);
            if (!$storage->isOpen()) {
                $this->markTestSkipped('The library cannot read a dba handle with this version of php (fixed in noid after 1.4.1 for php 8.4).');
            }
            $dba = $storage->get_range('');
            $storage->close();
            $this->assertEquals($dba, (new \Ark\Stdlib\BerkeleyDbReader())->read($this->dir . '/NOID/noid.bdb'), $fixture);
        }
    }

    /**
     * The store selected in the config is the target of the migration.
     */
    public function testMigrateToTheSelectedStore(): void
    {
        copy(dirname(__DIR__, 2) . '/fixtures/noid-little-endian.bdb', $this->dir . '/NOID/noid.bdb');
        $maintenance = $this->maintenance(['ark_store' => 'xml']);
        $this->assertTrue($maintenance->isMigrationNeeded(), 'A selected store without database needs the migration.');
        $this->assertSame(['xml'], $maintenance->migrationStores());

        $this->assertSame('xml', $maintenance->migrate('php'));
        $this->assertFileExists($this->dir . '/NOID/noid.xml');
        $this->assertFalse($maintenance->isMigrationNeeded(), 'An existing database is never replaced.');
    }

    public function testMigrateUnreadableDatabaseLeavesNoFile(): void
    {
        file_put_contents($this->dir . '/NOID/noid.bdb', str_repeat('not a database', 100));
        $maintenance = $this->maintenance(['ark_store' => '']);
        $this->assertTrue($maintenance->migrationMethods()['php']);

        try {
            $maintenance->migrate();
            $this->fail('An exception should be thrown.');
        } catch (\RuntimeException $e) {
            $this->assertFileDoesNotExist($this->dir . '/NOID/noid.xml');
            $this->assertFileDoesNotExist($this->dir . '/NOID/noid.lmdb');
        }
    }

    /**
     * The directory of the database is inside "files", served by the server.
     */
    public function testWrittenDatabaseIsProtected(): void
    {
        $this->assertFileDoesNotExist($this->dir . '/.htaccess');
        $this->maintenance(['ark_store' => 'xml'])->importData([':/template' => '.zek'], 'xml');
        $this->assertFileExists($this->dir . '/.htaccess');
        $this->assertStringContainsString('Require all denied', file_get_contents($this->dir . '/.htaccess'));
    }

    public function testCountersDoNotCountTwiceTheHoldsOfLongTerm(): void
    {
        $this->createSource([1, 2, 3, 4, 5]);
        $counters = $this->maintenance(['ark_store' => 'xml'])->counters();
        $this->assertSame(5, $counters['minted']);
        $this->assertSame(5, $counters['known']);
    }

    public function testLock(): void
    {
        $maintenance = $this->maintenance(['ark_store' => 'xml']);
        $maintenance->omekaArks = ['99999/b1' => [1], '99999/b2' => [2]];
        $this->assertTrue($maintenance->updateLock(), 'A missing database with arks in Omeka is locked.');

        $arks = $this->createSource([1, 2]);
        $maintenance->omekaArks = array_fill_keys($arks, [1]);
        $this->assertFalse($maintenance->updateLock());
        $this->assertFalse($maintenance->getSettings()->values['ark_name_noid_locked']);
    }

    /**
     * Arks imported with the same naan but another template are not a risk.
     */
    public function testImportedArksThatCannotBeIssuedDoNotLock(): void
    {
        [$ids] = $this->mintSource('b.rllllk', 4);
        $maintenance = $this->maintenance(['ark_store' => 'xml']);
        $maintenance->omekaArks = array_fill_keys($ids, [1])
            + ['99999/zzzzzz' => [2], '99999/3th4r' => [3], '99999/x1' => [4]];

        $this->assertSame([], $maintenance->unknownArks());
        $this->assertFalse($maintenance->updateLock());
    }

    /**
     * Unknown arks that the minter can issue lock until they are reserved.
     */
    public function testUnknownArksThatCanBeIssuedAreReserved(): void
    {
        // The database was replaced by an older one, that knows some arks.
        [$ids] = $this->mintSource('b.rllllk', 8);
        unlink($this->dir . '/NOID/noid.xml');
        $this->mintSource('b.rllllk', 4);

        $maintenance = $this->maintenance(['ark_store' => 'xml']);
        foreach ($ids as $i => $id) {
            $maintenance->omekaArks[$id] = [100 + $i];
        }
        // The helper mints one more identifier, returned as the next one.
        $this->assertSame(array_slice($ids, 5), $maintenance->unknownArks());
        $this->assertTrue($maintenance->updateLock());

        $result = $maintenance->reserveUnknownArks();

        $this->assertSame(3, $result['reserved']);
        $this->assertCount(1, $result['backups']);
        $this->assertFalse($maintenance->getSettings()->values['ark_name_noid_locked']);

        $noid = Db::dbopen(NoidStore::settings('xml', $this->dir), DatabaseInterface::DB_WRITE);
        $this->assertSame($ids[5], Log::get_note($noid, 'locations/105'));
        $next = [];
        for ($i = 0; $i < 10; $i++) {
            $next[] = NoidLib::mint($noid, 'test@example.org');
        }
        Db::dbclose($noid);
        $this->assertSame([], array_values(array_intersect($next, $ids)));
    }

    public function rebuildProvider(): array
    {
        return [
            'random' => ['b.rllllk'],
            'sequential' => ['.zek'],
        ];
    }

    /**
     * @dataProvider rebuildProvider
     */
    public function testRebuildRestoresMinting(string $template): void
    {
        [$ids, $next] = $this->mintSource($template, 40);
        unlink($this->dir . '/NOID/noid.xml');

        // Some resources were removed.
        $maintenance = $this->maintenance(['ark_store' => 'xml', 'ark_name_noid_template' => $template]);
        foreach ($ids as $i => $id) {
            if ($i % 5 !== 2) {
                $maintenance->omekaArks[$id] = [100 + $i];
            }
        }

        $result = $maintenance->rebuild();

        $this->assertSame(count($maintenance->omekaArks), $result['replayed']);
        $this->assertSame(0, $result['held']);
        $this->assertFalse($maintenance->getSettings()->values['ark_name_noid_locked']);

        $noid = Db::dbopen(NoidStore::settings('xml', $this->dir), DatabaseInterface::DB_WRITE);
        foreach ($maintenance->omekaArks as $id => $resourceIds) {
            $this->assertSame($id, Log::get_note($noid, 'locations/' . $resourceIds[0]));
        }
        $this->assertSame($next, NoidLib::mint($noid, 'test@example.org'), 'The next ark is the one of the original database.');
        Db::dbclose($noid);
    }

    public function testRebuildDetectsTheGeneratorOfTheLostDatabase(): void
    {
        // The setting differs from the database, created by an old version.
        [$ids, $next] = $this->mintSource('b.rllllk', 24, 'drand48');
        unlink($this->dir . '/NOID/noid.xml');

        $maintenance = $this->maintenance(['ark_store' => 'xml', 'ark_name_noid_generator' => 'mt_rand']);
        // The first arks were removed, so the replay should not stop early.
        foreach (array_slice($ids, 16, null, true) as $i => $id) {
            $maintenance->omekaArks[$id] = [100 + $i];
        }

        $result = $maintenance->rebuild();

        $this->assertSame('drand48', $result['generator']);
        $this->assertSame('drand48', $maintenance->getSettings()->values['ark_name_noid_generator']);
        $this->assertSame(24, $result['minted']);

        $noid = Db::dbopen(NoidStore::settings('xml', $this->dir), DatabaseInterface::DB_WRITE);
        $this->assertSame($next, NoidLib::mint($noid, 'test@example.org'));
        Db::dbclose($noid);
    }

    public function testRebuildMarginProtectsTheArksOfTheLastRemovedResources(): void
    {
        [$ids] = $this->mintSource('b.rllllk', 20);
        unlink($this->dir . '/NOID/noid.xml');

        // The five last resources were removed.
        $maintenance = $this->maintenance(['ark_store' => 'xml']);
        foreach (array_slice($ids, 0, 15, true) as $i => $id) {
            $maintenance->omekaArks[$id] = [100 + $i];
        }

        $result = $maintenance->rebuild(null, 10);
        $this->assertSame(10, $result['reserved']);

        $noid = Db::dbopen(NoidStore::settings('xml', $this->dir), DatabaseInterface::DB_WRITE);
        $next = [];
        for ($i = 0; $i < 30; $i++) {
            $next[] = NoidLib::mint($noid, 'test@example.org');
        }
        Db::dbclose($noid);
        $this->assertSame([], array_values(array_intersect($next, $ids)));
    }

    public function testRebuildHoldsTheArksThatCannotBeIssued(): void
    {
        [$ids, $next] = $this->mintSource('b.rllllk', 8);
        unlink($this->dir . '/NOID/noid.xml');

        $maintenance = $this->maintenance(['ark_store' => 'xml']);
        $maintenance->omekaArks = array_fill_keys($ids, [1]) + ['99999/zzzzzz' => [2]];

        $result = $maintenance->rebuild();

        $this->assertSame(8, $result['replayed']);
        $this->assertSame(1, $result['held']);
        $this->assertSame(8, $result['minted'], 'No identifier is wasted to search a foreign ark.');

        $noid = Db::dbopen(NoidStore::settings('xml', $this->dir), DatabaseInterface::DB_WRITE);
        $this->assertSame('99999/zzzzzz', Log::get_note($noid, 'locations/2'));
        $this->assertSame($next, NoidLib::mint($noid, 'test@example.org'));
        Db::dbclose($noid);
    }

    public function testRebuildRestoresTheDatabaseOnFailure(): void
    {
        [$ids] = $this->mintSource('b.rllllk', 4);
        $before = $this->readAll('xml');

        $maintenance = $this->maintenance(['ark_store' => 'xml']);
        $maintenance->omekaArks = array_fill_keys($ids, [1]);

        try {
            $maintenance->rebuild(fn () => true);
            $this->fail('An exception should be thrown.');
        } catch (\RuntimeException $e) {
            $this->assertEquals($before, $this->readAll('xml'));
            $this->assertSame([], glob($this->dir . '/NOID/*.bak'));
        }

        $result = $maintenance->rebuild();
        $this->assertCount(1, $result['backups']);
        $this->assertFileExists($result['backups'][0]);
    }

    public function testMemoryStorageRange(): void
    {
        $storage = new NoidMemoryStorage([':/b' => 2, "x\t:/c" => 3, ':/a' => 1]);
        $storage->set("x\t:/a", 4);
        $storage->set("xy\t:/a", 5);
        $this->assertSame(["x\t:/a" => 4, "x\t:/c" => 3], $storage->get_range("x\t"));
        $this->assertSame([':/a' => 1, ':/b' => 2], $storage->get_range(':/'));
        $storage->delete("x\t:/c");
        $this->assertSame(["x\t:/a" => 4], $storage->get_range("x\t"));
        $this->assertCount(4, $storage->get_range(''));
    }

    protected function maintenance(array $settings): MockNoidMaintenance
    {
        $services = $this->getApplicationServiceLocator();
        return new MockNoidMaintenance(
            $services->get('Omeka\Connection'),
            $services->get('Common\EasyMeta'),
            new MockSettings($settings + [
                'ark_naan' => '99999',
                'ark_naa' => 'example.org',
                'ark_subnaa' => 'sub',
                'ark_name_noid_template' => 'b.rllllk',
                'ark_name_noid_generator' => 'mt_rand',
                'administrator_email' => 'test@example.org',
            ]),
            null,
            $this->dir,
            [],
            null,
            $services->get('Common\DirectoryManager')
        );
    }

    /**
     * Create a xml database with arks minted and bound like the module.
     *
     * @return array Arks by resource id.
     */
    protected function createSource(array $resourceIds): array
    {
        $settings = NoidStore::settings('xml', $this->dir) + ['generator' => 'mt_rand'];
        Db::dbcreate($settings, 'test@example.org', 'b.rllllk', 'long', '99999', 'example.org', 'sub');
        $noid = Db::dbopen($settings, DatabaseInterface::DB_WRITE);
        $arks = [];
        foreach ($resourceIds as $resourceId) {
            $ark = NoidLib::mint($noid, 'test@example.org');
            NoidLib::bind($noid, 'test@example.org', 1, 'set', $ark, 'locations', (string) $resourceId);
            Log::note($noid, 'test@example.org', 'locations/' . $resourceId, $ark);
            $arks[$resourceId] = $ark;
        }
        Db::dbclose($noid);
        return $arks;
    }

    /**
     * Mint identifiers one by one and by batch, and get the next one.
     */
    protected function mintSource(string $template, int $count, string $generator = 'mt_rand'): array
    {
        $settings = NoidStore::settings('xml', $this->dir) + ['generator' => $generator];
        Db::dbcreate($settings, 'test@example.org', $template, 'long', '99999', 'example.org', 'sub');
        $noid = Db::dbopen($settings, DatabaseInterface::DB_WRITE);
        $ids = [];
        while (count($ids) < $count) {
            $ids = array_merge($ids, count($ids) % 4 ? [NoidLib::mint($noid, 'test@example.org')] : NoidLib::mintMultiple($noid, 'test@example.org', 3));
        }
        $next = NoidLib::mint($noid, 'test@example.org');
        Db::dbclose($noid);
        return [$ids, $next];
    }

    protected function readAll(string $store): array
    {
        $storageClass = \Noid\Lib\Globals::DB_TYPES[$store];
        $storage = new $storageClass();
        $storage->open(NoidStore::settings($store, $this->dir), DatabaseInterface::DB_RDONLY);
        $data = $storage->get_range('');
        $storage->close();
        return $data;
    }

    /**
     * Format data like "db_dump -p".
     */
    protected function toDump(array $data): string
    {
        $escape = function ($value): string {
            $output = '';
            foreach (str_split((string) $value) as $char) {
                $ord = ord($char);
                $output .= $char === '\\' ? '\\\\' : ($ord < 32 || $ord > 126 ? sprintf('\\%02x', $ord) : $char);
            }
            return $output;
        };
        $dump = "VERSION=3\nformat=print\ntype=btree\nHEADER=END\n";
        foreach ($data as $key => $value) {
            $dump .= ' ' . $escape($key) . "\n " . $escape($value) . "\n";
        }
        return $dump . "DATA=END\n";
    }

    protected function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($dir);
    }
}
