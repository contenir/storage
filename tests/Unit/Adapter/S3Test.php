<?php

declare(strict_types=1);

namespace Contenir\Storage\Tests\Unit\Adapter;

use InvalidArgumentException;
use League\Flysystem\Filesystem;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;
use Contenir\Storage\Entry;
use Contenir\Storage\Exception\NotFoundException;
use Contenir\Storage\Exception\WriteException;
use Contenir\Storage\ListOptions;
use Contenir\Storage\SortDirection;
use Contenir\Storage\SortField;
use Contenir\Storage\Adapter\S3;
use Contenir\Storage\UploadInput;
use Contenir\Storage\Variant;
use Contenir\Storage\VariantFit;
use Contenir\Storage\VariantRegistry;
use Contenir\Storage\Tests\TestAsset\StubImageResizer;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
#[Group('storage')]
final class S3Test extends TestCase
{
    private string $tempDir;
    private StubImageResizer $resizer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/s3_test_' . uniqid('', true);
        mkdir($this->tempDir, 0o777, true);
        $this->resizer = new StubImageResizer();
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            foreach (glob($this->tempDir . '/*') ?: [] as $file) {
                @unlink($file);
            }
            rmdir($this->tempDir);
        }
        parent::tearDown();
    }

    public function testStoreUploadsAndReturnsEntry(): void
    {
        $source  = $this->writeTempFile('hello.txt', 'data');
        $backend = $this->backend();

        $entry = $backend->store(new UploadInput($source, 'hello.txt', 'text/plain'), 'docs');

        self::assertInstanceOf(Entry::class, $entry);
        self::assertSame('hello.txt', $entry->name);
        self::assertSame('docs/hello.txt', $entry->path);
        self::assertSame(md5('hello.txt'), $entry->id);
    }

    public function testStoreSanitisesFilenameAndAppliesMimeOverride(): void
    {
        $source  = $this->writeTempFile('test.bin', 'data');
        $backend = $this->backend();

        $entry = $backend->store(new UploadInput($source, 'IMG_1234.JPEG', 'image/jpeg'), 'docs');

        self::assertSame('img_1234.jpg', $entry->name);
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

    public function testStoreThrowsWhenSourceUnreadable(): void
    {
        $backend = $this->backend();

        $this->expectException(WriteException::class);

        $backend->store(new UploadInput('/no/such/file', 'a.txt'), 'docs');
    }

    public function testStoreGeneratesSiblingVariantsForImages(): void
    {
        $source  = $this->writePngFile('cat.png', 30, 30);
        $backend = $this->backend(new VariantRegistry(
            new Variant('admin-thumb', 180, 180, VariantFit::Contain),
        ));

        $entry = $backend->store(new UploadInput($source, 'cat.png', 'image/png'), 'gallery');

        self::assertCount(1, $this->resizer->calls);
        self::assertSame('gallery/cat.png', $entry->path);
        // Variant lives at sibling key
        self::assertNotNull($backend->url($entry->path, 'admin-thumb'));
    }

    public function testStoreSkipsVariantsForNonImages(): void
    {
        $source  = $this->writeTempFile('notes.txt', 'data');
        $backend = $this->backend(new VariantRegistry(new Variant('admin-thumb', 180, 180)));

        $backend->store(new UploadInput($source, 'notes.txt', 'text/plain'), 'docs');

        self::assertSame([], $this->resizer->calls);
    }

    public function testUrlReturnsPublicUrlForExistingFile(): void
    {
        $source  = $this->writeTempFile('a.txt', 'data');
        $backend = $this->backend(publicUrlBase: 'https://cdn.example.com');
        $backend->store(new UploadInput($source, 'a.txt'), 'docs');

        self::assertSame('https://cdn.example.com/docs/a.txt', $backend->url('docs/a.txt'));
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

        self::assertStringContainsString('docs/a__admin-thumb.png', $backend->url('docs/a.png', 'admin-thumb'));
    }

    public function testUrlReturnsNullWhenVariantNotMaterialised(): void
    {
        $source  = $this->writeTempFile('notes.txt', 'data');
        $backend = $this->backend(new VariantRegistry(new Variant('admin-thumb', 180, 180)));
        $backend->store(new UploadInput($source, 'notes.txt', 'text/plain'), 'docs');

        self::assertNull($backend->url('docs/notes.txt', 'admin-thumb'));
    }

    public function testUrlThrowsForUnknownVariant(): void
    {
        $source  = $this->writeTempFile('a.txt', 'd');
        $backend = $this->backend();
        $backend->store(new UploadInput($source, 'a.txt'), 'docs');

        $this->expectException(InvalidArgumentException::class);

        $backend->url('docs/a.txt', 'no-such-variant');
    }

    public function testListReturnsFilesAndExcludesVariantSiblings(): void
    {
        $source  = $this->writePngFile('a.png', 10, 10);
        $backend = $this->backend(new VariantRegistry(new Variant('admin-thumb', 180, 180)));
        $backend->store(new UploadInput($source, 'a.png', 'image/png'), 'docs');
        $backend->store(new UploadInput($source, 'b.png', 'image/png'), 'docs');

        $entries = iterator_to_array($this->iter($backend->list('docs')));
        $names   = array_map(static fn (Entry $e): string => $e->name, $entries);
        sort($names);

        self::assertSame(['a.png', 'b.png'], $names);
    }

    public function testUrlsForKeyReturnsOriginalPlusEveryDeclaredVariant(): void
    {
        $backend = $this->backend(new VariantRegistry(
            new Variant('admin-thumb', 180, 180, VariantFit::Contain),
            new Variant('w480', 480, 480, VariantFit::Contain),
            new Variant('w960', 960, 960, VariantFit::Contain),
        ));

        $urls = $backend->urlsForKey('docs/logo.png');

        self::assertSame([
            'https://cdn.test/docs/logo.png',
            'https://cdn.test/docs/logo__admin-thumb.png',
            'https://cdn.test/docs/logo__w480.png',
            'https://cdn.test/docs/logo__w960.png',
        ], $urls);
    }

    public function testUrlsForKeyDoesNotCheckExistence(): void
    {
        // Key isn't stored, but urlsForKey should still return URLs — it's
        // for cache invalidation where we want every URL that COULD have
        // been cached, not just the ones currently materialised.
        $backend = $this->backend(new VariantRegistry(
            new Variant('thumb', 180, 180, VariantFit::Contain),
        ));

        $urls = $backend->urlsForKey('not/yet/stored.png');

        self::assertSame([
            'https://cdn.test/not/yet/stored.png',
            'https://cdn.test/not/yet/stored__thumb.png',
        ], $urls);
    }

    public function testListInfersMimeFromExtensionWhenAdapterReportsNone(): void
    {
        $source  = $this->writePngFile('logo.png', 10, 10);
        $backend = $this->backend();
        $backend->store(new UploadInput($source, 'logo.png', 'image/png'), 'docs');

        // InMemoryFilesystemAdapter returns null mimeType in listings — same
        // shape as S3's ListObjectsV2 — so the entry's mime must come from
        // the extension fallback for isImage() to be correct.
        $entries = iterator_to_array($this->iter($backend->list('docs')));

        self::assertCount(1, $entries);
        self::assertSame('image/png', $entries[0]->mime);
        self::assertTrue($entries[0]->isImage());
    }

    public function testListFiltersByKeyword(): void
    {
        $source  = $this->writeTempFile('a.txt', 'd');
        $backend = $this->backend();
        $backend->store(new UploadInput($source, 'report.pdf'), 'docs');
        $backend->store(new UploadInput($source, 'notes.txt'), 'docs');

        $entries = iterator_to_array($this->iter(
            $backend->list('docs', new ListOptions(keyword: 'report')),
        ));

        self::assertCount(1, $entries);
        self::assertSame('report.pdf', $entries[0]->name);
    }

    public function testListSortsByNameDescending(): void
    {
        $source  = $this->writeTempFile('a.txt', 'd');
        $backend = $this->backend();
        $backend->store(new UploadInput($source, 'apple.txt'), 'docs');
        $backend->store(new UploadInput($source, 'zebra.txt'), 'docs');

        $entries = iterator_to_array($this->iter(
            $backend->list('docs', new ListOptions(sortDirection: SortDirection::Desc)),
        ));
        $names = array_map(static fn (Entry $e): string => $e->name, $entries);

        self::assertSame(['zebra.txt', 'apple.txt'], $names);
    }

    public function testListSortsBySize(): void
    {
        $source = $this->writeTempFile('a.txt', 'd');
        $big    = $this->writeTempFile('big.txt', str_repeat('x', 100));
        $small  = $this->writeTempFile('small.txt', 'x');

        $backend = $this->backend();
        $backend->store(new UploadInput($big, 'big.txt'), 'docs');
        $backend->store(new UploadInput($small, 'small.txt'), 'docs');

        $entries = iterator_to_array($this->iter(
            $backend->list('docs', new ListOptions(sortField: SortField::Size)),
        ));
        $names = array_map(static fn (Entry $e): string => $e->name, $entries);

        self::assertSame(['small.txt', 'big.txt'], $names);
    }

    public function testExistsTrueForStoredFile(): void
    {
        $source  = $this->writeTempFile('a.txt', 'd');
        $backend = $this->backend();
        $backend->store(new UploadInput($source, 'a.txt'), 'docs');

        self::assertTrue($backend->exists('docs/a.txt'));
    }

    public function testExistsFalseForMissingFile(): void
    {
        self::assertFalse($this->backend()->exists('nope.txt'));
    }

    public function testDeleteRemovesFileAndVariants(): void
    {
        $source  = $this->writePngFile('a.png', 10, 10);
        $backend = $this->backend(new VariantRegistry(new Variant('admin-thumb', 180, 180)));
        $backend->store(new UploadInput($source, 'a.png', 'image/png'), 'docs');

        self::assertTrue($backend->exists('docs/a.png'));
        self::assertTrue($backend->exists('docs/a__admin-thumb.png'));

        $backend->delete('docs/a.png');

        self::assertFalse($backend->exists('docs/a.png'));
        self::assertFalse($backend->exists('docs/a__admin-thumb.png'));
    }

    public function testDeleteThrowsForMissingFile(): void
    {
        $this->expectException(NotFoundException::class);

        $this->backend()->delete('nope.txt');
    }

    public function testRenameMovesFileAndVariants(): void
    {
        $source  = $this->writePngFile('a.png', 10, 10);
        $backend = $this->backend(new VariantRegistry(new Variant('admin-thumb', 180, 180)));
        $backend->store(new UploadInput($source, 'a.png', 'image/png'), 'docs');

        $backend->rename('docs/a.png', 'docs/renamed.png');

        self::assertFalse($backend->exists('docs/a.png'));
        self::assertTrue($backend->exists('docs/renamed.png'));
        self::assertFalse($backend->exists('docs/a__admin-thumb.png'));
        self::assertTrue($backend->exists('docs/renamed__admin-thumb.png'));
    }

    public function testRenameThrowsWhenSourceMissing(): void
    {
        $this->expectException(NotFoundException::class);

        $this->backend()->rename('nope.txt', 'somewhere.txt');
    }

    public function testRenameThrowsWhenDestinationExists(): void
    {
        $source  = $this->writeTempFile('a.txt', 'd');
        $backend = $this->backend();
        $backend->store(new UploadInput($source, 'a.txt'), 'docs');
        $backend->store(new UploadInput($source, 'b.txt'), 'docs');

        $this->expectException(WriteException::class);

        $backend->rename('docs/a.txt', 'docs/b.txt');
    }

    public function testImageMetaReturnsDimensionsForRealImage(): void
    {
        $source  = $this->writePngFile('a.png', 50, 30);
        $backend = $this->backend();
        $backend->store(new UploadInput($source, 'a.png', 'image/png'), 'docs');

        $meta = $backend->imageMeta('docs/a.png');

        self::assertSame(50, $meta->width);
        self::assertSame(30, $meta->height);
        self::assertSame('image/png', $meta->mime);
    }

    public function testImageMetaThrowsForMissingFile(): void
    {
        $this->expectException(NotFoundException::class);

        $this->backend()->imageMeta('nope.png');
    }

    public function testImageMetaThrowsForNonImage(): void
    {
        $source  = $this->writeTempFile('notes.txt', 'data');
        $backend = $this->backend();
        $backend->store(new UploadInput($source, 'notes.txt', 'text/plain'), 'docs');

        $this->expectException(NotFoundException::class);

        $backend->imageMeta('docs/notes.txt');
    }

    private function backend(?VariantRegistry $variants = null, string $publicUrlBase = 'https://cdn.test'): S3
    {
        $fs = new Filesystem(new InMemoryFilesystemAdapter());
        return new S3(
            fs:            $fs,
            publicUrlBase: $publicUrlBase,
            variants:      $variants ?? new VariantRegistry(),
            resizer:       $this->resizer,
        );
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

    private function writePngFile(string $name, int $width, int $height): string
    {
        $path = $this->tempDir . '/' . $name;
        $img  = imagecreatetruecolor($width, $height);
        imagepng($img, $path);
        imagedestroy($img);
        return $path;
    }
}
