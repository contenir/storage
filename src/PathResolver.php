<?php

declare(strict_types=1);

namespace Contenir\Storage;

use Contenir\Storage\Exception\WriteException;
use Contenir\Storage\Image\ImageResizer;

/**
 * Bridge between legacy field-config option-bag uploads and the new ImageResizer.
 *
 * The CMS field XML schema describes lookup-table file pyramids by listing
 * one option array per derived file (e.g. image_lg=1200x800, image_md=600x400,
 * image_sm=200x200), each of which becomes its own row in the asset table.
 * This class consumes those option arrays the same way the deprecated
 * `\PeptoCms\Filter\ImageResize` did, but routes the work through ImageResizer
 * and a single configured root.
 *
 * Output is byte-identical to the legacy filter so existing DB rows and
 * controller assertions keep working — see Filename + the mime→ext mapping.
 *
 * This class is a transitional artefact: when the field-config XML schema is
 * redesigned (post-Mezzio), it goes away.
 */
final class PathResolver
{
    public function __construct(
        private readonly string $rootPath,
        private readonly ImageResizer $resizer,
    ) {
    }

    /**
     * Resize/copy the file described by $options and return the public-relative
     * path to be persisted in the database (always begins with a directory
     * separator, matching legacy behaviour).
     *
     * Recognised option keys (every key is optional):
     *   - path      : sub-directory under the storage root
     *   - prefix    : extra sub-directory below path (often the parent slug)
     *   - suffix    : appended to the basename before the extension
     *   - width     : target width (omit/0 to skip resize and just copy)
     *   - height    : target height
     *   - mimeType  : drives canonical extension selection
     *   - extension : fallback extension when mime is absent/unrecognised
     *
     * @param array<string, mixed> $options
     *
     * @throws WriteException If the destination cannot be written.
     */
    public function resolve(array $options, string $sourcePath, ?string $filename = null): string
    {
        $extension = $this->extensionFor(
            isset($options['mimeType']) ? (string) $options['mimeType'] : null,
            isset($options['extension']) ? (string) $options['extension'] : '',
        );

        $basename = $filename ?? (new \SplFileInfo($sourcePath))->getBasename(
            '.' . (new \SplFileInfo($sourcePath))->getExtension(),
        );
        $suffix    = isset($options['suffix']) ? (string) $options['suffix'] : '';
        $finalName = sprintf('%s%s.%s', $basename, $suffix, $extension);

        $segments = array_values(array_filter([
            isset($options['path']) ? trim((string) $options['path'], '/') : null,
            isset($options['prefix']) ? trim((string) $options['prefix'], '/') : null,
            $finalName,
        ], static fn (?string $segment): bool => $segment !== null && $segment !== ''));

        $relativePath = '/' . implode('/', $segments);
        $absolutePath = rtrim($this->rootPath, '/') . $relativePath;
        $destDir      = \dirname($absolutePath);

        if (! is_dir($destDir) && ! @mkdir($destDir, 0o777, true) && ! is_dir($destDir)) {
            throw new WriteException(sprintf('Cannot create destination directory "%s".', $destDir));
        }
        if (! is_writable($destDir)) {
            throw new WriteException(sprintf('Destination directory "%s" is not writable.', $destDir));
        }

        $width  = isset($options['width']) ? (int) $options['width'] : 0;
        $height = isset($options['height']) ? (int) $options['height'] : 0;
        $mime   = isset($options['mimeType']) ? strtolower((string) $options['mimeType']) : '';

        if (($width > 0 || $height > 0) && $this->isResizableMime($mime)) {
            $this->resizer->resize($sourcePath, $absolutePath, $width, $height, VariantFit::Contain);
        } elseif (! @copy($sourcePath, $absolutePath)) {
            throw new WriteException(sprintf('Failed copying "%s" to "%s".', $sourcePath, $absolutePath));
        }

        return $relativePath;
    }

    /**
     * Mirror the legacy ImageResize::filter() switch — only the formats it knew
     * how to drive through ImageMagick are resized; everything else is copied.
     */
    private function isResizableMime(string $mime): bool
    {
        return in_array($mime, [
            'image/jpeg',
            'image/pjpeg',
            'image/png',
            'image/x-png',
            'image/gif',
        ], true);
    }

    /**
     * Sanitise a basename to a slug-safe form: replace runs of non-word
     * characters with single hyphens and lower-case. Byte-identical to the
     * legacy Zend filter so existing DB rows keep matching.
     */
    public function sanitiseBasename(string $basename): string
    {
        $value = preg_replace('/[^\w]+/', '-', $basename) ?? $basename;
        $value = preg_replace('/-{2,}/', '-', $value) ?? $value;
        return strtolower($value);
    }

    private function extensionFor(?string $mime, string $fallback): string
    {
        return match (strtolower((string) $mime)) {
            'image/jpeg', 'image/pjpeg'  => 'jpg',
            'image/png', 'image/x-png'   => 'png',
            'image/gif'                  => 'gif',
            'image/webp'                 => 'webp',
            'image/svg+xml', 'image/svg' => 'svg',
            default => $fallback,
        };
    }
}
