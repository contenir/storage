<?php

declare(strict_types=1);

namespace Contenir\Storage\Adapter;

use DateTimeImmutable;
use DirectoryIterator;
use InvalidArgumentException;
use Contenir\Storage\Entry;
use Contenir\Storage\StorageInterface;
use Contenir\Storage\Thumbnail;
use Contenir\Storage\Exception\NotFoundException;
use Contenir\Storage\Exception\WriteException;
use Contenir\Storage\Exception\InvalidPathException;
use Contenir\Storage\DefaultUploadResolver;
use Contenir\Storage\Image\ImageResizer;
use Contenir\Storage\ImageMeta;
use Contenir\Storage\ListOptions;
use Contenir\Storage\SortDirection;
use Contenir\Storage\SortField;
use Contenir\Storage\UploadInput;
use Contenir\Storage\UploadResolverInterface;
use Contenir\Storage\Variant;
use Contenir\Storage\Config\PathVariantResolver;
use Contenir\Storage\VariantRegistry;
use SplFileInfo;

/**
 * Local-filesystem implementation of StorageInterface.
 *
 * Storage shape:
 *   $rootPath/<directory>/<filename>                     - the asset itself
 *   $rootPath/<directory>/_variant/<variant>/<filename>   - eager variants
 *
 * Entry::$id is md5(name) so the JS layer's existing delete/rename
 * payloads continue to round-trip without change.
 */
final class LocalFilesystem implements StorageInterface
{
    use Thumbnail;

    private const VARIANT_DIR    = '_variant';
    private const HIDDEN_FILES  = ['.DS_Store', 'Thumbs.db'];
    private const COLLISION_MAX = 1000;

    private readonly UploadResolverInterface $resolver;

    public function __construct(
        private readonly string $rootPath,
        private readonly string $publicPath,
        private readonly VariantRegistry $variants,
        private readonly ImageResizer $resizer,
        ?UploadResolverInterface $resolver = null,
        private readonly ?PathVariantResolver $paths = null,
    ) {
        $this->resolver = $resolver ?? new DefaultUploadResolver();
    }

    public function store(UploadInput $upload, string $directory): Entry
    {
        $directory   = $this->normalisePath($directory);
        $absoluteDir = $this->resolveAbsolutePath($directory);

        if (! is_readable($upload->sourcePath)) {
            throw new WriteException(sprintf('Upload source "%s" is not readable.', $upload->sourcePath));
        }
        if (! is_dir($absoluteDir) && ! @mkdir($absoluteDir, 0o777, true) && ! is_dir($absoluteDir)) {
            throw new WriteException(sprintf('Cannot create directory "%s".', $absoluteDir));
        }
        if (! is_writable($absoluteDir)) {
            throw new WriteException(sprintf('Directory "%s" is not writable.', $absoluteDir));
        }

        $resolved  = $this->resolver->resolve($upload);
        $finalName = $this->resolveCollision($absoluteDir, $resolved->name);
        $destAbs   = $absoluteDir . \DIRECTORY_SEPARATOR . $finalName;

        if (! @copy($upload->sourcePath, $destAbs)) {
            throw new WriteException(sprintf('Failed copying upload to "%s".', $destAbs));
        }

        $relativePath = $directory === '' ? $finalName : $directory . '/' . $finalName;

        if ($resolved->image !== null) {
            foreach ($this->variants->allowedFor($this->paths, $relativePath) as $variant) {
                $this->generateVariant($relativePath, $variant);
            }
        }

        return $this->buildEntry($relativePath, image: $resolved->image);
    }

    public function url(string $path, ?string $variant = null): ?string
    {
        if ($variant !== null && ! $this->variants->has($variant)) {
            throw new InvalidArgumentException(sprintf('Unknown variant "%s".', $variant));
        }

        $path     = $this->normalisePath($path);
        $absolute = $this->resolveAbsolutePath($path);

        if (! is_file($absolute)) {
            return null;
        }

        if ($variant === null) {
            return $this->buildPublicUrl($path);
        }

        $variantRel = $this->variantRelativePath($path, $variant);
        $variantAbs = $this->resolveAbsolutePath($variantRel);

        // Lazy generation: if the variant hasn't been materialised but the
        // original is a real image, resize on demand. This covers files that
        // landed on disk by any route other than store() (legacy data, SCP,
        // rsync, manual drops). One-time cost per asset; subsequent requests
        // hit the cached sibling.
        if (! is_file($variantAbs) && @getimagesize($absolute) !== false) {
            try {
                $this->generateVariant($path, $this->variants->get($variant));
            } catch (\Throwable) {
                // Resizer failure (corrupt image, missing GD/Imagick, perms,
                // etc.) — leave the variant unmaterialised and let the caller
                // fall back to the original URL.
                return null;
            }
        }

        if (! is_file($variantAbs)) {
            return null;
        }

        return $this->buildPublicUrl($variantRel);
    }

