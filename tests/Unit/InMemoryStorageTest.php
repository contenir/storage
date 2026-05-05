<?php

declare(strict_types=1);

namespace Contenir\Storage\Tests\Unit;

use InvalidArgumentException;
use Contenir\Storage\Entry;
use Contenir\Storage\Exception\NotFoundException;
use Contenir\Storage\Exception\WriteException;
use Contenir\Storage\ListOptions;
use Contenir\Storage\SortDirection;
use Contenir\Storage\SortField;
use Contenir\Storage\UploadInput;
use Contenir\Storage\Variant;
use Contenir\Storage\VariantRegistry;
use Contenir\Storage\Tests\TestAsset\InMemoryStorage;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
#[Group('storage')]
final class InMemoryStorageTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/asset_storage_test_' . uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            foreach (glob($this->tempDir . '/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($this->tempDir);
        }
        parent::tearDown();
    }

    public function testStoreReturnsEntryForUploadedFile(): void
    {
        $source = $this->writeTempFile('hello.txt', 'hello world');
        $storage = new InMemoryStorage();

        $entry = $storage->store(new UploadInput($source, 'hello.txt', 'text/plain'), 'docs');

        self::assertInstanceOf(Entry::class, $entry);
        self::assertSame('hello.txt', $entry->name);
        self::assertSame('docs/hello.txt', $entry->path);
        self::assertSame('text/plain', $entry->mime);
        self::assertSame(11, $entry->size);
        self::assertFalse($entry->isDir);
    }

    public function testStoreUsesMd5OfFilenameAsId(): void
    {
        $source = $this->writeTempFile('hello.txt', 'hello');
        $storage = new InMemoryStorage();

        $entry = $storage->store(new UploadInput($source, 'hello.txt'), 'docs');

        self::assertSame(md5('hello.txt'), $entry->id);
    }

    public function testStoreThrowsWhenSourceUnreadable(): void
    {
        $storage = new InMemoryStorage();

        $this->expectException(WriteException::class);

        $storage->store(new UploadInput('/no/such/file', 'foo.txt'), 'docs');
    }

    public function testStoredFileIsRetrievableViaUrl(): void
    {
        $source  = $this->writeTempFile('a.txt', 'data');
        $storage = new InMemoryStorage();
        $storage->store(new UploadInput($source, 'a.txt'), 'docs');

        self::assertSame('memory://docs/a.txt', $storage->url('docs/a.txt'));
    }

    public function testUrlReturnsNullForMissingPath(): void
    {
        $storage = new InMemoryStorage();

        self::assertNull($storage->url('nope.txt'));
    }

    public function testUrlReturnsNullForDirectory(): void
    {
        $storage = new InMemoryStorage();
        $storage->makeDirectory('docs');

        self::assertNull($storage->url('docs'));
    }

    public function testUrlIncludesVariantNameWhenRequested(): void
    {
        $registry = new VariantRegistry(new Variant('thumb', 100, 100));
        $storage  = new InMemoryStorage($registry);
        $storage->putFile('docs/a.jpg', 'bytes', 'image/jpeg');

        self::assertSame('memory://docs/a.jpg?v=thumb', $storage->url('docs/a.jpg', 'thumb'));
    }

    public function testUrlThrowsForUnknownVariant(): void
    {
        $storage = new InMemoryStorage();
        $storage->putFile('a.jpg', 'bytes', 'image/jpeg');

        $this->expectException(InvalidArgumentException::class);

        $storage->url('a.jpg', 'no-such-variant');
    }

    public function testListReturnsFilesInDirectory(): void
    {
        $storage = new InMemoryStorage();
        $storage->putFile('docs/a.txt', 'a');
        $storage->putFile('docs/b.txt', 'b');

        $entries = iterator_to_array($this->iter($storage->list('docs')));
        $names   = array_map(static fn (Entry $e): string => $e->name, $entries);

        sort($names);
        self::assertSame(['a.txt', 'b.txt'], $names);
    }

    public function testListExcludesEntriesInSubdirectories(): void
    {
        $storage = new InMemoryStorage();
        $storage->putFile('docs/a.txt', 'a');
        $storage->putFile('docs/sub/b.txt', 'b');

        $entries = iterator_to_array($this->iter($storage->list('docs')));
        $names   = array_map(static fn (Entry $e): string => $e->name, $entries);

        self::assertSame(['a.txt'], $names);
    }

    public function testListExcludesDirectoriesByDefault(): void
    {
        $storage = new InMemoryStorage();
        $storage->putFile('docs/a.txt', 'a');
        $storage->makeDirectory('docs/sub');

        $entries = iterator_to_array($this->iter($storage->list('docs')));
        $names   = array_map(static fn (Entry $e): string => $e->name, $entries);

        self::assertSame(['a.txt'], $names);
    }

    public function testListIncludesDirectoriesWhenRequested(): void
    {
        $storage = new InMemoryStorage();
        $storage->putFile('docs/a.txt', 'a');
        $storage->makeDirectory('docs/sub');

        $entries = iterator_to_array($this->iter(
            $storage->list('docs', new ListOptions(includeDirectories: true))
        ));
        $names = array_map(static fn (Entry $e): string => $e->name, $entries);

        sort($names);
        self::assertSame(['a.txt', 'sub'], $names);
    }

    public function testListFiltersByKeyword(): void
    {
        $storage = new InMemoryStorage();
        $storage->putFile('docs/report.pdf', 'r');
        $storage->putFile('docs/notes.txt', 'n');
        $storage->putFile('docs/report-2026.pdf', 'r2');

        $entries = iterator_to_array($this->iter(
            $storage->list('docs', new ListOptions(keyword: 'report'))
        ));
        $names = array_map(static fn (Entry $e): string => $e->name, $entries);

        sort($names);
        self::assertSame(['report-2026.pdf', 'report.pdf'], $names);
    }

    public function testListSortsByNameAscendingByDefault(): void
    {
        $storage = new InMemoryStorage();
        $storage->putFile('docs/zebra.txt', 'z');
        $storage->putFile('docs/apple.txt', 'a');
        $storage->putFile('docs/mango.txt', 'm');

        $entries = iterator_to_array($this->iter($storage->list('docs')));
        $names   = array_map(static fn (Entry $e): string => $e->name, $entries);

        self::assertSame(['apple.txt', 'mango.txt', 'zebra.txt'], $names);
    }

    public function testListSortsByNameDescending(): void
    {
        $storage = new InMemoryStorage();
        $storage->putFile('docs/apple.txt', 'a');
        $storage->putFile('docs/zebra.txt', 'z');

        $entries = iterator_to_array($this->iter(
            $storage->list('docs', new ListOptions(sortDirection: SortDirection::Desc))
        ));
        $names = array_map(static fn (Entry $e): string => $e->name, $entries);

        self::assertSame(['zebra.txt', 'apple.txt'], $names);
    }

    public function testListSortsBySize(): void
    {
        $storage = new InMemoryStorage();
        $storage->putFile('docs/big.txt', str_repeat('x', 100));
        $storage->putFile('docs/tiny.txt', 'x');
        $storage->putFile('docs/medium.txt', str_repeat('x', 10));

        $entries = iterator_to_array($this->iter(
            $storage->list('docs', new ListOptions(sortField: SortField::Size))
        ));
        $names = array_map(static fn (Entry $e): string => $e->name, $entries);

        self::assertSame(['tiny.txt', 'medium.txt', 'big.txt'], $names);
    }

    public function testListThrowsForMissingDirectory(): void
    {
        $storage = new InMemoryStorage();

        $this->expectException(NotFoundException::class);

        iterator_to_array($this->iter($storage->list('nope')));
    }

    public function testListThrowsWhenPathIsAFile(): void
    {
        $storage = new InMemoryStorage();
        $storage->putFile('docs/a.txt', 'a');

        $this->expectException(NotFoundException::class);

        iterator_to_array($this->iter($storage->list('docs/a.txt')));
    }

    public function testExistsReturnsTrueForStoredFile(): void
    {
        $storage = new InMemoryStorage();
        $storage->putFile('a.txt', 'a');

        self::assertTrue($storage->exists('a.txt'));
    }

    public function testExistsReturnsFalseForMissingFile(): void
    {
        $storage = new InMemoryStorage();

        self::assertFalse($storage->exists('a.txt'));
    }

    public function testDeleteRemovesStoredFile(): void
    {
        $storage = new InMemoryStorage();
        $storage->putFile('a.txt', 'a');

        $storage->delete('a.txt');

        self::assertFalse($storage->exists('a.txt'));
    }

    public function testDeleteThrowsForMissingFile(): void
    {
        $storage = new InMemoryStorage();

        $this->expectException(NotFoundException::class);

        $storage->delete('a.txt');
    }

    public function testRenameMovesFileToNewPath(): void
    {
        $storage = new InMemoryStorage();
        $storage->putFile('docs/old.txt', 'data');

        $storage->rename('docs/old.txt', 'docs/new.txt');

        self::assertFalse($storage->exists('docs/old.txt'));
        self::assertTrue($storage->exists('docs/new.txt'));
    }

    public function testRenameThrowsWhenSourceMissing(): void
    {
        $storage = new InMemoryStorage();

        $this->expectException(NotFoundException::class);

        $storage->rename('does-not-exist', 'somewhere');
    }

    public function testImageMetaReturnsDimensionsForImage(): void
    {
        $storage = new InMemoryStorage();
        $storage->putFile('a.jpg', 'bytes', 'image/jpeg', 320, 240);

        $meta = $storage->imageMeta('a.jpg');

        self::assertSame(320, $meta->width);
        self::assertSame(240, $meta->height);
        self::assertSame('image/jpeg', $meta->mime);
    }

    public function testImageMetaThrowsForMissingFile(): void
    {
        $storage = new InMemoryStorage();

        $this->expectException(NotFoundException::class);

        $storage->imageMeta('nope.jpg');
    }

    public function testImageMetaThrowsForNonImageFile(): void
    {
        $storage = new InMemoryStorage();
        $storage->putFile('a.txt', 'data', 'text/plain');

        $this->expectException(NotFoundException::class);

        $storage->imageMeta('a.txt');
    }

    public function testStoreExtractsImageDimensionsFromUploadedImage(): void
    {
        $source = $this->tempDir . '/test.png';
        $img    = imagecreatetruecolor(50, 30);
        imagepng($img, $source);
        imagedestroy($img);

        $storage = new InMemoryStorage();
        $storage->store(new UploadInput($source, 'test.png', 'image/png'), 'images');

        $meta = $storage->imageMeta('images/test.png');
        self::assertSame(50, $meta->width);
        self::assertSame(30, $meta->height);
    }

    public function testListNormalisesLeadingAndTrailingSlashesInPath(): void
    {
        $storage = new InMemoryStorage();
        $storage->putFile('docs/a.txt', 'a');

        $entries = iterator_to_array($this->iter($storage->list('/docs/')));

        self::assertCount(1, $entries);
    }

    /**
     * @param iterable<Entry> $iterable
     * @return \Generator<int, Entry>
     */
    private function iter(iterable $iterable): \Generator
    {
        foreach ($iterable as $key => $value) {
            yield $key => $value;
        }
    }

    private function writeTempFile(string $name, string $contents): string
    {
        $path = $this->tempDir . '/' . $name;
        file_put_contents($path, $contents);
        return $path;
    }
}
