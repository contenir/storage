<?php

declare(strict_types=1);

namespace Contenir\Storage;

use DateTimeImmutable;

/**
 * A single item returned by StorageInterface::list() or ::store().
 *
 * $id is a stable identifier the JS layer round-trips on delete/rename
 * operations. Local backends compute it as md5(name) to preserve the existing
 * FileBrowser contract; cloud backends derive it from the object key.
 *
 * $path is relative to the storage root (e.g. "products/hero.jpg"), never
 * absolute. The mapping to a public URL is the backend's responsibility — see
 * StorageInterface::url().
 *
 * For directories, $mime is "inode/directory" so isImage() correctly returns
 * false without a separate type check.
 *
 * $image carries the dimensions detected at store() time for raster images;
 * it is null for directories, non-images, and entries produced by list() (which
 * does not sniff dimensions). Callers persist it as provenance metadata.
 */
final readonly class Entry
{
    public function __construct(
        public string $id,
        public string $name,
        public string $path,
        public bool $isDir,
        public int $size,
        public DateTimeImmutable $mtime,
        public string $mime,
        public ?ImageMeta $image = null,
    ) {
    }

    public function isFile(): bool
    {
        return ! $this->isDir;
    }

    public function isImage(): bool
    {
        return ! $this->isDir && str_starts_with($this->mime, 'image/');
    }
}
