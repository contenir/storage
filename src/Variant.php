<?php

declare(strict_types=1);

namespace Contenir\Storage;

/**
 * Named image variant (e.g. an admin thumbnail).
 *
 * Variants are declared by the application up-front and consumed by storage
 * backends — local backends pre-generate them at store time, cloud backends
 * with native transform support resolve them at URL time.
 */
final readonly class Variant
{
    public function __construct(
        public string $name,
        public int $width,
        public int $height,
        public VariantFit $fit = VariantFit::Cover,
    ) {
    }
}
