<?php

declare(strict_types=1);

namespace Contenir\Storage;

final readonly class ImageMeta
{
    public function __construct(
        public int $width,
        public int $height,
        public string $mime,
    ) {
    }
}
