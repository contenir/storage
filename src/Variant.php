<?php

declare(strict_types=1);

namespace Contenir\Storage;

/**
 * Named image variant (e.g. an admin thumbnail).
 *
 * Variants are declared by the application up-front and consumed by storage
 * backends — local backends pre-generate them at store time, cloud backends
 * with native transform support resolve them at URL time.
 *
 * Each variant may be materialised in one or more output formats (e.g. AVIF,
 * WebP). When `$formats` is empty the source extension is preserved (legacy
 * behaviour). When multiple formats are declared, the backend generates one
 * sibling object per format and consumers can pick via `<picture>` markup.
 */
final readonly class Variant
{
    /** @param array<int, string> $formats Output extensions without leading dot, e.g. ['avif', 'webp']. */
    public function __construct(
        public string $name,
        public int $width,
        public int $height,
        public VariantFit $fit = VariantFit::Cover,
        public array $formats = [],
        public ?int $quality = null,
    ) {
    }

    /**
     * Formats to materialise. Falls back to [null] (meaning "use the source's
     * extension") so callers can iterate uniformly.
     *
     * @return array<int, ?string>
     */
    public function targetFormats(): array
    {
        return $this->formats === [] ? [null] : $this->formats;
    }
}
