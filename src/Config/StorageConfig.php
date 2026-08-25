<?php

declare(strict_types=1);

namespace Contenir\Storage\Config;

use Aws\S3\S3Client;
use InvalidArgumentException;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use League\Flysystem\Filesystem;
use Contenir\Storage\StorageInterface;
use Contenir\Storage\Image\ImageResizer;
use Contenir\Storage\Adapter\CloudflareImages;
use Contenir\Storage\Adapter\LocalFilesystem;
use Contenir\Storage\Adapter\S3;
use Contenir\Storage\StorageManager;
use Contenir\Storage\Variant;
use Contenir\Storage\VariantFit;
use Contenir\Storage\VariantRegistry;

/**
 * Builds a StorageManager from the flat `storage` config.
 *
 * Expected shape — three sibling keys, nothing else:
 *
 *   [
 *     // WHERE bytes live. A `local` backend (rooted at the web root) is ALWAYS
 *     // pre-wired; declare extra backends here. Primary = the one flagged
 *     // 'default' => true, else `local`. Declaring `r2` alone leaves `local`
 *     // primary; add 'default' => true to promote it.
 *     'backend' => [
 *       'r2' => ['type' => 's3', 'default' => true, 'bucket' => '…', 'endpoint' => '…', 'key' => '…', 'secret' => '…'],
 *       // 'local' => ['type' => 'local', 'root_path' => '/abs/override'],  // only to override the pre-wired root
 *     ],
 *     // WHAT transforms exist. Flat; each MAY pin a 'backend' (default = primary).
 *     'variants' => [
 *       'admin-thumb' => ['width' => 180, 'height' => 180, 'fit' => 'contain'],
 *       'gallery'     => ['dimensions' => ['320x', '640x'], 'fit' => 'contain'],
 *     ],
 *     // WHICH variants a path owns — keyed by path; '*' is the universal wildcard.
 *     // Consumed via resolverFromArray(); not needed to build the manager.
 *     'paths' => ['*' => ['variants' => ['admin-thumb']], '/asset/library/news/lg' => ['variants' => ['gallery']]],
 *   ]
 *
 * Each backend carries a VariantRegistry of the variants assigned to it (a
 * variant's own `backend`, else the primary). The primary backend holds
 * originals and any variant that doesn't pin its own.
 */
final class StorageConfig
{
    /**
     * @param array<string, mixed>|null $config The `storage` config array.
     *
     * @throws InvalidArgumentException If a backend or variant is malformed.
     */
    public static function fromArray(?array $config, ImageResizer $resizer, string $defaultRootPath): StorageManager
    {
        $config   = is_array($config) ? $config : [];
        $declared = is_array($config['backend'] ?? null) ? $config['backend'] : [];
        $variants = is_array($config['variants'] ?? null) ? $config['variants'] : [];

        // 'local' is always pre-wired (rooted at the web root) unless the config
        // declares its own 'local' backend, e.g. to override root_path.
        $backends = $declared;
        if (! isset($backends[StorageManager::DEFAULT_PROFILE])) {
            $backends[StorageManager::DEFAULT_PROFILE] = ['type' => 'local'];
        }

        $primary   = self::primaryBackendKey($backends);
        $byBackend = self::variantsByBackend($variants, $primary, $backends);
        // Ownership is consulted at generation time so an upload only
        // materialises the families its path owns.
        $paths     = self::resolverFromArray($config);

        $manager = new StorageManager();
        foreach ($backends as $name => $backend) {
            if (! is_array($backend)) {
                throw new InvalidArgumentException(sprintf('Backend "%s" must be an array.', $name));
            }
            $name = (string) $name;
            $manager->register(
                $name,
                self::buildBackend(
                    $name,
                    $backend,
                    new VariantRegistry(...($byBackend[$name] ?? [])),
                    $resizer,
                    $defaultRootPath,
                    $paths,
                ),
                $name === $primary,
            );
        }

        return $manager;
    }

    /**
     * Build the path → families resolver from the `storage.paths` map. One
     * canonical construction point reused by generation (worker) and render
     * (front-end). Each entry is `<path> => ['variants' => [...]]`; the '*' key
     * declares families owned by every path.
     */
    public static function resolverFromArray(?array $config): PathVariantResolver
    {
        $paths = (is_array($config) && is_array($config['paths'] ?? null)) ? $config['paths'] : [];

        $map = [];
        foreach ($paths as $base => $entry) {
            $map[(string) $base] = (is_array($entry) && is_array($entry['variants'] ?? null))
                ? array_values($entry['variants'])
                : [];
        }

        return new PathVariantResolver($map);
    }

