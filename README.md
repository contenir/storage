# contenir/storage

Framework-agnostic asset storage for [Contenir CMS](https://github.com/contenir).

Provides a unified `StorageInterface` for reading, writing, and listing
CMS-managed assets across local filesystem, S3-compatible object stores,
and Cloudflare Images, with a shared variant pipeline (responsive image
sizes derived from a single source).

## Install

```bash
composer require contenir/storage
```

The package itself only requires `league/flysystem` and `psr/log`. Pull
in the optional dependencies for the adapter and features you use:

| You need                        | Also require                       |
|---------------------------------|------------------------------------|
| Local image variant generation  | `gumlet/php-image-resize`          |
| S3 adapter                      | `league/flysystem-aws-s3-v3`       |
| Cloudflare Images adapter       | `cloudflare/sdk`                   |

## Usage

```php
use Contenir\Storage\StorageManager;
use Contenir\Storage\Adapter\LocalFilesystem;
use Contenir\Storage\VariantRegistry;

$variants = new VariantRegistry();
$variants->register('admin-thumb', new Variant(width: 200, height: 200));

$manager = new StorageManager();
$manager->register('default', new LocalFilesystem(
    rootPath: '/var/www/uploads',
    publicUrl: 'https://example.com/uploads',
    variants: $variants,
));

$backend = $manager->get('default');
$url = $backend->url('logos/site.png', variant: 'admin-thumb');
```

See `src/Adapter/` for the full set of adapters and `tests/` for end-to-end
examples against each.

## License

MIT