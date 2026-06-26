<?php

declare(strict_types=1);

namespace Contenir\Storage\Tests\Unit\Config;

use InvalidArgumentException;
use Contenir\Storage\Config\StorageConfig;
use Contenir\Storage\Adapter\CloudflareImages;
use Contenir\Storage\Adapter\LocalFilesystem;
use Contenir\Storage\Adapter\S3;
use Contenir\Storage\Image\StubImageResizer;
use Contenir\Storage\StorageManager;
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

    public function testNoBackendDeclaredBuildsImplicitLocalPrimary(): void
    {
        $manager = StorageConfig::fromArray([], $this->resizer, '/var/uploads');

        self::assertSame(['local'], $manager->profiles());
        self::assertSame('local', $manager->primaryKey());
        self::assertInstanceOf(LocalFilesystem::class, $manager->primary());
    }

    public function testNullConfigBuildsImplicitLocal(): void
    {
        $manager = StorageConfig::fromArray(null, $this->resizer, '/var/uploads');

        self::assertSame(['local'], $manager->profiles());
        self::assertSame('local', $manager->primaryKey());
    }

    public function testImplicitLocalRootIsTheDefaultRoot(): void
    {
        $manager = StorageConfig::fromArray([], $this->resizer, '/srv/site/public');

        self::assertSame('/srv/site/public/file.jpg', $manager->primary()->localPath('file.jpg'));
    }

    public function testDeclaredBackendWithoutDefaultLeavesLocalPrimary(): void
    {
        $manager = $this->build([
            'backend' => ['r2' => $this->s3Stub()],
        ]);

        // 'local' is pre-wired; without a default flag it stays primary.
        self::assertSame(['r2', 'local'], $manager->profiles());
        self::assertSame('local', $manager->primaryKey());
        self::assertInstanceOf(S3::class, $manager->get('r2'));
        self::assertInstanceOf(LocalFilesystem::class, $manager->get('local'));
    }

    public function testDefaultFlagPromotesBackendToPrimary(): void
    {
        $manager = $this->build([
            'backend' => ['r2' => $this->s3Stub() + ['default' => true]],
        ]);

        self::assertSame('r2', $manager->primaryKey());
        self::assertInstanceOf(S3::class, $manager->primary());
    }

    public function testDeclaredLocalBackendOverridesPrewiredRoot(): void
    {
        $manager = $this->build([
            'backend' => ['local' => ['type' => 'local', 'root_path' => '/custom/root']],
        ]);

        self::assertSame('/custom/root/file.jpg', $manager->get('local')->localPath('file.jpg'));
    }

    public function testLocalBackendRootPathOverridesDefaultRoot(): void
    {
        $manager = $this->build([
            'backend' => ['main' => ['type' => 'local', 'root_path' => '/explicit/root']],
        ]);

        self::assertSame('/explicit/root/file.jpg', $manager->get('main')->localPath('file.jpg'));
    }

    public function testCloudflareImagesBackendBuilds(): void
    {
        $manager = $this->build([
            'backend' => [
                'cf' => $this->s3Stub([
                    'type'            => 'cloudflare-images',
                    'deliveryBaseUrl' => 'https://cdn.example.com',
                ]),
            ],
        ]);

        self::assertInstanceOf(CloudflareImages::class, $manager->get('cf'));
    }

    public function testMultipleBackendsRequireExactlyOneDefault(): void
    {
        $manager = $this->build([
            'backend' => [
                'local' => ['type' => 'local'],
                'r2'    => $this->s3Stub() + ['default' => true],
            ],
        ]);

        self::assertSame(['local', 'r2'], $manager->profiles());
        self::assertSame('r2', $manager->primaryKey());
    }

    public function testMoreThanOneDefaultThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('At most one backend');

        $this->build([
            'backend' => [
                'a'  => ['type' => 'local', 'default' => true],
                'r2' => $this->s3Stub() + ['default' => true],
            ],
        ]);
    }

    public function testVariantsLandOnPrimaryAndOnPinnedBackend(): void
    {
        $manager = $this->build([
            'backend' => [
                'main' => ['type' => 'local', 'root_path' => '/a', 'default' => true],
                'side' => ['type' => 'local', 'root_path' => '/b'],
            ],
            'variants' => [
                'admin-thumb' => ['width' => 180, 'height' => 180, 'fit' => 'contain'],
                'card'        => ['width' => 600, 'height' => 400, 'fit' => 'cover', 'backend' => 'side'],
            ],
        ]);

        $main = $manager->get('main');
        $side = $manager->get('side');

        // admin-thumb (unpinned) → primary 'main'; card (pinned) → 'side'.
        self::assertNull($main->url('x.jpg', 'admin-thumb'));   // known on main, file missing
        self::assertNull($side->url('x.jpg', 'card'));          // known on side, file missing

        $this->expectException(InvalidArgumentException::class);
        $main->url('x.jpg', 'card');                            // not registered on main
    }

    public function testArtDirectedLadderExpandsToWidthNamedVariants(): void
    {
        $manager = $this->build([
            'variants' => [
                'card'        => ['fit' => 'cover', 'dimensions' => ['320x320', '480x480', '768x768']],
                'admin-thumb' => ['width' => 180, 'height' => 180, 'fit' => 'contain'],
            ],
        ]);

        $backend = $manager->primary();

        self::assertNull($backend->url('missing.jpg', 'card-320'));
        self::assertNull($backend->url('missing.jpg', 'card-768'));
        self::assertNull($backend->url('missing.jpg', 'admin-thumb'));

        // The family key itself is not a variant — only its expanded rungs are.
        $this->expectException(InvalidArgumentException::class);
        $backend->url('missing.jpg', 'card');
    }

    public function testVariantTargetingUnknownBackendThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('unknown backend');

        $this->build([
            'backend'  => ['main' => ['type' => 'local']],
            'variants' => ['card' => ['width' => 1, 'height' => 1, 'backend' => 'ghost']],
        ]);
    }

    public function testThrowsForUnknownBackendType(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('unknown type');

        $this->build([
            'backend' => ['weird' => ['type' => 'azure-blob']],
        ]);
    }

    public function testThrowsForS3MissingBucket(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('bucket');

        $this->build([
            'backend' => [
                'r2' => [
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
            'backend' => ['cf' => $this->s3Stub(['type' => 'cloudflare-images'])],
        ]);
    }

    public function testThrowsForUnknownVariantFit(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('fit');

        $this->build([
            'variants' => ['v' => ['width' => 100, 'height' => 100, 'fit' => 'squish']],
        ]);
    }

    public function testVariantNamesForBackendGroupsByAssignment(): void
    {
        $config = [
            'backend'  => ['r2' => $this->s3Stub() + ['default' => true]],
            'variants' => [
                'admin-thumb' => ['width' => 180, 'height' => 180],
                'gallery'     => ['dimensions' => ['320x']],
                'mark'        => ['width' => 160, 'height' => 160, 'backend' => 'local'],
            ],
        ];

        self::assertSame(['admin-thumb', 'gallery'], StorageConfig::variantNamesForBackend($config, 'r2'));
        self::assertSame(['mark'], StorageConfig::variantNamesForBackend($config, 'local'));
    }

    public function testVariantNamesForBackendUsesLocalPrimaryWhenNoDefault(): void
    {
        $config = ['variants' => ['gallery' => ['dimensions' => ['320x']]]];

        self::assertSame(['gallery'], StorageConfig::variantNamesForBackend($config, 'local'));
        self::assertSame([], StorageConfig::variantNamesForBackend($config, 'r2'));
    }

    public function testResolverFromArrayBuildsPathVariantResolver(): void
    {
        $resolver = StorageConfig::resolverFromArray([
            'paths' => [
                '*'                      => ['variants' => ['admin-thumb']],
                '/asset/library/news/lg' => ['variants' => ['gallery']],
            ],
        ]);

        self::assertSame(['gallery', 'admin-thumb'], $resolver->familiesFor('/asset/library/news/lg/x.jpg'));
        self::assertSame(['admin-thumb'], $resolver->familiesFor('/asset/library/slide/x.jpg'));
    }

    public function testResolverFromArrayHandlesMissingPaths(): void
    {
        $resolver = StorageConfig::resolverFromArray([]);

        self::assertSame([], $resolver->familiesFor('/anything.jpg'));
    }

    /**
     * @param array<string, mixed> $config
     */
    private function build(array $config): StorageManager
    {
        return StorageConfig::fromArray($config, $this->resizer, '/var/uploads');
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
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
