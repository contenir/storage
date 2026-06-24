<?php

declare(strict_types=1);

namespace Contenir\Storage\Tests\Unit\Config;

use InvalidArgumentException;
use Contenir\Storage\Config\StorageConfig;
use Contenir\Storage\Adapter\CloudflareImages;
use Contenir\Storage\Adapter\LocalFilesystem;
use Contenir\Storage\Adapter\S3;
use Contenir\Storage\Image\StubImageResizer;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
#[Group('storage')]
final class StorageConfigTest extends TestCase
{
    private StubImageResizer $resizer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resizer = new StubImageResizer();
    }

    public function testDefaultBuildsLocalProfileWhenNoConfigSupplied(): void
    {
        $manager = StorageConfig::default($this->resizer, '/var/uploads');

        self::assertSame(['local'], $manager->profiles());
        self::assertInstanceOf(LocalFilesystem::class, $manager->get('local'));
    }

    public function testFromConfigFallsBackToDefaultWhenConfigIsNull(): void
    {
        $manager = StorageConfig::fromArray(null, $this->resizer, '/var/uploads');

        self::assertSame(['local'], $manager->profiles());
    }

    public function testFromConfigFallsBackToDefaultWhenProfilesMissing(): void
    {
        $manager = StorageConfig::fromArray([], $this->resizer, '/var/uploads');

        self::assertSame(['local'], $manager->profiles());
    }

    public function testBuildsLocalProfile(): void
    {
        $manager = $this->build([
            'profiles' => [
                'docs-local' => [
                    'type'       => 'local',
                    'rootPath'   => '/var/uploads',
                    'publicPath' => '/uploads',
                ],
            ],
        ]);

        self::assertSame(['docs-local'], $manager->profiles());
        self::assertInstanceOf(LocalFilesystem::class, $manager->get('docs-local'));
    }

    public function testBuildsS3Profile(): void
    {
        $manager = $this->build([
            'profiles' => [
                'r2-products' => [
                    'type'      => 's3',
                    'endpoint'  => 'https://abc.r2.cloudflarestorage.com',
                    'region'    => 'auto',
                    'bucket'    => 'products',
                    'key'       => 'AKIA...',
                    'secret'    => 'secret-...',
                    'publicUrl' => 'https://cdn.example.com',
                ],
            ],
        ]);

        self::assertInstanceOf(S3::class, $manager->get('r2-products'));
    }

    public function testBuildsCloudflareImagesProfile(): void
    {
        $manager = $this->build([
            'profiles' => [
                'r2-marketing' => [
                    'type'            => 'cloudflare-images',
                    'endpoint'        => 'https://abc.r2.cloudflarestorage.com',
                    'bucket'          => 'marketing',
                    'key'             => 'AKIA...',
                    'secret'          => 'secret-...',
                    'publicUrl'       => 'https://r2.example.com',
                    'deliveryBaseUrl' => 'https://cdn.example.com',
                ],
            ],
        ]);

        self::assertInstanceOf(CloudflareImages::class, $manager->get('r2-marketing'));
    }

    public function testBuildsMultipleProfilesAlongside(): void
    {
        $manager = $this->build([
            'profiles' => [
                'local'       => ['type' => 'local', 'rootPath' => '/var/uploads'],
                'r2-products' => $this->s3Stub(),
                'r2-archive'  => $this->s3Stub(['bucket' => 'archive']),
            ],
        ]);

        self::assertSame(['local', 'r2-products', 'r2-archive'], $manager->profiles());
    }

    public function testProfileVariantsAreRegisteredPerProfile(): void
    {
        $manager = $this->build([
            'profiles' => [
                'with-variants' => [
                    'type'     => 'local',
                    'rootPath' => '/var/uploads',
                    'variants' => [
                        'admin-thumb' => ['width' => 180, 'height' => 180, 'fit' => 'contain'],
                        'card'        => ['width' => 600, 'height' => 400, 'fit' => 'cover'],
                    ],
                ],
            ],
        ]);

        $backend = $manager->get('with-variants');
        // Url throws InvalidArgumentException for unknown variant, returns null for known-but-missing-file.
        // Exercising both proves the registry was built correctly.
        self::assertNull($backend->url('does-not-exist.jpg', 'admin-thumb'));
        self::assertNull($backend->url('does-not-exist.jpg', 'card'));

        $this->expectException(InvalidArgumentException::class);
        $backend->url('does-not-exist.jpg', 'no-such-variant');
    }

    public function testArtDirectedProfileExpandsToWidthNamedVariants(): void
    {
        $manager = $this->build([
            'profiles' => [
                'assets' => [
                    'type'     => 'local',
                    'rootPath' => '/var/uploads',
                    'variants' => [
                        // Grouped art-directed profile + a flat variant side by side.
                        'card'        => ['fit' => 'cover', 'dimensions' => ['320x320', '480x480', '768x768']],
                        'admin-thumb' => ['width' => 180, 'height' => 180, 'fit' => 'contain'],
                    ],
                ],
            ],
        ]);

        $backend = $manager->get('assets');

        // Expanded rungs and the flat variant are registered (null = known, file missing).
        self::assertNull($backend->url('missing.jpg', 'card-320'));
        self::assertNull($backend->url('missing.jpg', 'card-768'));
        self::assertNull($backend->url('missing.jpg', 'admin-thumb'));

        // The family key itself is not a variant — only its expanded rungs are.
        $this->expectException(InvalidArgumentException::class);
        $backend->url('missing.jpg', 'card');
    }

    public function testProfileWithoutVariantsRegistersEmptyRegistry(): void
    {
        $manager = $this->build([
            'profiles' => [
                'no-variants' => ['type' => 'local', 'rootPath' => '/var/uploads'],
            ],
        ]);

        $backend = $manager->get('no-variants');
        $this->expectException(InvalidArgumentException::class);
        $backend->url('does-not-exist.jpg', 'admin-thumb');
    }

    public function testThrowsForUnknownProfileType(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('unknown type');

        $this->build([
            'profiles' => [
                'weird' => ['type' => 'azure-blob', 'rootPath' => '/x'],
            ],
        ]);
    }

    public function testThrowsForLocalMissingRootPath(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('rootPath');

        $this->build([
            'profiles' => [
                'broken' => ['type' => 'local'],
            ],
        ]);
    }

    public function testThrowsForS3MissingBucket(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('bucket');

        $this->build([
            'profiles' => [
                'broken' => [
                    'type'      => 's3',
                    'endpoint'  => 'https://e.example.com',
                    'key'       => 'k',
                    'secret'    => 's',
                    'publicUrl' => 'https://cdn.example.com',
                ],
            ],
        ]);
    }

    public function testThrowsForCloudflareImagesMissingDeliveryUrl(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('deliveryBaseUrl');

        $this->build([
            'profiles' => [
                'broken' => $this->s3Stub(['type' => 'cloudflare-images']),
            ],
        ]);
    }

    public function testThrowsForUnknownVariantFit(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('fit');

        $this->build([
            'profiles' => [
                'p' => [
                    'type'     => 'local',
                    'rootPath' => '/x',
                    'variants' => [
                        'v' => ['width' => 100, 'height' => 100, 'fit' => 'squish'],
                    ],
                ],
            ],
        ]);
    }

    /** @param array<string, mixed> $config */
    private function build(array $config): \Contenir\Storage\StorageManager
    {
        return StorageConfig::fromArray($config, $this->resizer, '/var/uploads');
    }

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private function s3Stub(array $overrides = []): array
    {
        return array_merge([
            'type'      => 's3',
            'endpoint'  => 'https://e.example.com',
            'bucket'    => 'b',
            'key'       => 'k',
            'secret'    => 's',
            'publicUrl' => 'https://cdn.example.com',
        ], $overrides);
    }
}