    public function urlsForKey(string $path): array
    {
        $path = $this->normalisePath($path);
        $urls = [$this->buildPublicUrl($path)];
        foreach ($this->variants->all() as $variant) {
            $urls[] = $this->buildPublicUrl($this->variantRelativePath($path, $variant->name));
        }
        return $urls;
    }

    public function variantUrls(string $path, string $variantName): array
    {
        if (! $this->variants->has($variantName)) {
            throw new InvalidArgumentException(sprintf('Unknown variant "%s".', $variantName));
        }
        $variant = $this->variants->get($variantName);
        $path    = $this->normalisePath($path);

        // LocalFilesystem stores a variant as a single sibling file under
        // _variant/<name>/ with the original extension — there is no per-format
        // suffix, so every declared format resolves to the same URL.
        $url  = $this->buildPublicUrl($this->variantRelativePath($path, $variantName));
        $urls = [];
        foreach ($variant->targetFormats() as $format) {
            $urls[$format ?? 'source'] = $url;
        }
        return $urls;
    }

    public function list(string $path, ?ListOptions $options = null): iterable
    {
        $options  = $options ?? new ListOptions();
        $path     = $this->normalisePath($path);
        $absolute = $this->resolveAbsolutePath($path);

        if (! is_dir($absolute)) {
            throw NotFoundException::forPath($path);
        }

        $entries = [];
        foreach (new DirectoryIterator($absolute) as $info) {
            if ($info->isDot()) {
                continue;
            }
            $name = $info->getFilename();
            if (str_starts_with($name, '.') || in_array($name, self::HIDDEN_FILES, true)) {
                continue;
            }
            if ($name === self::VARIANT_DIR) {
                continue;
            }

            $relative = $path === '' ? $name : $path . '/' . $name;
            $entry    = $this->buildEntry($relative, clone $info);

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

        usort($entries, $this->comparator($options->sortField, $options->sortDirection));
        return $entries;
    }

    public function exists(string $path): bool
    {
        $path = $this->normalisePath($path);
        return file_exists($this->resolveAbsolutePath($path));
    }

    /**
     * Local-FS-only escape hatch: return the absolute filesystem path for a
     * stored asset. Callers that need to hand the file to a non-storage-aware
     * routine (e.g. mime sniffing via mime_content_type) use this instead of
     * concatenating the storage root directly.
     */
    public function localPath(string $path): string
    {
        return $this->resolveAbsolutePath($this->normalisePath($path));
    }

    public function delete(string $path): void
    {
        $path     = $this->normalisePath($path);
        $absolute = $this->resolveAbsolutePath($path);

        if (! file_exists($absolute)) {
            throw NotFoundException::forPath($path);
        }
        if (is_dir($absolute)) {
            throw new WriteException(sprintf('Cannot delete directory "%s" via delete().', $path));
        }

        foreach ($this->variants->all() as $variant) {
            $variantAbs = $this->resolveAbsolutePath($this->variantRelativePath($path, $variant->name));
            if (is_file($variantAbs)) {
                @unlink($variantAbs);
            }
        }

        if (! @unlink($absolute)) {
            throw new WriteException(sprintf('Failed deleting "%s".', $path));
        }
    }

    public function rename(string $from, string $to): void
    {
        $from        = $this->normalisePath($from);
        $to          = $this->normalisePath($to);
        $absoluteFrom = $this->resolveAbsolutePath($from);
        $absoluteTo   = $this->resolveAbsolutePath($to);

        if (! file_exists($absoluteFrom)) {
            throw NotFoundException::forPath($from);
        }
        if (file_exists($absoluteTo)) {
            throw new WriteException(sprintf('Destination "%s" already exists.', $to));
        }

        $destDir = \dirname($absoluteTo);
        if (! is_dir($destDir) && ! @mkdir($destDir, 0o777, true) && ! is_dir($destDir)) {
            throw new WriteException(sprintf('Cannot create directory "%s".', $destDir));
        }
        if (! @rename($absoluteFrom, $absoluteTo)) {
            throw new WriteException(sprintf('Failed renaming "%s" to "%s".', $from, $to));
        }

        foreach ($this->variants->all() as $variant) {
            $varFrom = $this->resolveAbsolutePath($this->variantRelativePath($from, $variant->name));
            $varTo   = $this->resolveAbsolutePath($this->variantRelativePath($to, $variant->name));
            if (! is_file($varFrom)) {
                continue;
            }
            $varDir = \dirname($varTo);
            if (! is_dir($varDir)) {
                @mkdir($varDir, 0o777, true);
            }
            @rename($varFrom, $varTo);
        }
    }

    public function imageMeta(string $path): ImageMeta
    {
        $path     = $this->normalisePath($path);
        $absolute = $this->resolveAbsolutePath($path);

        if (! is_file($absolute)) {
            throw NotFoundException::forPath($path);
        }

        $info = @getimagesize($absolute);
        if ($info === false) {
            throw NotFoundException::forPath($path);
        }

        return new ImageMeta($info[0], $info[1], $info['mime'] ?? 'application/octet-stream');
    }

    public function regenerateMissingVariants(string $path): array
    {
        $path     = $this->normalisePath($path);
        $absolute = $this->resolveAbsolutePath($path);

        if (! is_file($absolute)) {
            throw NotFoundException::forPath($path);
        }

        /**
         * The LocalFilesystem layout stores one file per variant under
         * `_variant/<variantName>/<basename>` — same extension as the source
         * (no per-format suffix). Format iteration is therefore a no-op
         * here: each variant materialises as a single file matching the
         * source extension.
         */
        $generated = [];
        foreach ($this->variants->allowedFor($this->paths, $path) as $variant) {
            $variantRel = $this->variantRelativePath($path, $variant->name);
            $variantAbs = $this->resolveAbsolutePath($variantRel);
            if (is_file($variantAbs)) {
                continue;
            }

            try {
                $this->generateVariant($path, $variant);
            } catch (\Throwable $e) {
                throw new WriteException(
                    sprintf('Failed regenerating variant "%s" for "%s": %s', $variant->name, $path, $e->getMessage()),
                    0,
                    $e,
                );
            }

            $generated[] = $variantRel;
        }

        return $generated;
    }

    private function generateVariant(string $relativePath, Variant $variant): void
    {
        $sourceAbs = $this->resolveAbsolutePath($relativePath);
        $destAbs   = $this->resolveAbsolutePath($this->variantRelativePath($relativePath, $variant->name));

        $this->resizer->resize($sourceAbs, $destAbs, $variant->width, $variant->height, $variant->fit);
    }

    private function variantRelativePath(string $relativePath, string $variantName): string
    {
        $dir  = $this->relativeDirname($relativePath);
        $name = basename($relativePath);
        $base = $dir === '' ? '' : $dir . '/';
        return sprintf('%s%s/%s/%s', $base, self::VARIANT_DIR, $variantName, $name);
    }

    private function buildEntry(string $relativePath, ?SplFileInfo $info = null, ?ImageMeta $image = null): Entry
    {
        $absolute = $this->resolveAbsolutePath($relativePath);
        $info     = $info ?? new SplFileInfo($absolute);
        $name     = $info->getFilename();
        $isDir    = $info->isDir();
        $mime     = $isDir
            ? 'inode/directory'
            : (@mime_content_type($absolute) ?: 'application/octet-stream');

        return new Entry(
            id:    md5($name),
            name:  $name,
            path:  $relativePath,
            isDir: $isDir,
            size:  $isDir ? 0 : (int) $info->getSize(),
            mtime: (new DateTimeImmutable())->setTimestamp((int) $info->getMTime()),
            mime:  $mime,
            image: $image,
        );
    }

    private function resolveCollision(string $absoluteDir, string $filename): string
    {
        if (! file_exists($absoluteDir . \DIRECTORY_SEPARATOR . $filename)) {
            return $filename;
        }
        $dot  = strrpos($filename, '.');
        $base = $dot === false ? $filename : substr($filename, 0, $dot);
        $ext  = $dot === false ? '' : substr($filename, $dot);
        for ($i = 1; $i < self::COLLISION_MAX; $i++) {
            $candidate = sprintf('%s_%d%s', $base, $i, $ext);
            if (! file_exists($absoluteDir . \DIRECTORY_SEPARATOR . $candidate)) {
                return $candidate;
            }
        }
        throw new WriteException(sprintf('Cannot allocate unique filename in "%s".', $absoluteDir));
    }

    private function buildPublicUrl(string $relativePath): string
    {
        $prefix = rtrim($this->publicPath, '/');
        return ($prefix === '' ? '' : $prefix) . '/' . $relativePath;
    }

    private function normalisePath(string $path): string
    {
        return trim(str_replace('\\', '/', $path), '/');
    }

    private function relativeDirname(string $relativePath): string
    {
        $dir = \dirname($relativePath);
        return ($dir === '.' || $dir === '/' || $dir === '') ? '' : $dir;
    }

    private function resolveAbsolutePath(string $relative): string
    {
        if (str_contains($relative, "\0")) {
            throw InvalidPathException::forNullByte($relative);
        }
        if (preg_match('#(?:^|/)\.\.(?:/|$)#', $relative) === 1) {
            throw InvalidPathException::forTraversal($relative);
        }
        if (stripos($relative, '%2e%2e') !== false) {
            throw InvalidPathException::forTraversal($relative);
        }

        $absolute = $relative === ''
            ? $this->rootPath
            : $this->rootPath . \DIRECTORY_SEPARATOR . $relative;

        $real = realpath($absolute);
        if ($real !== false) {
            $realRoot = realpath($this->rootPath);
            if ($realRoot === false) {
                $realRoot = $this->rootPath;
            }
            if ($real !== $realRoot && ! str_starts_with($real, $realRoot . \DIRECTORY_SEPARATOR)) {
                throw InvalidPathException::forEscape($relative);
            }
        }

        return $absolute;
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
