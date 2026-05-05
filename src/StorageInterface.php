<?php

declare(strict_types=1);

namespace Contenir\Storage;

use Contenir\Storage\Exception\NotFoundException;
use Contenir\Storage\Exception\WriteException;

/**
 * Storage backend for CMS assets (images, files, and directories).
 *
 * Backends own the full lifecycle of an asset including any derived variants:
 * a local-filesystem backend pre-generates thumbnails on store(), a Cloudflare
 * Images-backed R2 backend resolves variants at url() time. Callers should not
 * assume a particular materialisation strategy.
 *
 * Paths are always relative to the storage root, use forward slashes, and do
 * not begin with a slash. Backends are responsible for rejecting traversal
 * attempts.
 */
interface StorageInterface
{
    /**
     * Persist an upload at $directory under its (sanitised) client filename.
     *
     * Backends generate any registered variants as part of this call where the
     * underlying storage requires eager materialisation.
     *
     * @throws WriteException If the file cannot be written.
     */
    public function store(UploadInput $upload, string $directory): Entry;

    /**
     * Public URL for an asset, optionally for a named variant.
     *
     * Returns null when $path does not exist or when the requested variant has
     * not been materialised for this asset. Passing an unknown variant name is
     * a programming error and raises InvalidArgumentException.
     */
    public function url(string $path, ?string $variant = null): ?string;

    /**
     * Public URLs for the original plus every declared variant.
     *
     * Used by CDN cache invalidation: the caller needs every URL that an
     * upstream cache might be holding for this asset, regardless of whether
     * each variant has actually been materialised. URLs are returned without
     * existence checks — the consumer (typically a purge call) can safely
     * include URLs that were never cached.
     *
     * @return list<string>
     */
    public function urlsForKey(string $path): array;

    /**
     * Browse a directory.
     *
     * @return iterable<Entry>
     *
     * @throws NotFoundException If $path does not exist.
     */
    public function list(string $path, ?ListOptions $options = null): iterable;

    public function exists(string $path): bool;

    /** @throws NotFoundException If $path does not exist. */
    public function delete(string $path): void;

    /** @throws NotFoundException If $from does not exist. */
    public function rename(string $from, string $to): void;

    /** @throws NotFoundException If $path does not exist or is not an image. */
    public function imageMeta(string $path): ImageMeta;
}
