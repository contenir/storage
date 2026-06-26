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
 * Builds a StorageManager from a profiles array.
 *
 * Expected config shape:
 *
 *   [
 *     // Optional shared backend block (storage.global.php form): local
 *     // profiles that omit their own rootPath/publicPath inherit it from here.
 *     'asset' => ['root_path' => '/abs/path', 'public_path' => ''],
 *     'profiles' => [
 *       '<name>' => [
 *         'type' => 'local' | 's3' | 'cloudflare-images',
 *         // ... type-specific keys ...
 *         'variants' => [
 *           '<vname>' => ['width' => 200, 'height' => 200, 'fit' => 'contain'],
 *           // or an art-directed ladder: ['dimensions' => ['320x', '640x']]
 *         ],
 *       ],
 *     ],
 *   ]
 *
 * Each profile carries its own VariantRegistry. Apps can declare wildly
 * different variant catalogues per profile (e.g. an archive bucket with
 * none, a CDN bucket with several). The optional `asset` block lets the same
 * file the front-end reads — where the backend root lives once under `asset`
 * and each profile declares only its variant catalogue — build a CMS-side
 * StorageManager without restating the root on every local profile.
 */
final class StorageConfig
{
    /**
     * @param array<string, mixed>|null $config
     *
     * @throws InvalidArgumentException If a profile is malformed or references an unknown type.
     */
    public static function fromArray(?array $config, ImageResizer $resizer, string $defaultRootPath): StorageManager
    {
        $manager = new StorageManager();
        $profiles = $config['profiles'] ?? null;

        if (! is_array($profiles) || $profiles === []) {
            return self::registerDefault($manager, $resizer, $defaultRootPath);
        }

        $asset = is_array($config['asset'] ?? null) ? $config['asset'] : [];

        foreach ($profiles as $name => $profile) {
            if (! is_array($profile)) {
                throw new InvalidArgumentException(sprintf('Profile "%s" must be an array.', $name));
            }
            $manager->register(
                (string) $name,
                self::buildBackend((string) $name, $profile, $asset, $resizer, $defaultRootPath),
            );
        }

        return $manager;
    }

    /**
     * Build a manager with a single 'local' profile pointed at $rootPath
     * with one admin-thumb variant. Used when no storage config is supplied
     * so consumers keep working without a config change.
     */
    public static function default(ImageResizer $resizer, string $rootPath): StorageManager
    {
        return self::registerDefault(new StorageManager(), $resizer, $rootPath);
    }

    private static function registerDefault(
        StorageManager $manager,
        ImageResizer $resizer,
        string $rootPath,
    ): StorageManager {
        $manager->register('local', new LocalFilesystem(
            rootPath:   $rootPath,
            publicPath: '',
            variants:   new VariantRegistry(
                new Variant('admin-thumb', 180, 180, VariantFit::Contain),
            ),
            resizer:    $resizer,
        ));
        return $manager;
    }

    /**
     * @param array<string, mixed> $profile
     */
    private static function buildBackend(
        string $profileName,
        array $profile,
        array $asset,
        ImageResizer $resizer,
        string $defaultRootPath,
    ): StorageInterface {
        $type     = (string) ($profile['type'] ?? '');
        $variants = self::buildVariants($profile['variants'] ?? null);

        return match ($type) {
            'local'             => self::buildLocal($profile, $asset, $variants, $resizer, $defaultRootPath),
            's3'                => self::buildS3($profile, $variants, $resizer),
            'cloudflare-images' => self::buildCloudflareImages($profileName, $profile, $variants, $resizer),
            default => throw new InvalidArgumentException(sprintf(
                'Profile "%s" has unknown type "%s" (expected: local, s3, cloudflare-images).',
                $profileName,
                $type,
            )),
        };
    }

    /**
     * Build the local-filesystem backend for a profile.
     *
     * `rootPath`/`publicPath` may sit on the profile directly, or be inherited
     * from the shared `asset` block (`root_path`/`public_path`) that the
     * front-end catalogue (storage.global.php) declares once for every profile —
     * there the profile carries only its variant catalogue. An absent root falls
     * back to the caller's default root (the resolved public dir), so a
     * variants-only profile still builds a usable backend rather than throwing.
     *
     * @param array<string, mixed> $profile
     * @param array<string, mixed> $asset
     */
    private static function buildLocal(
        array $profile,
        array $asset,
        VariantRegistry $variants,
        ImageResizer $resizer,
        string $defaultRootPath,
    ): LocalFilesystem {
        return new LocalFilesystem(
            rootPath:   self::resolveLocalRoot($profile, $asset, $defaultRootPath),
            publicPath: (string) ($profile['publicPath'] ?? $asset['public_path'] ?? ''),
            variants:   $variants,
            resizer:    $resizer,
        );
    }

