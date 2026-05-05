<?php

declare(strict_types=1);

namespace Contenir\Storage\Tests\TestAsset;

use Contenir\Storage\Image\ImageResizer;
use Contenir\Storage\VariantFit;

/**
 * Drop-in ImageResizer that records calls and writes a placeholder file rather
 * than shelling out to ImageMagick. Lets LocalFilesystem unit tests
 * verify variant-generation orchestration without real image transformation.
 */
final class StubImageResizer extends ImageResizer
{
    /** @var list<array{source: string, dest: string, width: int, height: int, fit: VariantFit}> */
    public array $calls = [];

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
    ): void {
        $this->calls[] = [
            'source' => $sourcePath,
            'dest'   => $destPath,
            'width'  => $width,
            'height' => $height,
            'fit'    => $fit,
        ];

        $dir = \dirname($destPath);
        if (! is_dir($dir)) {
            mkdir($dir, 0o777, true);
        }
        file_put_contents($destPath, sprintf('STUB:%dx%d:%s', $width, $height, $fit->name));
    }
}
