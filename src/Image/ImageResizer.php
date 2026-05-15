<?php

declare(strict_types=1);

namespace Contenir\Storage\Image;

use Contenir\Storage\Exception\WriteException;
use Contenir\Storage\VariantFit;

/**
 * Single-purpose ImageMagick-backed resizer used by storage backends.
 *
 * The legacy PeptoCms\Filter\ImageResize performs a `which magick` shell-out on
 * every filter() call; this implementation discovers the binary once at
 * construction and then only invokes ImageMagick for the actual work.
 *
 * Resize semantics map ImageMagick geometry hints to VariantFit:
 *  - Cover   → "WxH^" then -gravity center -extent WxH (fill, crop overflow)
 *  - Contain → "WxH"  (fit inside, preserve aspect)
 *  - Fill    → "WxH!" (stretch, ignore aspect)
 */
class ImageResizer
{
    protected string $binaryPath;

    /**
     * @param string|null $binaryPath Absolute path to magick/convert. When null,
     *                                discovered from PATH at construction time.
     *
     * @throws WriteException If $binaryPath is null and no ImageMagick
     *                             binary can be found.
     */
    public function __construct(?string $binaryPath = null)
    {
        $this->binaryPath = $binaryPath ?? self::discoverBinary();
    }

    /**
     * Resize $sourcePath to $destPath at $width × $height honouring $fit.
     *
     * The destination directory is created if missing. Any existing file at
     * $destPath is overwritten.
     *
     * @throws WriteException If the source is unreadable, the destination
     *                             directory cannot be created/written, or
     *                             ImageMagick fails.
     */
    public function resize(
        string $sourcePath,
        string $destPath,
        int $width,
        int $height,
        VariantFit $fit = VariantFit::Cover,
        ?int $quality = null,
    ): void {
        if (! is_readable($sourcePath)) {
            throw new WriteException(sprintf('Source image "%s" is not readable.', $sourcePath));
        }

        $destDir = \dirname($destPath);
        if (! is_dir($destDir) && ! @mkdir($destDir, 0o777, true) && ! is_dir($destDir)) {
            throw new WriteException(sprintf('Cannot create destination directory "%s".', $destDir));
        }
        if (! is_writable($destDir)) {
            throw new WriteException(sprintf('Destination directory "%s" is not writable.', $destDir));
        }

        // `-background none` preserves alpha for source PNGs/AVIFs; `$quality`
        // is consumed by the encoder driven by $destPath's extension.
        $qualityValue = $quality ?? 85;
        $command = sprintf(
            '%s %s -background none -colorspace sRGB -strip %s -unsharp 0x0.75 -quality %d %s 2>/dev/null',
            escapeshellcmd($this->binaryPath),
            escapeshellarg($sourcePath),
            $this->geometryArgs($width, $height, $fit),
            $qualityValue,
            escapeshellarg($destPath),
        );

        $exit = 0;
        $output = [];
        exec($command, $output, $exit);

        if ($exit !== 0 || ! file_exists($destPath)) {
            throw new WriteException(sprintf(
                'ImageMagick failed resizing "%s" → "%s" (exit %d).',
                $sourcePath,
                $destPath,
                $exit,
            ));
        }
    }

    private function geometryArgs(int $width, int $height, VariantFit $fit): string
    {
        if ($width <= 0 && $height <= 0) {
            throw new \InvalidArgumentException('At least one of width or height must be > 0.');
        }

        if ($fit !== VariantFit::Contain && ($width <= 0 || $height <= 0)) {
            throw new \InvalidArgumentException(sprintf(
                'VariantFit::%s requires both width and height to be > 0.',
                $fit->name,
            ));
        }

        $geom = match (true) {
            $width > 0 && $height > 0 => sprintf('%dx%d', $width, $height),
            $width > 0                => sprintf('%dx', $width),
            default                   => sprintf('x%d', $height),
        };

        return match ($fit) {
            VariantFit::Cover   => sprintf(
                '-resize %s -gravity center -extent %s',
                escapeshellarg($geom . '^'),
                escapeshellarg($geom),
            ),
            VariantFit::Contain => sprintf('-resize %s', escapeshellarg($geom)),
            VariantFit::Fill    => sprintf('-resize %s', escapeshellarg($geom . '!')),
        };
    }

    /** @throws WriteException */
    private static function discoverBinary(): string
    {
        $found = exec('which magick') ?: exec('which convert');
        if (is_string($found) && $found !== '') {
            return $found;
        }

        $candidates = [
            '/usr/local/bin/magick',
            '/usr/bin/magick',
            '/opt/homebrew/bin/magick',
            '/usr/local/bin/convert',
            '/usr/bin/convert',
        ];
        foreach ($candidates as $candidate) {
            if (is_executable($candidate)) {
                return $candidate;
            }
        }

        throw new WriteException('ImageMagick binary (magick or convert) not found.');
    }
}