    /**
     * Resolve the local backend root: an explicit profile `rootPath` wins; then
     * an absolute `asset.root_path`; otherwise the caller's default root. A
     * relative `asset.root_path` (e.g. "public") is the front-end's
     * site-relative form — already resolved by the caller and passed in as
     * $defaultRootPath — so it is not joined here.
     *
     * @param array<string, mixed> $profile
     * @param array<string, mixed> $asset
     */
    private static function resolveLocalRoot(array $profile, array $asset, string $defaultRootPath): string
    {
        $explicit = (string) ($profile['rootPath'] ?? '');
        if ($explicit !== '') {
            return $explicit;
        }

        $assetRoot = (string) ($asset['root_path'] ?? '');
        if ($assetRoot !== '' && str_starts_with($assetRoot, '/')) {
            return $assetRoot;
        }

        return $defaultRootPath;
    }

    /**
     * @param array<string, mixed> $profile
     */
    private static function buildS3(
        array $profile,
        VariantRegistry $variants,
        ImageResizer $resizer,
    ): S3 {
        return new S3(
            fs:            self::buildFlysystem($profile),
            publicUrlBase: self::requireString($profile, 'publicUrl'),
            variants:      $variants,
            resizer:       $resizer,
        );
    }

    /**
     * @param array<string, mixed> $profile
     */
    private static function buildCloudflareImages(
        string $profileName,
        array $profile,
        VariantRegistry $variants,
        ImageResizer $resizer,
    ): CloudflareImages {
        // The wrapped object store doesn't pre-generate variants — they resolve
        // through Cloudflare URL transforms at url() time.
        $inner = new S3(
            fs:            self::buildFlysystem($profile),
            publicUrlBase: self::requireString($profile, 'publicUrl'),
            variants:      new VariantRegistry(),
            resizer:       $resizer,
        );

        return new CloudflareImages(
            objectStore:     $inner,
            deliveryBaseUrl: self::requireString($profile, 'deliveryBaseUrl'),
            variants:        $variants,
        );
    }

    /**
     * @param array<string, mixed> $profile
     */
    private static function buildFlysystem(array $profile): Filesystem
    {
        $client = new S3Client([
            'version'                 => 'latest',
            'region'                  => (string) ($profile['region'] ?? 'auto'),
            'endpoint'                => self::requireString($profile, 'endpoint'),
            'credentials'             => [
                'key'    => self::requireString($profile, 'key'),
                'secret' => self::requireString($profile, 'secret'),
            ],
            'use_path_style_endpoint' => (bool) ($profile['usePathStyleEndpoint'] ?? false),
        ]);

        return new Filesystem(new AwsS3V3Adapter(
            $client,
            self::requireString($profile, 'bucket'),
        ));
    }

    private static function buildVariants(mixed $variantsConfig): VariantRegistry
    {
        if (! is_array($variantsConfig)) {
            return new VariantRegistry();
        }

        $variants = [];
        foreach ($variantsConfig as $name => $variant) {
            if (! is_array($variant)) {
                throw new InvalidArgumentException(sprintf('Variant "%s" must be an array.', $name));
            }

            // Art-directed profile: a `dimensions` ladder expands to one Variant
            // per rung ("<name>-<width>"). Otherwise it is a single flat variant
            // declared with explicit width/height (legacy form).
            if (isset($variant['dimensions'])) {
                foreach (VariantProfile::fromArray((string) $name, $variant)->variants as $expanded) {
                    $variants[] = $expanded;
                }
                continue;
            }

            $formats = [];
            if (isset($variant['formats']) && is_array($variant['formats'])) {
                foreach ($variant['formats'] as $f) {
                    $formats[] = strtolower(trim((string) $f, " \t\n\r\0\x0B."));
                }
            }

            $variants[] = new Variant(
                name:    (string) $name,
                width:   (int) self::requireString($variant, 'width'),
                height:  (int) self::requireString($variant, 'height'),
                fit:     self::parseFit((string) ($variant['fit'] ?? 'contain')),
                formats: $formats,
                quality: isset($variant['quality']) ? (int) $variant['quality'] : null,
            );
        }

        return new VariantRegistry(...$variants);
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
