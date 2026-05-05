<?php

declare(strict_types=1);

namespace Contenir\Storage\Tests\Integration\Adapter;

use Contenir\Storage\Image\ImageResizer;
use Contenir\Storage\Adapter\LocalFilesystem;
use Contenir\Storage\UploadInput;
use Contenir\Storage\Variant;
use Contenir\Storage\VariantFit;
use Contenir\Storage\VariantRegistry;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Exercises LocalFilesystem against the real disk and a real ImageMagick
 * binary. Skips silently if ImageMagick is unavailable on the host.
 */
#[Group('integration')]
#[Group('storage')]
#[Group('filesystem')]
final class LocalFilesystemTest extends TestCase
{
    private string $rootPath;

    protected function setUp(): void
    {
        parent::setUp();
        if (exec('which magick') === '' && exec('which convert') === '') {
            self::markTestSkipped('ImageMagick (magick/convert) not installed.');
        }
        $this->rootPath = sys_get_temp_dir() . '/local_backend_int_' . uniqid('', true);
        mkdir($this->rootPath, 0o777, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->rootPath);
        parent::tearDown();
    }

    public function testStoreGeneratesThumbVariantOnDisk(): void
    {
        $source  = $this->writePngFile('source.png', 800, 600);
        $backend = $this->backend(new VariantRegistry(
            new Variant('admin-thumb', 180, 180, VariantFit::Contain),
        ));

        $entry = $backend->store(new UploadInput($source, 'photo.png', 'image/png'), 'gallery');

        self::assertSame('photo.png', $entry->name);
        self::assertFileExists($this->rootPath . '/gallery/photo.png');
        self::assertFileExists($this->rootPath . '/gallery/_thumbs/admin-thumb/photo.png');

        $variantInfo = getimagesize($this->rootPath . '/gallery/_thumbs/admin-thumb/photo.png');
        self::assertIsArray($variantInfo);
        self::assertLessThanOrEqual(180, $variantInfo[0]);
        self::assertLessThanOrEqual(180, $variantInfo[1]);
    }

    public function testStoreCoverFitProducesExactDimensions(): void
    {
        $source  = $this->writePngFile('source.png', 800, 400);
        $backend = $this->backend(new VariantRegistry(
            new Variant('square', 200, 200, VariantFit::Cover),
        ));

        $backend->store(new UploadInput($source, 'photo.png', 'image/png'), 'gallery');

        $info = getimagesize($this->rootPath . '/gallery/_thumbs/square/photo.png');
        self::assertIsArray($info);
        self::assertSame(200, $info[0]);
        self::assertSame(200, $info[1]);
    }

    public function testStoreFillFitProducesExactDimensions(): void
    {
        $source  = $this->writePngFile('source.png', 800, 600);
        $backend = $this->backend(new VariantRegistry(
            new Variant('stretched', 100, 50, VariantFit::Fill),
        ));

        $backend->store(new UploadInput($source, 'photo.png', 'image/png'), 'gallery');

        $info = getimagesize($this->rootPath . '/gallery/_thumbs/stretched/photo.png');
        self::assertIsArray($info);
        self::assertSame(100, $info[0]);
        self::assertSame(50, $info[1]);
    }

    public function testDeleteRemovesAllVariantsOnDisk(): void
    {
        $source  = $this->writePngFile('source.png', 200, 200);
        $backend = $this->backend(new VariantRegistry(
            new Variant('admin-thumb', 180, 180, VariantFit::Contain),
            new Variant('hero', 1200, 600, VariantFit::Cover),
        ));

        $backend->store(new UploadInput($source, 'photo.png', 'image/png'), 'gallery');

        self::assertFileExists($this->rootPath . '/gallery/_thumbs/admin-thumb/photo.png');
        self::assertFileExists($this->rootPath . '/gallery/_thumbs/hero/photo.png');

        $backend->delete('gallery/photo.png');

        self::assertFileDoesNotExist($this->rootPath . '/gallery/photo.png');
        self::assertFileDoesNotExist($this->rootPath . '/gallery/_thumbs/admin-thumb/photo.png');
        self::assertFileDoesNotExist($this->rootPath . '/gallery/_thumbs/hero/photo.png');
    }

    public function testRenameMovesAllVariantsOnDisk(): void
    {
        $source  = $this->writePngFile('source.png', 200, 200);
        $backend = $this->backend(new VariantRegistry(
            new Variant('admin-thumb', 180, 180, VariantFit::Contain),
        ));

        $backend->store(new UploadInput($source, 'photo.png', 'image/png'), 'gallery');
        $backend->rename('gallery/photo.png', 'gallery/renamed.png');

        self::assertFileDoesNotExist($this->rootPath . '/gallery/photo.png');
        self::assertFileExists($this->rootPath . '/gallery/renamed.png');
        self::assertFileExists($this->rootPath . '/gallery/_thumbs/admin-thumb/renamed.png');
    }

    private function backend(VariantRegistry $variants): LocalFilesystem
    {
        return new LocalFilesystem(
            $this->rootPath,
            '',
            $variants,
            new ImageResizer(),
        );
    }

    private function writePngFile(string $name, int $width, int $height): string
    {
        $path = $this->rootPath . '/' . $name;
        $img  = imagecreatetruecolor($width, $height);
        imagepng($img, $path);
        imagedestroy($img);
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
