<?php

declare(strict_types=1);

namespace Contenir\Storage\Tests\Unit\Adapter;

use InvalidArgumentException;
use Contenir\Storage\Entry;
use Contenir\Storage\Exception\NotFoundException;
use Contenir\Storage\Exception\WriteException;
use Contenir\Storage\Exception\InvalidPathException;
use Contenir\Storage\ListOptions;
use Contenir\Storage\SortDirection;
use Contenir\Storage\SortField;
use Contenir\Storage\Adapter\LocalFilesystem;
use Contenir\Storage\UploadInput;
use Contenir\Storage\Variant;
use Contenir\Storage\VariantFit;
use Contenir\Storage\VariantRegistry;
use Contenir\Storage\Image\StubImageResizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
#[Group('storage')]
final class LocalFilesystemTest extends TestCase
{
    private string $rootPath;
    private StubImageResizer $resizer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rootPath = sys_get_temp_dir() . '/local_backend_unit_' . uniqid('', true);
        mkdir($this->rootPath, 0o777, true);
        $this->resizer = new StubImageResizer();
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->rootPath);
        parent::tearDown();
    }

    public function testStoreCopiesUploadAndReturnsEntry(): void
    {
        $source  = $this->writeTempFile('hello.txt', 'data');
        $backend = $this->backend();

        $entry = $backend->store(new UploadInput($source, 'hello.txt', 'text/plain'), 'docs');

        self::assertInstanceOf(Entry::class, $entry);
        self::assertSame('hello.txt', $entry->name);
        self::assertSame('docs/hello.txt', $entry->path);
        self::assertFileExists($this->rootPath . '/docs/hello.txt');
        self::assertSame('data', file_get_contents($this->rootPath . '/docs/hello.txt'));
    }

    public function testStoreUsesMd5OfFilenameAsId(): void
    {
        $source  = $this->writeTempFile('hello.txt', 'data');
        $backend = $this->backend();

        $entry = $backend->store(new UploadInput($source, 'hello.txt'), 'docs');

        self::assertSame(md5('hello.txt'), $entry->id);
    }

    public function testStoreSanitisesFilename(): void
    {
        $source  = $this->writeTempFile('hello.txt', 'data');
        $backend = $this->backend();

        $entry = $backend->store(new UploadInput($source, 'My File!.txt'), 'docs');

        self::assertSame('my-file.txt', $entry->name);
    }

    public function testStoreAppliesMimeDrivenExtensionOverride(): void
    {
        $source  = $this->writePngFile('source.png', 10, 10);
        $backend = $this->backend();

        $entry = $backend->store(new UploadInput($source, 'IMG_1234.JPEG', 'image/jpeg'), 'docs');

        self::assertSame('img_1234.jpg', $entry->name);
    }

    public function testStoreFallsBackToRawExtensionForUnknownMime(): void
    {
        $source  = $this->writeTempFile('a.txt', 'data');
        $backend = $this->backend();

        $entry = $backend->store(new UploadInput($source, 'note.MD', 'application/octet-stream'), 'docs');

        self::assertSame('note.md', $entry->name);
    }

    public function testStoreResolvesCollisionWithSuffix(): void
    {
        $source  = $this->writeTempFile('a.txt', 'data');
        $backend = $this->backend();

        $first  = $backend->store(new UploadInput($source, 'note.txt'), 'docs');
        $second = $backend->store(new UploadInput($source, 'note.txt'), 'docs');

        self::assertSame('note.txt', $first->name);
        self::assertSame('note_1.txt', $second->name);
    }

    public function testStoreCreatesTargetDirectory(): void
    {
        $source  = $this->writeTempFile('a.txt', 'd');
        $backend = $this->backend();

        $backend->store(new UploadInput($source, 'a.txt'), 'docs/2026/april');

        self::assertDirectoryExists($this->rootPath . '/docs/2026/april');
    }

    public function testStoreThrowsWhenSourceUnreadable(): void
    {
        $backend = $this->backend();

        $this->expectException(WriteException::class);

        $backend->store(new UploadInput('/no/such/file', 'a.txt'), 'docs');
    }

    public function testStoreGeneratesVariantsForImageUploads(): void
    {
        $source  = $this->writePngFile('cat.png', 50, 30);
        $backend = $this->backend(new VariantRegistry(
            new Variant('admin-thumb', 180, 180, VariantFit::Contain),
            new Variant('hero', 1200, 600, VariantFit::Cover),
        ));

        $backend->store(new UploadInput($source, 'cat.png', 'image/png'), 'gallery');

        self::assertCount(2, $this->resizer->calls);
        self::assertSame('admin-thumb', $this->variantNameForCall(0));
        self::assertSame(180, $this->resizer->calls[0]['width']);
        self::assertSame(VariantFit::Contain, $this->resizer->calls[0]['fit']);
    }

    public function testStoreSkipsVariantGenerationForNonImages(): void
    {
        $source  = $this->writeTempFile('notes.txt', 'data');
        $backend = $this->backend(new VariantRegistry(new Variant('admin-thumb', 180, 180)));

        $backend->store(new UploadInput($source, 'notes.txt', 'text/plain'), 'docs');

        self::assertSame([], $this->resizer->calls);
    }

    public function testUrlReturnsPublicPathPrefixedRelativePath(): void
    {
        file_put_contents($this->rootPath . '/a.txt', 'x');
        $backend = new LocalFilesystem(
            $this->rootPath,
            '/uploads',
            new VariantRegistry(),
            $this->resizer,
        );

        self::assertSame('/uploads/a.txt', $backend->url('a.txt'));
    }

    public function testUrlWithEmptyPublicPathReturnsRootRelativePath(): void
    {
        file_put_contents($this->rootPath . '/a.txt', 'x');
        $backend = $this->backend();

        self::assertSame('/a.txt', $backend->url('a.txt'));
    }

    public function testUrlReturnsNullForMissingFile(): void
    {
        self::assertNull($this->backend()->url('nope.txt'));
    }

    public function testUrlReturnsVariantUrlWhenMaterialised(): void
    {
        $source  = $this->writePngFile('a.png', 10, 10);
        $backend = $this->backend(new VariantRegistry(new Variant('admin-thumb', 180, 180)));

        $backend->store(new UploadInput($source, 'a.png', 'image/png'), 'docs');

        self::assertSame('/docs/_thumbs/admin-thumb/a.png', $backend->url('docs/a.png', 'admin-thumb'));
    }

    public function testUrlReturnsNullWhenVariantMissing(): void
    {
        file_put_contents($this->rootPath . '/a.png', 'x');
        $backend = $this->backend(new VariantRegistry(new Variant('admin-thumb', 180, 180)));

        self::assertNull($backend->url('a.png', 'admin-thumb'));
    }

    public function testUrlThrowsForUnknownVariant(): void
    {
        file_put_contents($this->rootPath . '/a.png', 'x');
        $backend = $this->backend();

        $this->expectException(InvalidArgumentException::class);

        $backend->url('a.png', 'no-such-variant');
    }

    public function testListReturnsFilesInDirectory(): void
    {
        mkdir($this->rootPath . '/docs');
        file_put_contents($this->rootPath . '/docs/a.txt', 'a');
        file_put_contents($this->rootPath . '/docs/b.txt', 'b');

        $entries = iterator_to_array($this->iter($this->backend()->list('docs')));
        $names   = array_map(static fn (Entry $e): string => $e->name, $entries);
        sort($names);

        self::assertSame(['a.txt', 'b.txt'], $names);
    }

    public function testListExcludesThumbsDirectory(): void
    {
        mkdir($this->rootPath . '/docs/_thumbs', 0o777, true);
        file_put_contents($this->rootPath . '/docs/a.txt', 'a');

        $entries = iterator_to_array($this->iter(
            $this->backend()->list('docs', new ListOptions(includeDirectories: true)),
        ));
        $names = array_map(static fn (Entry $e): string => $e->name, $entries);

        self::assertSame(['a.txt'], $names);
    }

    public function testListExcludesHiddenFiles(): void
    {
        mkdir($this->rootPath . '/docs');
        file_put_contents($this->rootPath . '/docs/a.txt', 'a');
        file_put_contents($this->rootPath . '/docs/.DS_Store', '');
        file_put_contents($this->rootPath . '/docs/.hidden', 'h');

        $entries = iterator_to_array($this->iter($this->backend()->list('docs')));
        $names   = array_map(static fn (Entry $e): string => $e->name, $entries);

        self::assertSame(['a.txt'], $names);
    }

    public function testListFiltersByKeyword(): void
    {
        mkdir($this->rootPath . '/docs');
        file_put_contents($this->rootPath . '/docs/report.pdf', 'r');
        file_put_contents($this->rootPath . '/docs/notes.txt', 'n');

        $entries = iterator_to_array($this->iter(
            $this->backend()->list('docs', new ListOptions(keyword: 'report')),
        ));
        $names = array_map(static fn (Entry $e): string => $e->name, $entries);

        self::assertSame(['report.pdf'], $names);
    }

    public function testListSortsByNameDescending(): void
    {
        mkdir($this->rootPath . '/docs');
        file_put_contents($this->rootPath . '/docs/apple.txt', 'a');
        file_put_contents($this->rootPath . '/docs/zebra.txt', 'z');

        $entries = iterator_to_array($this->iter(
            $this->backend()->list('docs', new ListOptions(sortDirection: SortDirection::Desc)),
        ));
        $names = array_map(static fn (Entry $e): string => $e->name, $entries);

        self::assertSame(['zebra.txt', 'apple.txt'], $names);
    }

    public function testListSortsBySize(): void
    {
        mkdir($this->rootPath . '/docs');
        file_put_contents($this->rootPath . '/docs/big.txt', str_repeat('x', 100));
        file_put_contents($this->rootPath . '/docs/small.txt', 'x');

        $entries = iterator_to_array($this->iter(
            $this->backend()->list('docs', new ListOptions(sortField: SortField::Size)),
        ));
        $names = array_map(static fn (Entry $e): string => $e->name, $entries);

        self::assertSame(['small.txt', 'big.txt'], $names);
    }

    public function testListThrowsForMissingDirectory(): void
    {
        $this->expectException(NotFoundException::class);

        iterator_to_array($this->iter($this->backend()->list('nope')));
    }

    public function testListThrowsWhenPathIsAFile(): void
    {
        file_put_contents($this->rootPath . '/a.txt', 'a');

        $this->expectException(NotFoundException::class);

        iterator_to_array($this->iter($this->backend()->list('a.txt')));
    }

    public function testExistsTrueForFile(): void
    {
        file_put_contents($this->rootPath . '/a.txt', 'a');

        self::assertTrue($this->backend()->exists('a.txt'));
    }

    public function testExistsFalseForMissingFile(): void
    {
        self::assertFalse($this->backend()->exists('a.txt'));
    }

    public function testDeleteRemovesFileAndItsVariants(): void
    {
        $source  = $this->writePngFile('a.png', 10, 10);
        $backend = $this->backend(new VariantRegistry(new Variant('admin-thumb', 180, 180)));
        $backend->store(new UploadInput($source, 'a.png', 'image/png'), 'docs');

        self::assertFileExists($this->rootPath . '/docs/_thumbs/admin-thumb/a.png');

        $backend->delete('docs/a.png');

        self::assertFileDoesNotExist($this->rootPath . '/docs/a.png');
        self::assertFileDoesNotExist($this->rootPath . '/docs/_thumbs/admin-thumb/a.png');
    }

    public function testDeleteRemovesLegacyThumbLayout(): void
    {
        mkdir($this->rootPath . '/docs/_thumbs', 0o777, true);
        file_put_contents($this->rootPath . '/docs/a.png', 'x');
        file_put_contents($this->rootPath . '/docs/_thumbs/_a.png', 'thumb');

        $this->backend()->delete('docs/a.png');

        self::assertFileDoesNotExist($this->rootPath . '/docs/_thumbs/_a.png');
    }

    public function testDeleteThrowsForMissingFile(): void
    {
        $this->expectException(NotFoundException::class);

        $this->backend()->delete('nope.txt');
    }

    public function testDeleteThrowsForDirectory(): void
    {
        mkdir($this->rootPath . '/docs');

        $this->expectException(WriteException::class);

        $this->backend()->delete('docs');
    }

    public function testRenameMovesFileAndVariants(): void
    {
        $source  = $this->writePngFile('a.png', 10, 10);
        $backend = $this->backend(new VariantRegistry(new Variant('admin-thumb', 180, 180)));
        $backend->store(new UploadInput($source, 'a.png', 'image/png'), 'docs');

        $backend->rename('docs/a.png', 'docs/renamed.png');

        self::assertFileExists($this->rootPath . '/docs/renamed.png');
        self::assertFileDoesNotExist($this->rootPath . '/docs/a.png');
        self::assertFileExists($this->rootPath . '/docs/_thumbs/admin-thumb/renamed.png');
        self::assertFileDoesNotExist($this->rootPath . '/docs/_thumbs/admin-thumb/a.png');
    }

    public function testRenameThrowsWhenSourceMissing(): void
    {
        $this->expectException(NotFoundException::class);

        $this->backend()->rename('does-not-exist', 'somewhere');
    }

    public function testRenameThrowsWhenDestinationExists(): void
    {
        file_put_contents($this->rootPath . '/a.txt', 'a');
        file_put_contents($this->rootPath . '/b.txt', 'b');

        $this->expectException(WriteException::class);

        $this->backend()->rename('a.txt', 'b.txt');
    }

    public function testImageMetaThrowsForMissingFile(): void
    {
        $this->expectException(NotFoundException::class);

        $this->backend()->imageMeta('nope.png');
    }

    public function testImageMetaThrowsForNonImage(): void
    {
        file_put_contents($this->rootPath . '/a.txt', 'data');

        $this->expectException(NotFoundException::class);

        $this->backend()->imageMeta('a.txt');
    }

    public function testImageMetaReturnsDimensions(): void
    {
        $this->writePngFileAt('a.png', 50, 30);

        $meta = $this->backend()->imageMeta('a.png');

        self::assertSame(50, $meta->width);
        self::assertSame(30, $meta->height);
        self::assertSame('image/png', $meta->mime);
    }

    #[DataProvider('unsafePathProvider')]
    public function testStoreRejectsUnsafePath(string $unsafe): void
    {
        $source = $this->writeTempFile('a.txt', 'd');

        $this->expectException(InvalidPathException::class);

        $this->backend()->store(new UploadInput($source, 'a.txt'), $unsafe);
    }

    /** @return array<string, array{string}> */
    public static function unsafePathProvider(): array
    {
        return [
            'parent traversal'         => ['../escape'],
            'mid-path traversal'       => ['docs/../../etc'],
            'url-encoded traversal'    => ['docs/%2e%2e/etc'],
            'null byte'                => ["docs\0/etc"],
        ];
    }

    private function backend(?VariantRegistry $variants = null): LocalFilesystem
    {
        return new LocalFilesystem(
            $this->rootPath,
            '',
            $variants ?? new VariantRegistry(),
            $this->resizer,
        );
    }

    private function variantNameForCall(int $index): string
    {
        $destPath = $this->resizer->calls[$index]['dest'];
        $parts    = explode('/', $destPath);
        $idx      = array_search('_thumbs', $parts, true);
        return is_int($idx) ? ($parts[$idx + 1] ?? '') : '';
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
        $path = $this->rootPath . '/' . $name;
        file_put_contents($path, $contents);
        return $path;
    }

    private function writePngFile(string $name, int $width, int $height): string
    {
        $path = $this->rootPath . '/' . $name;
        $img  = imagecreatetruecolor($width, $height);
        imagepng($img, $path);
        imagedestroy($img);
        return $path;
    }

    private function writePngFileAt(string $relative, int $width, int $height): void
    {
        $path = $this->rootPath . '/' . $relative;
        $dir  = \dirname($path);
        if (! is_dir($dir)) {
            mkdir($dir, 0o777, true);
        }
        $img = imagecreatetruecolor($width, $height);
        imagepng($img, $path);
        imagedestroy($img);
    }

    private function removeTree(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->removeTree($path);
            } else {
                @unlink($path);
            }
        }
        rmdir($dir);
    }
}