    /**
     * Build a manager with a single implicit local backend at $rootPath. Used
     * when no storage config is supplied so consumers keep working.
     */
    public static function default(ImageResizer $resizer, string $rootPath): StorageManager
    {
        return self::fromArray(null, $resizer, $rootPath);
    }

    /**
     * The primary backend's raw config block (the `'default' => true` backend,
     * else the always-present `local`). Lets front-end factories read backend
     * settings — `public_base_url`, `generate_secret`, `root_path`, `type` — from
     * the one place they live, instead of a separate top-level block.
     *
     * @param array<string, mixed>|null $config The `storage` config array.
     *
     * @return array<string, mixed>
     */
    public static function primaryBackendConfig(?array $config): array
    {
        $config   = is_array($config) ? $config : [];
        $declared = is_array($config['backend'] ?? null) ? $config['backend'] : [];

        $backends = $declared;
        if (! isset($backends[StorageManager::DEFAULT_PROFILE])) {
            $backends[StorageManager::DEFAULT_PROFILE] = ['type' => 'local'];
        }

        $primary = self::primaryBackendKey($backends);

        return is_array($backends[$primary] ?? null) ? $backends[$primary] : [];
    }

    /**
     * The declared variant names assigned to a backend (a variant's own
     * `backend`, else the primary). Lets the CMS asset-index UI/worker list a
     * backend's variants from the config without building a StorageManager.
     *
     * @param array<string, mixed>|null $config The `storage` config array.
     *
     * @return list<string>
     */
    public static function variantNamesForBackend(?array $config, string $backend): array
    {
        $config   = is_array($config) ? $config : [];
        $declared = is_array($config['backend'] ?? null) ? $config['backend'] : [];
        $variants = is_array($config['variants'] ?? null) ? $config['variants'] : [];

        $backends = $declared;
        if (! isset($backends[StorageManager::DEFAULT_PROFILE])) {
            $backends[StorageManager::DEFAULT_PROFILE] = ['type' => 'local'];
        }
        $primary = self::primaryBackendKey($backends);

        $names = [];
        foreach ($variants as $name => $spec) {
            if (! is_array($spec)) {
                continue;
            }
            $target = isset($spec['backend']) ? (string) $spec['backend'] : $primary;
            if ($target === $backend) {
                $names[] = (string) $name;
            }
        }

        return $names;
    }

    /**
     * @param array<string, mixed> $backends
     */
    /**
     * Primary = the backend flagged `'default' => true`; if none is flagged, the
     * always-present `local` backend. More than one flagged is a config error.
     *
     * @param array<string, mixed> $backends
     */
    private static function primaryBackendKey(array $backends): string
    {
        $flagged = [];
        foreach ($backends as $name => $backend) {
            if (is_array($backend) && ($backend['default'] ?? false) === true) {
                $flagged[] = (string) $name;
            }
        }

        if (count($flagged) > 1) {
            throw new InvalidArgumentException(sprintf(
                'At most one backend may declare \'default\' => true (found %d).',
                count($flagged),
            ));
        }

        return $flagged[0] ?? StorageManager::DEFAULT_PROFILE;
    }

    /**
     * Expand every variant declaration and group the resulting Variant objects
     * by their target backend (a variant's own `backend`, else the primary).
     *
     * @param array<string, mixed> $variants
     * @param array<string, mixed> $backends
     *
     * @return array<string, list<Variant>>
     */
    private static function variantsByBackend(array $variants, string $primary, array $backends): array
    {
        $byBackend = [];
        foreach ($variants as $name => $spec) {
            if (! is_array($spec)) {
                throw new InvalidArgumentException(sprintf('Variant "%s" must be an array.', $name));
            }

            $target = isset($spec['backend']) ? (string) $spec['backend'] : $primary;
            if (! isset($backends[$target])) {
                throw new InvalidArgumentException(sprintf(
                    'Variant "%s" targets unknown backend "%s".',
                    $name,
                    $target,
                ));
            }

            foreach (self::buildVariant((string) $name, $spec) as $variant) {
                $byBackend[$target][] = $variant;
            }
        }

        return $byBackend;
    }

    /**
     * Expand one variant declaration: an art-directed `dimensions` ladder
     * compiles to one Variant per rung (`<name>-<width>`); otherwise it is a
     * single flat variant declared with explicit width/height.
     *
     * @param array<string, mixed> $spec
     *
     * @return list<Variant>
     */
    private static function buildVariant(string $name, array $spec): array
    {
        if (isset($spec['dimensions'])) {
            return VariantProfile::fromArray($name, $spec)->variants;
        }

        $formats = [];
        if (isset($spec['formats']) && is_array($spec['formats'])) {
            foreach ($spec['formats'] as $format) {
                $formats[] = strtolower(trim((string) $format, " \t\n\r\0\x0B."));
            }
        }

        return [new Variant(
            name:    $name,
            width:   (int) self::requireString($spec, 'width'),
            height:  (int) self::requireString($spec, 'height'),
            fit:     self::parseFit((string) ($spec['fit'] ?? 'contain')),
            formats: $formats,
            quality: isset($spec['quality']) ? (int) $spec['quality'] : null,
        )];
    }

