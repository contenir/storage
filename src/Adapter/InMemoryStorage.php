<?php

declare(strict_types=1);

namespace Contenir\Storage\Adapter;

use DateTimeImmutable;
use InvalidArgumentException;
use Contenir\Storage\Entry;
use Contenir\Storage\StorageInterface;
use Contenir\Storage\Thumbnail;
use Contenir\Storage\Exception\NotFoundException;
use Contenir\Storage\Exception\WriteException;
use Contenir\Storage\ImageMeta;
use Contenir\Storage\ListOptions;
use Contenir\Storage\SortDirection;
use Contenir\Storage\SortField;
use Contenir\Storage\UploadInput;
use Contenir\Storage\VariantRegistry;

/**
 * Hand-rolled in-memory backend used by tests that need to inject the contract
 * without touching disk or network.
 *
 * The fake intentionally does not perform any image transformation — variant
 * URLs are emitted deterministically from the registered variant name so tests
 * can assert on them, but no derived bytes are produced. Real image processing
 * lives in the production backends and is verified by their integration tests.
 */
final class InMemoryStorage implements StorageInterface
{
    use Thumbnail;

    /** @var array<string, array{isDir: bool, bytes: string, mime: string, mtime: DateTimeImmutable, width?: int, height?: int}> */
    private array $nodes = [];

    public function __construct(
        private readonly VariantRegistry $variants = new VariantRegistry(),
        private readonly string $urlScheme = 'memory://',
    ) {
    }

    public function makeDirectory(string $path): void
    {
        $path = $this->normalise($path);
        $this->nodes[$path] = [
            'isDir' => true,
            'bytes' => '',
            'mime'  => 'inode/directory',
            'mtime' => new DateTimeImmutable(),
        ];
    }

    public function putFile(
        string $path,
        string $bytes,
        string $mime = 'application/octet-stream',
        ?int $width = null,
        ?int $height = null,
    ): void {
        $path = $this->normalise($path);
        $node = [
            'isDir' => false,
            'bytes' => $bytes,
            'mime'  => $mime,
            'mtime' => new DateTimeImmutable(),
        ];
        if ($width !== null && $height !== null) {
            $node['width']  = $width;
            $node['height'] = $height;
        }
        $this->nodes[$path] = $node;
    }

    public function store(UploadInput $upload, string $directory): Entry
    {
        if (! is_readable($upload->sourcePath)) {
            throw new WriteException(sprintf('Cannot read upload source "%s".', $upload->sourcePath));
        }

        $bytes = file_get_contents($upload->sourcePath);
        if ($bytes === false) {
            throw new WriteException(sprintf('Failed reading upload source "%s".', $upload->sourcePath));
        }

        $name = basename($upload->clientFilename);
        $path = $this->normalise(rtrim($directory, '/') . '/' . $name);
        $mime = $upload->clientMime ?? 'application/octet-stream';

        $width  = null;
        $height = null;
        if (str_starts_with($mime, 'image/')) {
            $size = @getimagesizefromstring($bytes);
            if (is_array($size)) {
                $width  = $size[0];
                $height = $size[1];
            }
        }

        $this->putFile($path, $bytes, $mime, $width, $height);

        return $this->entryFor($path);
    }

    public function url(string $path, ?string $variant = null): ?string
    {
        if ($variant !== null && ! $this->variants->has($variant)) {
            throw new InvalidArgumentException(sprintf('Unknown variant "%s".', $variant));
        }

        $path = $this->normalise($path);
        if (! isset($this->nodes[$path]) || $this->nodes[$path]['isDir']) {
            return null;
        }

        return $variant === null
            ? $this->urlScheme . $path
            : sprintf('%s%s?v=%s', $this->urlScheme, $path, $variant);
    }

    public function urlsForKey(string $path): array
    {
        $path = $this->normalise($path);
        $urls = [$this->urlScheme . $path];
        foreach ($this->variants->all() as $variant) {
            $urls[] = sprintf('%s%s?v=%s', $this->urlScheme, $path, $variant->name);
        }
        return $urls;
    }

    public function list(string $path, ?ListOptions $options = null): iterable
    {
        $path    = $this->normalise($path);
        $options = $options ?? new ListOptions();
        $prefix  = $path === '' ? '' : $path . '/';

        if ($path !== '' && isset($this->nodes[$path]) && ! $this->nodes[$path]['isDir']) {
            throw NotFoundException::forPath($path);
        }
        if ($path !== '' && ! isset($this->nodes[$path]) && ! $this->hasDescendants($prefix)) {
            throw NotFoundException::forPath($path);
        }

        $entries = [];
        foreach ($this->nodes as $key => $_node) {
            if ($key === $path) {
                continue;
            }
            if ($prefix !== '' && ! str_starts_with($key, $prefix)) {
                continue;
            }
            $remainder = substr($key, strlen($prefix));
            if (str_contains($remainder, '/')) {
                continue;
            }

            $entry = $this->entryFor($key);

            if (! $options->includeDirectories && $entry->isDir) {
                continue;
            }
            if ($options->keyword !== null && stripos($entry->name, $options->keyword) === false) {
                continue;
            }

            $entries[] = $entry;
        }

        usort($entries, $this->comparator($options->sortField, $options->sortDirection));

        return $entries;
    }

    public function exists(string $path): bool
    {
        return isset($this->nodes[$this->normalise($path)]);
    }

    public function delete(string $path): void
    {
        $path = $this->normalise($path);
        if (! isset($this->nodes[$path])) {
            throw NotFoundException::forPath($path);
        }
        unset($this->nodes[$path]);
    }

    public function rename(string $from, string $to): void
    {
        $from = $this->normalise($from);
        $to   = $this->normalise($to);
        if (! isset($this->nodes[$from])) {
            throw NotFoundException::forPath($from);
        }
        $this->nodes[$to] = $this->nodes[$from];
        unset($this->nodes[$from]);
    }

    public function imageMeta(string $path): ImageMeta
    {
        $path = $this->normalise($path);
        if (! isset($this->nodes[$path]) || $this->nodes[$path]['isDir']) {
            throw NotFoundException::forPath($path);
        }
        $node = $this->nodes[$path];
        if (! isset($node['width'], $node['height'])) {
            throw NotFoundException::forPath($path);
        }

        return new ImageMeta($node['width'], $node['height'], $node['mime']);
    }

    public function regenerateMissingVariants(string $path): array
    {
        /**
         * The in-memory adapter doesn't materialise variants — they're only
         * tracked when callers explicitly `putFile()` them. Surface the
         * NotFoundException for missing originals so tests can assert the
         * same failure shape across adapters; otherwise return [].
         */
        $path = $this->normalise($path);
        if (! isset($this->nodes[$path]) || $this->nodes[$path]['isDir']) {
            throw NotFoundException::forPath($path);
        }
        return [];
    }

    private function entryFor(string $path): Entry
    {
        $node = $this->nodes[$path];
        $name = basename($path);

        return new Entry(
            id: md5($name),
            name: $name,
            path: $path,
            isDir: $node['isDir'],
            size: strlen($node['bytes']),
            mtime: $node['mtime'],
            mime: $node['mime'],
        );
    }

    private function normalise(string $path): string
    {
        return trim($path, '/');
    }

    private function hasDescendants(string $prefix): bool
    {
        foreach (array_keys($this->nodes) as $key) {
            if (str_starts_with($key, $prefix)) {
                return true;
            }
        }
        return false;
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
