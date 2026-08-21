<?php

declare(strict_types=1);

namespace Contenir\Storage\Tests\Integration\Image;

use Contenir\Storage\Exception\WriteException;
use Contenir\Storage\Image\ImageResizer;
use Contenir\Storage\VariantFit;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Exercises ImageResizer against both a real ImageMagick binary and the
 * native `imagick` PHP extension, since production hosts only have the
 * extension compiled in (no CLI binary) while some local dev setups only
 * have the CLI. Pins down the geometry semantics both backends are
 * sensitive to — in particular, resizing with only one dimension given must
 * still preserve the source aspect ratio.
 */
#[Group('integration')]
#[Group('storage')]
#[Group('image')]
final class ImageResizerTest extends TestCase
{
    private string $rootPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rootPath = sys_get_temp_dir() . '/image_resizer_int_' . uniqid('', true);
        mkdir($this->rootPath, 0o777, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->rootPath);
        parent::tearDown();
    }

    /**
     * @return array<string, array{0: bool}>
     */
    public static function backendProvider(): array
    {
        return [
            'imagick extension' => [true],
            'CLI binary'        => [false],
        ];
    }

    #[DataProvider('backendProvider')]
    public function testContainResizesProportionallyWhenOnlyWidthIsGiven(bool $useExtension): void
    {
        $this->skipUnlessBackendAvailable($useExtension);
        $source = $this->writePngFile('source.png', 800, 600);
        $dest   = $this->rootPath . '/out.png';

        $this->makeResizer($useExtension)->resize($source, $dest, 400, 0, VariantFit::Contain);

        [$width, $height] = $this->dimensions($dest);
        self::assertSame(400, $width);
        self::assertSame(300, $height);
    }

    #[DataProvider('backendProvider')]
    public function testContainResizesProportionallyWhenOnlyHeightIsGiven(bool $useExtension): void
    {
        $this->skipUnlessBackendAvailable($useExtension);
        $source = $this->writePngFile('source.png', 800, 600);
        $dest   = $this->rootPath . '/out.png';

        $this->makeResizer($useExtension)->resize($source, $dest, 0, 300, VariantFit::Contain);

        [$width, $height] = $this->dimensions($dest);
        self::assertSame(400, $width);
        self::assertSame(300, $height);
    }

    #[DataProvider('backendProvider')]
    public function testCoverCropsToExactDimensions(bool $useExtension): void
    {
        $this->skipUnlessBackendAvailable($useExtension);
        $source = $this->writePngFile('source.png', 800, 600);
        $dest   = $this->rootPath . '/out.png';

        $this->makeResizer($useExtension)->resize($source, $dest, 400, 400, VariantFit::Cover);

        [$width, $height] = $this->dimensions($dest);
        self::assertSame(400, $width);
        self::assertSame(400, $height);
    }

    #[DataProvider('backendProvider')]
    public function testFillStretchesToExactDimensions(bool $useExtension): void
    {
        $this->skipUnlessBackendAvailable($useExtension);
        $source = $this->writePngFile('source.png', 800, 600);
        $dest   = $this->rootPath . '/out.png';

        $this->makeResizer($useExtension)->resize($source, $dest, 200, 500, VariantFit::Fill);

        [$width, $height] = $this->dimensions($dest);
        self::assertSame(200, $width);
        self::assertSame(500, $height);
    }

    #[DataProvider('backendProvider')]
    public function testRejectsZeroForBothDimensions(bool $useExtension): void
    {
        $this->skipUnlessBackendAvailable($useExtension);
        $source = $this->writePngFile('source.png', 800, 600);
        $dest   = $this->rootPath . '/out.png';

        $this->expectException(\InvalidArgumentException::class);
        $this->makeResizer($useExtension)->resize($source, $dest, 0, 0, VariantFit::Contain);
    }

    #[DataProvider('backendProvider')]
    public function testCoverRejectsZeroDimension(bool $useExtension): void
    {
        $this->skipUnlessBackendAvailable($useExtension);
        $source = $this->writePngFile('source.png', 800, 600);
        $dest   = $this->rootPath . '/out.png';

        $this->expectException(\InvalidArgumentException::class);
        $this->makeResizer($useExtension)->resize($source, $dest, 400, 0, VariantFit::Cover);
    }

    #[DataProvider('backendProvider')]
    public function testFillRejectsZeroDimension(bool $useExtension): void
    {
        $this->skipUnlessBackendAvailable($useExtension);
        $source = $this->writePngFile('source.png', 800, 600);
        $dest   = $this->rootPath . '/out.png';

        $this->expectException(\InvalidArgumentException::class);
        $this->makeResizer($useExtension)->resize($source, $dest, 0, 300, VariantFit::Fill);
    }

    public function testConstructorNeverThrowsRegardlessOfExtensionOrBinaryAvailability(): void
    {
        $this->expectNotToPerformAssertions();
        new ImageResizer();
        new ImageResizer(useExtension: true);
        new ImageResizer(useExtension: false);
    }

    public function testResizeThrowsWhenForcedToCliAndBinaryCannotRun(): void
    {
        $source = $this->writePngFile('source.png', 800, 600);
        $dest   = $this->rootPath . '/out.png';

        $this->expectException(WriteException::class);
        (new ImageResizer(binaryPath: '/nonexistent/magick', useExtension: false))
            ->resize($source, $dest, 400, 400, VariantFit::Cover);
    }

    private function makeResizer(bool $useExtension): ImageResizer
    {
        return new ImageResizer(useExtension: $useExtension);
    }

    private function skipUnlessBackendAvailable(bool $useExtension): void
    {
        if ($useExtension && ! extension_loaded('imagick')) {
            self::markTestSkipped('imagick extension not loaded.');
        }
        if (! $useExtension && exec('which magick') === '' && exec('which convert') === '') {
            self::markTestSkipped('ImageMagick (magick/convert) not installed.');
        }
    }

    private function writePngFile(string $name, int $width, int $height): string
    {
        $path = $this->rootPath . '/' . $name;
        $img  = imagecreatetruecolor($width, $height);
        imagepng($img, $path);
        imagedestroy($img);
        return $path;
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function dimensions(string $path): array
    {
        $info = getimagesize($path);
        if ($info === false) {
            self::fail(sprintf('Could not read image dimensions for "%s".', $path));
        }
        return [$info[0], $info[1]];
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
