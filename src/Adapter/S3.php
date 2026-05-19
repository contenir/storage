<?php

declare(strict_types=1);

namespace Contenir\Storage\Adapter;

use DateTimeImmutable;
use InvalidArgumentException;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\StorageAttributes;
use Contenir\Storage\Entry;
use Contenir\Storage\StorageInterface;
use Contenir\Storage\Thumbnail;
use Contenir\Storage\Exception\NotFoundException;
use Contenir\Storage\Exception\WriteException;
use Contenir\Storage\Image\ImageResizer;
use Contenir\Storage\ImageMeta;
use Contenir\Storage\ListOptions;
use Contenir\Storage\SortDirection;
use Contenir\Storage\SortField;
use Contenir\Storage\UploadInput;
use Contenir\Storage\Variant;
use Contenir\Storage\VariantRegistry;

/**
 * S3-compatible storage backend (AWS S3, Cloudflare R2, MinIO, etc).
 *
 * Variants are stored as sibling objects at "<base>__<variant>.<ext>" rather
 * than under a directory, so the variant is fetched as a sibling of the
 * original key with no prefix-listing penalty.
 *
 * imageMeta() reads the full object body to resolve dimensions — callers
 * iterating over many entries should expect a network round-trip per call.
 * Future optimisation: store width/height in custom object metadata at upload
 * time and resolve via HEAD.
 */
final class S3 implements StorageInterface
{
    use Thumbnail;

    private const VARIANT_SEPARATOR = '__';
    private const COLLISION_MAX     = 1000;

    /**
     * Per-request positive cache of keys known to exist in the bucket.
     * Populated as a side effect of list(): every key returned by
     * Flysystem::listContents() (originals and variants alike) is recorded
     * here, letting subsequent url() calls answer locally instead of paying
     * for a HEAD round-trip per asset. Cache lifetime is the PHP request —
     * long enough to cover the common "list a directory then build URLs for
     * every entry's variants in the template" loop, short enough that
     * mutations from other processes don't go unnoticed across requests.
     *
     * Keys are stored as map keys (bool true) for O(1) membership checks.
     *
     * @var array<string, true>
     */
    private array $knownKeys = [];

    public function __construct(
        private readonly FilesystemOperator $fs,
        private readonly string $publicUrlBase,
        private readonly VariantRegistry $variants,
        private readonly ImageResizer $resizer,
    ) {
    }

