<?php

declare(strict_types=1);

namespace Contenir\Storage;

use InvalidArgumentException;

/**
 * Default implementation of {@see StorageInterface::thumbnailUrl()} as a
 * thin wrapper over `url($path, StorageInterface::THUMBNAIL_VARIANT)`. Mixed
 * into each adapter so the convenience is uniformly available without each
 * adapter having to repeat the same try/catch.
 *
 * Adapters with custom thumbnail resolution (e.g. URL transforms instead of
 * materialised siblings) can simply not use the trait and implement the
 * method directly.
 */
trait Thumbnail
{
    final public function thumbnailUrl(string $path): ?string
    {
        try {
            return $this->url($path, StorageInterface::THUMBNAIL_VARIANT);
        } catch (InvalidArgumentException) {
            return null;
        }
    }
}
