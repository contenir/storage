<?php

declare(strict_types=1);

namespace Contenir\Storage\Tests\Unit\Adapter;

use InvalidArgumentException;
use League\Flysystem\Filesystem;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;
use Contenir\Storage\Entry;
use Contenir\Storage\Exception\NotFoundException;
use Contenir\Storage\Exception\WriteException;
use Contenir\Storage\ImageMeta;
use Contenir\Storage\ListOptions;
use Contenir\Storage\SortDirection;
use Contenir\Storage\SortField;
use Contenir\Storage\Adapter\S3;
use Contenir\Storage\UploadInput;
use Contenir\Storage\Variant;
use Contenir\Storage\VariantFit;
use Contenir\Storage\VariantRegistry;
use Contenir\Storage\Image\StubImageResizer;
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

    public function testStoreDerivesNameAndExtensionFromDetectedType(): void
    {
        $source  = $this->writePngFile('test.bin', 10, 10);
        $backend = $this->backend();

        // Misleading .JPEG name + image/jpeg header; the detected PNG bytes win
        // and the human part is slugged to hyphens.
        $entry = $backend->store(new UploadInput($source, 'IMG_1234.JPEG', 'image/jpeg'), 'docs');

        self::assertSame('img-1234.png', $entry->name);
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

    public function testVariantUrlsAreDeterministicWithoutExistenceCheck(): void
    {
        // No store() call: the variant (and the original) do NOT exist in the
        // bucket. variantUrls() must still return the derived URL — it trusts
        // the key and never issues a HEAD — whereas url() returns null because
        // it does probe. This is the contract the CMS render path relies on.
        $backend = $this->backend(new VariantRegistry(new Variant('admin-thumb', 180, 180)));

        self::assertNull($backend->url('docs/ghost.png', 'admin-thumb'));
        self::assertSame(
            ['source' => 'https://cdn.test/docs/ghost__admin-thumb.png'],
            $backend->variantUrls('docs/ghost.png', 'admin-thumb'),
        );
    }

    public function testVariantUrlsThrowsForUnknownVariant(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->backend()->variantUrls('docs/a.png', 'no-such-variant');
    }

    public function testStorePopulatesImageDimensionsAsProvenance(): void
    {
        $source  = $this->writePngFile('a.png', 42, 24);
        $backend = $this->backend();

        $entry = $backend->store(new UploadInput($source, 'a.png', 'image/png'), 'docs');

        self::assertInstanceOf(ImageMeta::class, $entry->image);
        self::assertSame(42, $entry->image->width);
        self::assertSame(24, $entry->image->height);
    }

    public function testStoreLeavesImageNullForNonImages(): void
    {
        $source  = $this->writeTempFile('notes.txt', 'just some plain text');
        $backend = $this->backend();

        $entry = $backend->store(new UploadInput($source, 'notes.txt'), 'docs');

        self::assertNull($entry->image);
    }

    public function testUrlThrowsForUnknownVariant(): void
    {
        $source  = $this->writeTempFile('a.txt', 'plain text body');
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
        $source  = $this->writeTempFile('a.txt', 'plain text body');
        $backend = $this->backend();
        $backend->store(new UploadInput($source, 'report.txt'), 'docs');
        $backend->store(new UploadInput($source, 'notes.txt'), 'docs');

        $entries = iterator_to_array($this->iter(
            $backend->list('docs', new ListOptions(keyword: 'report')),
        ));

        self::assertCount(1, $entries);
        self::assertSame('report.txt', $entries[0]->name);
    }

    public function testListSortsByNameDescending(): void
    {
        $source  = $this->writeTempFile('a.txt', 'plain text body');
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
        $big    = $this->writeTempFile('big.txt', str_repeat('a', 100));
        $small  = $this->writeTempFile('small.txt', 'abc');

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
        $source  = $this->writeTempFile('a.txt', 'plain text body');
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
        $source  = $this->writeTempFile('a.txt', 'plain text body');
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

    public function testRegenerateMissingVariantsCreatesAbsentSiblings(): void
    {
        $source  = $this->writePngFile('cat.png', 30, 30);
        $fs      = new Filesystem(new InMemoryFilesystemAdapter());
        $backend = new S3(
            fs:            $fs,
            publicUrlBase: 'https://cdn.test',
            variants:      new VariantRegistry(
                new Variant('admin-thumb', 180, 180, VariantFit::Contain),
            ),
            resizer:       $this->resizer,
        );

        $entry = $backend->store(new UploadInput($source, 'cat.png', 'image/png'), 'gallery');
        $fs->delete('gallery/cat__admin-thumb.png');
        $this->resizer->calls = [];

        $regenerated = $backend->regenerateMissingVariants($entry->path);

        self::assertSame(['gallery/cat__admin-thumb.png'], $regenerated);
        self::assertCount(1, $this->resizer->calls);
        self::assertTrue($fs->fileExists('gallery/cat__admin-thumb.png'));
    }

    public function testRegenerateMissingVariantsIsIdempotentWhenEverythingPresent(): void
    {
        $source  = $this->writePngFile('cat.png', 30, 30);
        $backend = $this->backend(new VariantRegistry(
            new Variant('admin-thumb', 180, 180, VariantFit::Contain),
        ));

        $entry = $backend->store(new UploadInput($source, 'cat.png', 'image/png'), 'gallery');
        $this->resizer->calls = [];

        $regenerated = $backend->regenerateMissingVariants($entry->path);

        self::assertSame([], $regenerated);
        self::assertSame([], $this->resizer->calls, 'No resizer calls expected when nothing is missing.');
    }

    public function testRegenerateMissingVariantsThrowsWhenSourceMissing(): void
    {
        $backend = $this->backend(new VariantRegistry(new Variant('admin-thumb', 180, 180)));

        $this->expectException(NotFoundException::class);
        $backend->regenerateMissingVariants('gallery/does-not-exist.png');
    }

    public function testRegenerateMissingVariantsGeneratesAllDeclaredFormats(): void
    {
        $source  = $this->writePngFile('hero.png', 30, 30);
        $fs      = new Filesystem(new InMemoryFilesystemAdapter());
        $backend = new S3(
            fs:            $fs,
            publicUrlBase: 'https://cdn.test',
            variants:      new VariantRegistry(
                new Variant('hero', 1600, 1200, VariantFit::Cover, ['avif', 'webp'], 80),
            ),
            resizer:       $this->resizer,
        );

        $entry = $backend->store(new UploadInput($source, 'hero.png', 'image/png'), 'covers');
        $fs->delete('covers/hero__hero.avif');
        $fs->delete('covers/hero__hero.webp');
        $this->resizer->calls = [];

        $regenerated = $backend->regenerateMissingVariants($entry->path);

        sort($regenerated);
        self::assertSame(['covers/hero__hero.avif', 'covers/hero__hero.webp'], $regenerated);
        self::assertCount(2, $this->resizer->calls);
        self::assertTrue($fs->fileExists('covers/hero__hero.avif'));
        self::assertTrue($fs->fileExists('covers/hero__hero.webp'));
    }

    public function testRegenerateMissingVariantsStreamsSourceFromBackend(): void
    {
        // Sanity check that the new streaming path materialises the same
        // local temp file content the legacy slurping path produced. A
        // bespoke ImageResizer subclass captures the bytes the resizer
        // was actually fed (we can't query the regenerator's temp file
        // after the fact — its finally block unlinks it on return).
        $source      = $this->writePngFile('cat.png', 30, 30);
        $sourceBytes = (string) file_get_contents($source);
        $fs          = new Filesystem(new InMemoryFilesystemAdapter());

        $captureResizer = new class () extends \Contenir\Storage\Image\ImageResizer {
            public ?string $capturedBytes = null;
            public function __construct()
            {
                $this->binaryPath = '/dev/null';
            }
            public function resize(
                string $sourcePath,
                string $destPath,
                int $width,
                int $height,
                VariantFit $fit = VariantFit::Cover,
                ?int $quality = null,
            ): void {
                $this->capturedBytes = (string) file_get_contents($sourcePath);
                $dir = \dirname($destPath);
                if (! is_dir($dir)) {
                    mkdir($dir, 0o777, true);
                }
                file_put_contents($destPath, 'STUB');
            }
        };

        $backend = new S3(
            fs:            $fs,
            publicUrlBase: 'https://cdn.test',
            variants:      new VariantRegistry(
                new Variant('admin-thumb', 180, 180, VariantFit::Contain),
            ),
            resizer:       $captureResizer,
        );

        $entry = $backend->store(new UploadInput($source, 'cat.png', 'image/png'), 'gallery');
        $fs->delete('gallery/cat__admin-thumb.png');
        $captureResizer->capturedBytes = null;

        $backend->regenerateMissingVariants($entry->path);

        self::assertSame(
            $sourceBytes,
            $captureResizer->capturedBytes,
            'Streaming download must reproduce source bytes exactly.',
        );
    }

    public function testClearKeyCacheForcesReExistenceProbes(): void
    {
        $fs      = new Filesystem(new InMemoryFilesystemAdapter());
        $backend = new S3(
            fs:            $fs,
            publicUrlBase: 'https://cdn.test',
            variants:      new VariantRegistry(),
            resizer:       $this->resizer,
        );

        // Warm the cache by listing the prefix containing a known object.
        $source = $this->writeTempFile('hello.txt', 'data');
        $backend->store(new UploadInput($source, 'hello.txt', 'text/plain'), 'docs');
        iterator_to_array($this->iter($backend->list('docs')));
        self::assertNotNull($backend->url('docs/hello.txt'));

        // Manually nuke the object from the underlying fs (bypassing
        // delete() so the cache isn't invalidated as a side effect).
        $fs->delete('docs/hello.txt');

        // Cache says the key exists even though it doesn't (this is the
        // bug clearKeyCache exists to mitigate in long-running workers).
        self::assertNotNull($backend->url('docs/hello.txt'));

        $backend->clearKeyCache();

        // After clearing, url() falls through to a real fileExists() and
        // correctly reports the gone object.
        self::assertNull($backend->url('docs/hello.txt'));
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