    public function store(UploadInput $upload, string $directory): Entry
    {
        if (! is_readable($upload->sourcePath)) {
            throw new WriteException(sprintf('Upload source "%s" is not readable.', $upload->sourcePath));
        }

        $directory = $this->normalisePath($directory);
        $sanitised = $this->sanitiseFilename($upload->clientFilename, $upload->clientMime);
        $finalName = $this->resolveCollision($directory, $sanitised);
        $key       = $directory === '' ? $finalName : $directory . '/' . $finalName;
        $mime      = $upload->clientMime ?? 'application/octet-stream';

        $stream = @fopen($upload->sourcePath, 'rb');
        if ($stream === false) {
            throw new WriteException(sprintf('Failed opening upload source "%s".', $upload->sourcePath));
        }
        try {
            $this->fs->writeStream($key, $stream, ['ContentType' => $mime]);
        } catch (FilesystemException $e) {
            throw new WriteException(sprintf('Failed writing "%s": %s', $key, $e->getMessage()), 0, $e);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        if (str_starts_with($mime, 'image/') && @getimagesize($upload->sourcePath) !== false) {
            foreach ($this->variants->all() as $variant) {
                $this->generateVariant($upload->sourcePath, $key, $variant);
            }
        }

        return $this->buildEntry($key);
    }

    public function url(string $path, ?string $variant = null): ?string
    {
        if ($variant !== null && ! $this->variants->has($variant)) {
            throw new InvalidArgumentException(sprintf('Unknown variant "%s".', $variant));
        }

        $path = $this->normalisePath($path);
        if (! $this->keyExists($path)) {
            return null;
        }

        if ($variant === null) {
            return $this->buildPublicUrl($path);
        }

        // For multi-format variants, url() returns the first declared format
        // (callers needing a specific format use variantUrls()).
        $variantObj = $this->variants->get($variant);
        $format     = $variantObj->targetFormats()[0] ?? null;
        $variantKey = $this->variantKey($path, $variant, $format);
        if (! $this->keyExists($variantKey)) {
            return null;
        }

        return $this->buildPublicUrl($variantKey);
    }

    public function urlsForKey(string $path): array
    {
        $path = $this->normalisePath($path);
        $urls = [$this->buildPublicUrl($path)];
        foreach ($this->variants->all() as $variant) {
            foreach ($variant->targetFormats() as $format) {
                $urls[] = $this->buildPublicUrl($this->variantKey($path, $variant->name, $format));
            }
        }
        return $urls;
    }

    /**
     * URLs for every materialised format of a single variant, keyed by format
     * (e.g. ['avif' => '…', 'webp' => '…']). When the variant declares no
     * formats, the only key is 'source' and the value uses the original
     * extension. URLs are computed without existence checks so callers can
     * build `<picture>` markup without paying for HEAD round-trips.
     *
     * @return array<string, string>
     */
    public function variantUrls(string $path, string $variantName): array
    {
        if (! $this->variants->has($variantName)) {
            throw new InvalidArgumentException(sprintf('Unknown variant "%s".', $variantName));
        }
        $variant = $this->variants->get($variantName);
        $path    = $this->normalisePath($path);

        $urls = [];
        foreach ($variant->targetFormats() as $format) {
            $key = $format ?? 'source';
            $urls[$key] = $this->buildPublicUrl($this->variantKey($path, $variantName, $format));
        }
        return $urls;
    }

    public function list(string $path, ?ListOptions $options = null): iterable
    {
        $options = $options ?? new ListOptions();
        $path    = $this->normalisePath($path);

        $entries = [];
        try {
            foreach ($this->fs->listContents($path, false) as $attrs) {
                /**
                 * Record every key the bucket is reporting at this prefix —
                 * originals AND variant siblings — before any filtering. The
                 * template's per-entry url() calls hit this cache for free
                 * instead of paying for a HEAD per asset.
                 */
                if (! $attrs->isDir()) {
                    $this->knownKeys[$attrs->path()] = true;
                }

                $name = basename($attrs->path());
                if (str_starts_with($name, '.')) {
                    continue;
                }
                if ($this->isVariantKey($name)) {
                    continue;
                }

                $entry = $this->buildAttributesEntry($attrs);

                if (! $options->includeDirectories && $entry->isDir) {
                    continue;
                }
                if (
                    $options->keyword !== null && $options->keyword !== ''
                    && stripos($entry->name, $options->keyword) === false
                ) {
                    continue;
                }

                $entries[] = $entry;
            }
        } catch (FilesystemException $e) {
            throw NotFoundException::forPath($path);
        }

        usort($entries, $this->comparator($options->sortField, $options->sortDirection));
        return $entries;
    }

    /**
     * Resolve key existence, preferring the in-memory cache populated by
     * earlier list() calls. Falls back to a fileExists() round-trip when the
     * key hasn't been observed yet — preserves correctness when url() is
     * called for paths the caller didn't list first.
     */
    private function keyExists(string $key): bool
    {
        if (isset($this->knownKeys[$key])) {
            return true;
        }
        if ($this->fs->fileExists($key)) {
            $this->knownKeys[$key] = true;
            return true;
        }
        return false;
    }

    public function exists(string $path): bool
    {
        return $this->fs->fileExists($this->normalisePath($path));
    }

    public function delete(string $path): void
    {
        $path = $this->normalisePath($path);
        if (! $this->fs->fileExists($path)) {
            throw NotFoundException::forPath($path);
        }

        try {
            $this->fs->delete($path);
        } catch (FilesystemException $e) {
            throw new WriteException(sprintf('Failed deleting "%s": %s', $path, $e->getMessage()), 0, $e);
        }
        unset($this->knownKeys[$path]);

        foreach ($this->variants->all() as $variant) {
            foreach ($variant->targetFormats() as $format) {
                $variantKey = $this->variantKey($path, $variant->name, $format);
                try {
                    if ($this->fs->fileExists($variantKey)) {
                        $this->fs->delete($variantKey);
                    }
                } catch (FilesystemException $_e) {
                    // Variant cleanup is best-effort — primary delete already succeeded.
                }
                unset($this->knownKeys[$variantKey]);
            }
        }
    }

    public function rename(string $from, string $to): void
    {
        $from = $this->normalisePath($from);
        $to   = $this->normalisePath($to);

        if (! $this->fs->fileExists($from)) {
            throw NotFoundException::forPath($from);
        }
        if ($this->fs->fileExists($to)) {
            throw new WriteException(sprintf('Destination "%s" already exists.', $to));
        }

        try {
            $this->fs->move($from, $to);
        } catch (FilesystemException $e) {
            throw new WriteException(
                sprintf('Failed renaming "%s" to "%s": %s', $from, $to, $e->getMessage()),
                0,
                $e,
            );
        }
        unset($this->knownKeys[$from]);
        $this->knownKeys[$to] = true;

        foreach ($this->variants->all() as $variant) {
            foreach ($variant->targetFormats() as $format) {
                $varFrom = $this->variantKey($from, $variant->name, $format);
                $varTo   = $this->variantKey($to, $variant->name, $format);
                try {
                    if ($this->fs->fileExists($varFrom)) {
                        $this->fs->move($varFrom, $varTo);
                        unset($this->knownKeys[$varFrom]);
                        $this->knownKeys[$varTo] = true;
                    }
                } catch (FilesystemException $_e) {
                    // Variant rename is best-effort — primary rename already succeeded.
                }
            }
        }
    }

    public function imageMeta(string $path): ImageMeta
    {
        $path = $this->normalisePath($path);
        if (! $this->fs->fileExists($path)) {
            throw NotFoundException::forPath($path);
        }

        try {
            $bytes = $this->fs->read($path);
        } catch (FilesystemException $e) {
            throw NotFoundException::forPath($path);
        }

        $info = @getimagesizefromstring($bytes);
        if ($info === false) {
            throw NotFoundException::forPath($path);
        }

        return new ImageMeta($info[0], $info[1], $info['mime'] ?? 'application/octet-stream');
    }

    public function regenerateMissingVariants(string $path): array
    {
        $path = $this->normalisePath($path);
        if (! $this->fs->fileExists($path)) {
            throw NotFoundException::forPath($path);
        }

        /**
         * Quick pre-pass: figure out which variant-format combinations are
         * actually missing, so we don't bother spilling the source object to
         * disk for an asset that's already fully materialised.
         */
        $missing = [];
        foreach ($this->variants->all() as $variant) {
            foreach ($variant->targetFormats() as $format) {
                $variantKey = $this->variantKey($path, $variant->name, $format);
                if (! $this->keyExists($variantKey)) {
                    $missing[] = ['variant' => $variant, 'format' => $format, 'key' => $variantKey];
                }
            }
        }
        if ($missing === []) {
            return [];
        }

        /**
         * Mirror the source object to a local temp file once, then feed each
         * resize from disk. Reading the body once is significantly cheaper
         * than streaming the original through ImageMagick N times via stdin.
         */
        $sourceExt  = pathinfo($path, PATHINFO_EXTENSION) ?: 'tmp';
        $sourceBase = tempnam(sys_get_temp_dir(), 'cms_s3_source_');
        if ($sourceBase === false) {
            throw new WriteException('Cannot allocate temp file for source download.');
        }
        $sourcePath = $sourceBase . '.' . $sourceExt;
        @rename($sourceBase, $sourcePath);

        try {
            try {
                file_put_contents($sourcePath, $this->fs->read($path));
            } catch (FilesystemException $e) {
                throw new WriteException(
                    sprintf('Failed reading source "%s" for variant regeneration: %s', $path, $e->getMessage()),
                    0,
                    $e,
                );
            }

            $generated = [];
            foreach ($missing as $entry) {
                $this->generateVariantFormat($sourcePath, $path, $entry['variant'], $entry['format']);
                $this->knownKeys[$entry['key']] = true;
                $generated[] = $entry['key'];
            }

            return $generated;
        } finally {
            @unlink($sourcePath);
        }
    }

    private function generateVariant(string $sourcePath, string $originalKey, Variant $variant): void
    {
        foreach ($variant->targetFormats() as $format) {
            $this->generateVariantFormat($sourcePath, $originalKey, $variant, $format);
        }
    }

    private function generateVariantFormat(string $sourcePath, string $originalKey, Variant $variant, ?string $format): void
    {
        $variantKey = $this->variantKey($originalKey, $variant->name, $format);
        // ImageMagick infers the output encoder from the destination's extension,
        // so the tmp file MUST carry the target format's suffix.
        $ext     = $format ?? pathinfo($originalKey, PATHINFO_EXTENSION) ?: 'tmp';
        $tmpBase = tempnam(sys_get_temp_dir(), 'cms_s3_variant_');
        if ($tmpBase === false) {
            throw new WriteException('Cannot allocate temp file for variant generation.');
        }
        $tmpPath = $tmpBase . '.' . $ext;
        @rename($tmpBase, $tmpPath);

        try {
            $this->resizer->resize(
                $sourcePath,
                $tmpPath,
                $variant->width,
                $variant->height,
                $variant->fit,
                $variant->quality,
            );

            $stream = @fopen($tmpPath, 'rb');
            if ($stream === false) {
                throw new WriteException(sprintf('Failed opening variant temp "%s".', $tmpPath));
            }
            try {
                $this->fs->writeStream($variantKey, $stream, [
                    'ContentType' => mime_content_type($tmpPath) ?: 'application/octet-stream',
                ]);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
        } finally {
            @unlink($tmpPath);
        }
    }

    private function variantKey(string $key, string $variantName, ?string $format = null): string
    {
        $dot  = strrpos($key, '.');
        $base = $dot !== false ? substr($key, 0, $dot) : $key;
        $ext  = $format !== null ? '.' . $format : ($dot !== false ? substr($key, $dot) : '');
        return $base . self::VARIANT_SEPARATOR . $variantName . $ext;
    }

    private function isVariantKey(string $name): bool
    {
        $dot  = strrpos($name, '.');
        $base = $dot === false ? $name : substr($name, 0, $dot);
        $sep  = strrpos($base, self::VARIANT_SEPARATOR);
        if ($sep === false) {
            return false;
        }
        $candidate = substr($base, $sep + strlen(self::VARIANT_SEPARATOR));
        return $this->variants->has($candidate);
    }

    private function buildEntry(string $key): Entry
    {
        $name = basename($key);
        try {
            $size = $this->fs->fileSize($key);
        } catch (FilesystemException $_e) {
            $size = 0;
        }
        try {
            $mtime = (new DateTimeImmutable())->setTimestamp($this->fs->lastModified($key));
        } catch (FilesystemException $_e) {
            $mtime = new DateTimeImmutable();
        }
        try {
            $mime = $this->fs->mimeType($key);
        } catch (FilesystemException $_e) {
            $mime = 'application/octet-stream';
        }

        return new Entry(
            id:    md5($name),
            name:  $name,
            path:  $key,
            isDir: false,
            size:  $size,
            mtime: $mtime,
            mime:  $mime,
        );
    }

    private function buildAttributesEntry(StorageAttributes $attrs): Entry
    {
        $name  = basename($attrs->path());
        $isDir = $attrs->isDir();
        $size  = 0;
        $fallbackMime = $isDir ? 'inode/directory' : 'application/octet-stream';
        $mime  = $fallbackMime;
        $mtime = new DateTimeImmutable();

        if ($attrs instanceof FileAttributes) {
            $size = $attrs->fileSize() ?? 0;
            /**
             * S3 ListObjectsV2 doesn't return Content-Type per object, so
             * FileAttributes::mimeType() is typically null after a list call.
             * Fall back to extension-based inference so isImage()/isAudio()
             * tests in templates work without paying for a HEAD per entry.
             */
            $mime = $attrs->mimeType() ?? self::guessMimeFromName($name) ?? $fallbackMime;
            if ($attrs->lastModified() !== null) {
                $mtime = (new DateTimeImmutable())->setTimestamp($attrs->lastModified());
            }
        }

        return new Entry(
            id:    md5($name),
            name:  $name,
            path:  $attrs->path(),
            isDir: $isDir,
            size:  $size,
            mtime: $mtime,
            mime:  $mime,
        );
    }

    private static function guessMimeFromName(string $name): ?string
    {
        $ext = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));
        return match ($ext) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png'         => 'image/png',
            'gif'         => 'image/gif',
            'webp'        => 'image/webp',
            'svg'         => 'image/svg+xml',
            'avif'        => 'image/avif',
            'mp3'         => 'audio/mpeg',
            'm4a'         => 'audio/mp4',
            'ogg', 'oga'  => 'audio/ogg',
            'wav'         => 'audio/wav',
            'mp4'         => 'video/mp4',
            'm4v'         => 'video/mp4',
            'mov'         => 'video/quicktime',
            'webm'        => 'video/webm',
            'pdf'         => 'application/pdf',
            default       => null,
        };
    }

    private function buildPublicUrl(string $key): string
    {
        return rtrim($this->publicUrlBase, '/') . '/' . $key;
    }

    private function normalisePath(string $path): string
    {
        return trim(str_replace('\\', '/', $path), '/');
    }

    private function sanitiseFilename(string $clientFilename, ?string $clientMime = null): string
    {
        $name   = basename($clientFilename);
        $dot    = strrpos($name, '.');
        $rawExt = '';
        if ($dot !== false) {
            $rawExt = substr($name, $dot + 1);
            $name   = substr($name, 0, $dot);
        }
        $name = strtolower((string) preg_replace('/[^\w\-]+/', '-', $name));
        $name = trim((string) preg_replace('/-+/', '-', $name), '-');
        if ($name === '') {
            $name = 'file';
        }
        $ext = self::extensionFor($clientMime, $rawExt);
        return $ext === '' ? $name : sprintf('%s.%s', $name, $ext);
    }

    private static function extensionFor(?string $mime, string $fallback): string
    {
        return match (strtolower((string) $mime)) {
            'image/jpeg', 'image/pjpeg'  => 'jpg',
            'image/png', 'image/x-png'   => 'png',
            'image/gif'                  => 'gif',
            'image/webp'                 => 'webp',
            'image/svg+xml', 'image/svg' => 'svg',
            default => strtolower((string) preg_replace('/[^\w]/', '', $fallback)),
        };
    }

    private function resolveCollision(string $directory, string $filename): string
    {
        $key = $directory === '' ? $filename : $directory . '/' . $filename;
        if (! $this->fs->fileExists($key)) {
            return $filename;
        }
        $dot  = strrpos($filename, '.');
        $base = $dot === false ? $filename : substr($filename, 0, $dot);
        $ext  = $dot === false ? '' : substr($filename, $dot);
        for ($i = 1; $i < self::COLLISION_MAX; $i++) {
            $candidate    = sprintf('%s_%d%s', $base, $i, $ext);
            $candidateKey = $directory === '' ? $candidate : $directory . '/' . $candidate;
            if (! $this->fs->fileExists($candidateKey)) {
                return $candidate;
            }
        }
        throw new WriteException(sprintf('Cannot allocate unique filename in "%s".', $directory));
    }

    /** @return callable(Entry, Entry): int */
    private function comparator(SortField $field, SortDirection $dir): callable
    {
        $sign = $dir === SortDirection::Asc ? 1 : -1;

        return static function (Entry $a, Entry $b) use ($field, $sign): int {
            $cmp = match ($field) {
                SortField::Name => strcmp($a->name, $b->name),
                SortField::Time => $a->mtime <=> $b->mtime,
                SortField::Size => $a->size <=> $b->size,
                SortField::Type => strcmp($a->mime, $b->mime),
            };
            return $cmp * $sign;
        };
    }
}
