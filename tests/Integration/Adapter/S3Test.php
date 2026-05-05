<?php

declare(strict_types=1);

namespace Contenir\Storage\Tests\Integration\Adapter;

use Aws\S3\S3Client;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use League\Flysystem\Filesystem;
use Contenir\Storage\Image\ImageResizer;
use Contenir\Storage\Adapter\S3;
use Contenir\Storage\UploadInput;
use Contenir\Storage\Variant;
use Contenir\Storage\VariantFit;
use Contenir\Storage\VariantRegistry;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Hits a real S3-compatible endpoint (R2, MinIO, AWS S3) configured via env vars:
 *   R2_TEST_ENDPOINT  — e.g. https://<account>.r2.cloudflarestorage.com
 *   R2_TEST_REGION    — e.g. auto (R2) or us-east-1 (AWS)
 *   R2_TEST_BUCKET    — bucket name
 *   R2_TEST_KEY       — access key
 *   R2_TEST_SECRET    — secret key
 *
 * Skips silently if any are absent so CI/local runs without credentials stay
 * green. Each test uses a unique key prefix to avoid cross-run interference.
 */
#[Group('integration')]
#[Group('storage')]
#[Group('s3')]
final class S3Test extends TestCase
{
    private string $tempDir;
    private string $keyPrefix;
    private S3 $backend;
    private Filesystem $fs;

    protected function setUp(): void
    {
        parent::setUp();

        $endpoint = getenv('R2_TEST_ENDPOINT');
        $bucket   = getenv('R2_TEST_BUCKET');
        $key      = getenv('R2_TEST_KEY');
        $secret   = getenv('R2_TEST_SECRET');
        $region   = getenv('R2_TEST_REGION') ?: 'auto';

        if (! $endpoint || ! $bucket || ! $key || ! $secret) {
            self::markTestSkipped('S3 integration test skipped: R2_TEST_* env vars not set.');
        }
        if (exec('which magick') === '' && exec('which convert') === '') {
            self::markTestSkipped('ImageMagick not installed.');
        }

        $client = new S3Client([
            'version'                 => 'latest',
            'region'                  => $region,
            'endpoint'                => $endpoint,
            'credentials'             => ['key' => $key, 'secret' => $secret],
            'use_path_style_endpoint' => true,
        ]);
        $adapter = new AwsS3V3Adapter($client, $bucket);
        $this->fs = new Filesystem($adapter);

        $this->keyPrefix = 'test-' . uniqid('', true);
        $this->backend = new S3(
            fs:            $this->fs,
            publicUrlBase: rtrim($endpoint, '/') . '/' . $bucket,
            variants:      new VariantRegistry(
                new Variant('admin-thumb', 180, 180, VariantFit::Contain),
            ),
            resizer:       new ImageResizer(),
        );

        $this->tempDir = sys_get_temp_dir() . '/s3_int_' . uniqid('', true);
        mkdir($this->tempDir, 0o777, true);
    }

    protected function tearDown(): void
    {
        if (isset($this->fs, $this->keyPrefix)) {
            try {
                $this->fs->deleteDirectory($this->keyPrefix);
            } catch (\Throwable $_e) {
                // best-effort cleanup
            }
        }

        if (isset($this->tempDir) && is_dir($this->tempDir)) {
            foreach (glob($this->tempDir . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($this->tempDir);
        }
        parent::tearDown();
    }

    public function testStoreUploadsToS3AndGeneratesVariant(): void
    {
        $source = $this->writePngFile('cat.png', 200, 200);

        $entry = $this->backend->store(
            new UploadInput($source, 'cat.png', 'image/png'),
            $this->keyPrefix . '/gallery',
        );

        self::assertSame('cat.png', $entry->name);
        self::assertTrue($this->backend->exists($entry->path));
        self::assertNotNull($this->backend->url($entry->path, 'admin-thumb'));
    }

    public function testDeleteRemovesObjectAndVariantFromS3(): void
    {
        $source = $this->writePngFile('a.png', 100, 100);
        $entry  = $this->backend->store(
            new UploadInput($source, 'a.png', 'image/png'),
            $this->keyPrefix . '/gallery',
        );

        $variantPath = $this->variantPath($entry->path);
        self::assertTrue($this->backend->exists($variantPath));

        $this->backend->delete($entry->path);

        self::assertFalse($this->backend->exists($entry->path));
        self::assertFalse($this->backend->exists($variantPath));
    }

    public function testRenameMovesObjectAndVariant(): void
    {
        $source = $this->writePngFile('a.png', 100, 100);
        $entry  = $this->backend->store(
            new UploadInput($source, 'a.png', 'image/png'),
            $this->keyPrefix . '/gallery',
        );

        $newPath = $this->keyPrefix . '/gallery/renamed.png';
        $this->backend->rename($entry->path, $newPath);

        self::assertFalse($this->backend->exists($entry->path));
        self::assertTrue($this->backend->exists($newPath));
        self::assertTrue($this->backend->exists($this->variantPath($newPath)));
    }

    private function variantPath(string $path): string
    {
        $dot = strrpos($path, '.');
        return substr($path, 0, $dot) . '__admin-thumb' . substr($path, $dot);
    }

    private function writePngFile(string $name, int $width, int $height): string
    {
        $path = $this->tempDir . '/' . $name;
        $img  = imagecreatetruecolor($width, $height);
        imagepng($img, $path);
        imagedestroy($img);
        return $path;
    }
}
