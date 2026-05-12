<?php

declare(strict_types=1);

namespace Contenir\Storage\Tests\Integration\Image;

use Contenir\Storage\Exception\WriteException;
use Contenir\Storage\Image\ImageResizer;
use Contenir\Storage\VariantFit;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Exercises ImageResizer against a real ImageMagick binary. Pins down the
 * geometry-string semantics that ImageMagick is sensitive to — in particular,
 * "WxH" with H=0 collapses to a 1x1 image, which is why missing dimensions
 * must be omitted from the geometry string rather than zeroed.
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
        if (exec('which magick') === '' && exec('which convert') === '') {
            self::markTestSkipped('ImageMagick (magick/convert) not installed.');
        }
        $this->rootPath = sys_get_temp_dir() . '/image_resizer_int_' . uniqid('', true);
        mkdir($this->rootPath, 0o777, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->rootPath);
        parent::tearDown();
    }

    public function testContainResizesProportionallyWhenOnlyWidthIsGiven(): void
    {
        $source = $this->writePngFile('source.png', 800, 600);
        $dest   = $this->rootPath . '/out.png';

        (new ImageResizer())->resize($source, $dest, 400, 0, VariantFit::Contain);

        [$width, $height] = $this->dimensions($dest);
        self::assertSame(400, $width);
        self::assertSame(300, $height);
    }

    public function testContainResizesProportionallyWhenOnlyHeightIsGiven(): void
    {
        $source = $this->writePngFile('source.png', 800, 600);
        $dest   = $this->rootPath . '/out.png';

        (new ImageResizer())->resize($source, $dest, 0, 300, VariantFit::Contain);

        [$width, $height] = $this->dimensions($dest);
        self::assertSame(400, $width);
        self::assertSame(300, $height);
    }

    public function testRejectsZeroForBothDimensions(): void
    {
        $source = $this->writePngFile('source.png', 800, 600);
        $dest   = $this->rootPath . '/out.png';

        $this->expectException(\InvalidArgumentException::class);
        (new ImageResizer())->resize($source, $dest, 0, 0, VariantFit::Contain);
    }

    public function testCoverRejectsZeroDimension(): void
    {
        $source = $this->writePngFile('source.png', 800, 600);
        $dest   = $this->rootPath . '/out.png';

        $this->expectException(\InvalidArgumentException::class);
        (new ImageResizer())->resize($source, $dest, 400, 0, VariantFit::Cover);
    }

    public function testFillRejectsZeroDimension(): void
    {
        $source = $this->writePngFile('source.png', 800, 600);
        $dest   = $this->rootPath . '/out.png';

        $this->expectException(\InvalidArgumentException::class);
        (new ImageResizer())->resize($source, $dest, 0, 300, VariantFit::Fill);
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
