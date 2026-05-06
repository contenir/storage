<?php

declare(strict_types=1);

namespace Contenir\Storage\Adapter;

use InvalidArgumentException;
use Contenir\Storage\Entry;
use Contenir\Storage\StorageInterface;
use Contenir\Storage\Thumbnail;
use Contenir\Storage\ImageMeta;
use Contenir\Storage\ListOptions;
use Contenir\Storage\UploadInput;
use Contenir\Storage\Variant;
use Contenir\Storage\VariantFit;
use Contenir\Storage\VariantRegistry;

/**
 * Wraps another storage backend and serves variants through Cloudflare's
 * URL-based image transforms instead of materialising sibling objects.
 *
 * For an asset stored at `products/hero.jpg`, requesting variant
 * `'admin-thumb'` (180×180 contain) produces:
 *
 *   https://cdn.example.com/cdn-cgi/image/width=180,height=180,fit=contain/products/hero.jpg
 *
 * The wrapped backend (typically an S3 instance pointed at R2) does *not*
 * pre-generate variants — construct it with an empty VariantRegistry. This
 * class owns the variant catalogue and resolves it at URL time. No second
 * upload, no Cloudflare Images API account required; just enable image
 * resizing on the Cloudflare zone fronting your R2 bucket.
 *
 * Every non-URL method (store/list/exists/delete/rename/imageMeta) delegates
 * to the wrapped backend unchanged.
 */
final class CloudflareImages implements StorageInterface
{
    use Thumbnail;

    public function __construct(
        private readonly StorageInterface $objectStore,
        private readonly string $deliveryBaseUrl,
        private readonly VariantRegistry $variants,
    ) {
    }

    public function store(UploadInput $upload, string $directory): Entry
    {
        return $this->objectStore->store($upload, $directory);
    }

    public function url(string $path, ?string $variant = null): ?string
    {
        if ($variant !== null && ! $this->variants->has($variant)) {
            throw new InvalidArgumentException(sprintf('Unknown variant "%s".', $variant));
        }

        if ($variant === null) {
            return $this->objectStore->url($path);
        }

        if (! $this->objectStore->exists($path)) {
            return null;
        }

        return $this->buildTransformUrl($path, $this->variants->get($variant));
    }

    public function urlsForKey(string $path): array
    {
        $urls = $this->objectStore->urlsForKey($path);
        foreach ($this->variants->all() as $variant) {
            $urls[] = $this->buildTransformUrl($path, $variant);
        }
        return $urls;
    }

    public function list(string $path, ?ListOptions $options = null): iterable
    {
        return $this->objectStore->list($path, $options);
    }

    public function exists(string $path): bool
    {
        return $this->objectStore->exists($path);
    }

    public function delete(string $path): void
    {
        $this->objectStore->delete($path);
    }

    public function rename(string $from, string $to): void
    {
        $this->objectStore->rename($from, $to);
    }

    public function imageMeta(string $path): ImageMeta
    {
        return $this->objectStore->imageMeta($path);
    }

    private function buildTransformUrl(string $path, Variant $variant): string
    {
        $params = sprintf(
            'width=%d,height=%d,fit=%s',
            $variant->width,
            $variant->height,
            $this->fitParam($variant->fit),
        );

        return rtrim($this->deliveryBaseUrl, '/')
            . '/cdn-cgi/image/' . $params
            . '/' . ltrim($path, '/');
    }

    private function fitParam(VariantFit $fit): string
    {
        return match ($fit) {
            VariantFit::Cover   => 'cover',
            VariantFit::Contain => 'contain',
            VariantFit::Fill    => 'crop',
        };
    }
}