    /**
     * @param array<string, mixed> $backend
     */
    private static function buildBackend(
        string $name,
        array $backend,
        VariantRegistry $variants,
        ImageResizer $resizer,
        string $defaultRootPath,
        ?PathVariantResolver $paths = null,
    ): StorageInterface {
        $type = (string) ($backend['type'] ?? 'local');

        return match ($type) {
            'local'             => self::buildLocal($backend, $variants, $resizer, $defaultRootPath, $paths),
            's3'                => self::buildS3($backend, $variants, $resizer, $paths),
            'cloudflare-images' => self::buildCloudflareImages($name, $backend, $variants, $resizer),
            default => throw new InvalidArgumentException(sprintf(
                'Backend "%s" has unknown type "%s" (expected: local, s3, cloudflare-images).',
                $name,
                $type,
            )),
        };
    }

    /**
     * Local-filesystem backend. An explicit `root_path` on the backend overrides
     * the caller's default root (the resolved web root); otherwise the default is
     * used, so a backend-less local site needs no `root_path` at all.
     *
     * @param array<string, mixed> $backend
     */
    private static function buildLocal(
        array $backend,
        VariantRegistry $variants,
        ImageResizer $resizer,
        string $defaultRootPath,
        ?PathVariantResolver $paths = null,
    ): LocalFilesystem {
        $root = (string) ($backend['root_path'] ?? '');

        return new LocalFilesystem(
            rootPath:   $root !== '' ? $root : $defaultRootPath,
            publicPath: (string) ($backend['public_path'] ?? ''),
            variants:   $variants,
            resizer:    $resizer,
            paths:      $paths,
        );
    }

    /**
     * @param array<string, mixed> $backend
     */
    private static function buildS3(
        array $backend,
        VariantRegistry $variants,
        ImageResizer $resizer,
        ?PathVariantResolver $paths = null,
    ): S3 {
        return new S3(
            fs:            self::buildFlysystem($backend),
            publicUrlBase: self::requireString($backend, 'publicUrl'),
            variants:      $variants,
            resizer:       $resizer,
            paths:         $paths,
        );
    }

    /**
     * @param array<string, mixed> $backend
     */
    private static function buildCloudflareImages(
        string $name,
        array $backend,
        VariantRegistry $variants,
        ImageResizer $resizer,
    ): CloudflareImages {
        // The wrapped object store doesn't pre-generate variants — they resolve
        // through Cloudflare URL transforms at url() time.
        $inner = new S3(
            fs:            self::buildFlysystem($backend),
            publicUrlBase: self::requireString($backend, 'publicUrl'),
            variants:      new VariantRegistry(),
            resizer:       $resizer,
        );

        return new CloudflareImages(
            objectStore:     $inner,
            deliveryBaseUrl: self::requireString($backend, 'deliveryBaseUrl'),
            variants:        $variants,
        );
    }

    /**
     * @param array<string, mixed> $backend
     */
    private static function buildFlysystem(array $backend): Filesystem
    {
        $client = new S3Client([
            'version'                 => 'latest',
            'region'                  => (string) ($backend['region'] ?? 'auto'),
            'endpoint'                => self::requireString($backend, 'endpoint'),
            'credentials'             => [
                'key'    => self::requireString($backend, 'key'),
                'secret' => self::requireString($backend, 'secret'),
            ],
            'use_path_style_endpoint' => (bool) ($backend['usePathStyleEndpoint'] ?? false),
        ]);

        return new Filesystem(new AwsS3V3Adapter(
            $client,
            self::requireString($backend, 'bucket'),
        ));
    }

    private static function parseFit(string $fit): VariantFit
    {
        return match (strtolower($fit)) {
            'cover'   => VariantFit::Cover,
            'contain' => VariantFit::Contain,
            'fill'    => VariantFit::Fill,
            default   => throw new InvalidArgumentException(sprintf(
                'Unknown variant fit "%s" (expected: cover, contain, fill).',
                $fit,
            )),
        };
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function requireString(array $config, string $key): string
    {
        $value = $config[$key] ?? null;
        if ($value === null || $value === '') {
            throw new InvalidArgumentException(sprintf('Missing required config key "%s".', $key));
        }
        return (string) $value;
    }
}
