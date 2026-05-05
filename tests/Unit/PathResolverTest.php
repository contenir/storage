<?php

declare(strict_types=1);

namespace Contenir\Storage\Tests\Unit;

use Contenir\Storage\Exception\WriteException;
use Contenir\Storage\PathResolver;
use Contenir\Storage\Tests\TestAsset\StubImageResizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * PathResolver pins down a stable filename + path shape so consumers can
 * upgrade without breaking persisted DB rows: paths begin with a slash, mime
 * → ext mapping is canonical, suffixes are appended before the extension.
 */
#[Group('unit')]
#[Group('storage')]
final class PathResolverTest extends TestCase
{
    private string $rootPath;
    private StubImageResizer $resizer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rootPath = sys_get_temp_dir() . '/path_resolver_test_' . uniqid('', true);
        mkdir($this->rootPath, 0o777, true);
        $this->resizer = new StubImageResizer();
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->rootPath);
        parent::tearDown();
    }

    public function testResolveCopiesUnsizedFileAndReturnsLeadingSlashPath(): void
    {
        $source = $this->writeTempFile('source.jpg', 'data');
        $path   = $this->resolver()->resolve(
            ['path' => '/uploads/products', 'extension' => 'jpg'],
            $source,
            'hero',
        );

        self::assertSame('/uploads/products/hero.jpg', $path);
        self::assertFileExists($this->rootPath . $path);
        self::assertSame('data', file_get_contents($this->rootPath . $path));
        self::assertSame([], $this->resizer->calls);
    }

    public function testResolveAppliesSuffixBeforeExtension(): void
    {
        $source = $this->writeTempFile('source.jpg', 'data');

        $path = $this->resolver()->resolve(
            ['path' => '/uploads', 'suffix' => '_lg', 'extension' => 'jpg'],
            $source,
            'hero',
        );

        self::assertSame('/uploads/hero_lg.jpg', $path);
    }

    public function testResolveJoinsPathPrefixAndFilename(): void
    {
        $source = $this->writeTempFile('source.jpg', 'data');

        $path = $this->resolver()->resolve(
            ['path' => '/uploads', 'prefix' => 'products', 'extension' => 'jpg'],
            $source,
            'hero',
        );

        self::assertSame('/uploads/products/hero.jpg', $path);
    }

    public function testResolveSkipsEmptyPathSegments(): void
    {
        $source = $this->writeTempFile('source.jpg', 'data');

        $path = $this->resolver()->resolve(
            ['path' => '', 'prefix' => 'gallery', 'extension' => 'jpg'],
            $source,
            'hero',
        );

        self::assertSame('/gallery/hero.jpg', $path);
    }

    public function testResolveDelegatesToImageResizerForImageMimeWithDimensions(): void
    {
        $source = $this->writeTempFile('source.jpg', 'data');

        $this->resolver()->resolve(
            [
                'path'     => '/uploads',
                'width'    => 200,
                'height'   => 200,
                'mimeType' => 'image/jpeg',
            ],
            $source,
            'hero',
        );

        self::assertCount(1, $this->resizer->calls);
        self::assertSame(200, $this->resizer->calls[0]['width']);
        self::assertSame(200, $this->resizer->calls[0]['height']);
    }

    public function testResolveCopiesWhenWidthAndHeightAreZero(): void
    {
        $source = $this->writeTempFile('source.jpg', 'data');

        $path = $this->resolver()->resolve(
            ['path' => '/uploads', 'width' => 0, 'height' => 0, 'mimeType' => 'image/jpeg'],
            $source,
            'hero',
        );

        self::assertSame([], $this->resizer->calls);
        self::assertFileExists($this->rootPath . $path);
    }

    public function testResolveCopiesNonImageMimeRegardlessOfDimensions(): void
    {
        $source = $this->writeTempFile('source.txt', 'data');

        $path = $this->resolver()->resolve(
            ['path' => '/uploads', 'width' => 200, 'height' => 200, 'mimeType' => 'text/plain', 'extension' => 'txt'],
            $source,
            'note',
        );

        self::assertSame([], $this->resizer->calls);
        self::assertSame('/uploads/note.txt', $path);
        self::assertFileExists($this->rootPath . $path);
    }

    #[DataProvider('mimeExtensionProvider')]
    public function testResolveOverridesExtensionFromMime(
        string $mime,
        string $fallback,
        string $expected,
    ): void {
        $source = $this->writeTempFile('source.bin', 'data');

        $path = $this->resolver()->resolve(
            ['path' => '/uploads', 'mimeType' => $mime, 'extension' => $fallback],
            $source,
            'hero',
        );

        self::assertSame(sprintf('/uploads/hero.%s', $expected), $path);
    }

    /** @return array<string, array{0: string, 1: string, 2: string}> */
    public static function mimeExtensionProvider(): array
    {
        return [
            'jpeg'              => ['image/jpeg', 'jpeg', 'jpg'],
            'jpeg progressive'  => ['image/pjpeg', 'jpg', 'jpg'],
            'png'               => ['image/png', 'PNG', 'png'],
            'png x-prefixed'    => ['image/x-png', 'png', 'png'],
            'gif'               => ['image/gif', 'gif', 'gif'],
            'webp'              => ['image/webp', 'webp', 'webp'],
            'svg explicit xml'  => ['image/svg+xml', 'svg', 'svg'],
            'unknown mime'      => ['application/octet-stream', 'pdf', 'pdf'],
        ];
    }

    public function testResolveDerivesFilenameFromSourceWhenNoneProvided(): void
    {
        $source = $this->writeTempFile('IMG_1234.original.jpg', 'data');

        $path = $this->resolver()->resolve(
            ['path' => '/uploads', 'extension' => 'jpg'],
            $source,
        );

        self::assertSame('/uploads/IMG_1234.original.jpg', $path);
    }

    public function testResolveCreatesNestedDestinationDirectories(): void
    {
        $source = $this->writeTempFile('source.jpg', 'data');

        $path = $this->resolver()->resolve(
            ['path' => '/uploads/2026/04', 'extension' => 'jpg'],
            $source,
            'hero',
        );

        self::assertDirectoryExists($this->rootPath . '/uploads/2026/04');
        self::assertFileExists($this->rootPath . $path);
    }

    public function testResolveThrowsWhenSourceUnreadable(): void
    {
        $this->expectException(WriteException::class);

        $this->resolver()->resolve(
            ['path' => '/uploads', 'extension' => 'jpg'],
            '/nonexistent/source.jpg',
            'hero',
        );
    }

    public function testSanitiseBasenameMatchesLegacyFilenameFilter(): void
    {
        $resolver = $this->resolver();

        self::assertSame('my-file-', $resolver->sanitiseBasename('My File!'));
        self::assertSame('img_1234', $resolver->sanitiseBasename('IMG_1234'));
        self::assertSame('hello-world', $resolver->sanitiseBasename('Hello   World'));
    }

    private function resolver(): PathResolver
    {
        return new PathResolver($this->rootPath, $this->resizer);
    }

    private function writeTempFile(string $name, string $contents): string
    {
        $path = $this->rootPath . '/' . $name;
        file_put_contents($path, $contents);
        return $path;
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
