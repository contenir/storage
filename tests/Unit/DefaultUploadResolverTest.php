<?php

declare(strict_types=1);

namespace Contenir\Storage\Tests\Unit;

use Contenir\Storage\DefaultUploadResolver;
use Contenir\Storage\Exception\InvalidPathException;
use Contenir\Storage\Exception\UnsupportedTypeException;
use Contenir\Storage\UploadInput;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

use function imagecreatetruecolor;
use function imagedestroy;
use function imagejpeg;
use function imagepng;
use function is_dir;
use function mkdir;
use function rmdir;
use function scandir;
use function file_put_contents;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

#[Group('unit')]
#[Group('storage')]
final class DefaultUploadResolverTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/resolver_' . uniqid('', true);
        mkdir($this->dir, 0o777, true);
    }

    protected function tearDown(): void
    {
        if (! is_dir($this->dir)) {
            return;
        }
        foreach (scandir($this->dir) as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                unlink($this->dir . '/' . $entry);
            }
        }
        rmdir($this->dir);
    }

    public function testExtensionFollowsDetectedTypeNotTheClientFilename(): void
    {
        $source = $this->makePng(40, 25);

        $resolved = (new DefaultUploadResolver())->resolve(
            new UploadInput($source, 'Holiday Photo.JPG', 'image/jpeg'),
        );

        self::assertSame('holiday-photo.png', $resolved->name);
        self::assertSame('image/png', $resolved->mime);
        self::assertNotNull($resolved->image);
        self::assertSame(40, $resolved->image->width);
        self::assertSame(25, $resolved->image->height);
    }

    public function testJpegResolvesToJpgExtension(): void
    {
        $resolved = (new DefaultUploadResolver())->resolve(
            new UploadInput($this->makeJpeg(), 'snap.bin', null),
        );

        self::assertSame('snap.jpg', $resolved->name);
        self::assertSame('image/jpeg', $resolved->mime);
    }

    public function testDotsAndUnsafeCharactersCollapseToASingleSlug(): void
    {
        $resolved = (new DefaultUploadResolver())->resolve(
            new UploadInput($this->makePng(), 'My.Cool   Photo!!.jpeg', null),
        );

        self::assertSame('my-cool-photo.png', $resolved->name);
    }

    public function testUnderscoresBecomeDashes(): void
    {
        $resolved = (new DefaultUploadResolver())->resolve(
            new UploadInput($this->makePng(), 'my_photo.png', null),
        );

        self::assertSame('my-photo.png', $resolved->name);
    }

    public function testNonImageStorableTypeResolvesWithoutDimensions(): void
    {
        $source = $this->dir . '/doc';
        file_put_contents($source, "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n");

        $resolved = (new DefaultUploadResolver())->resolve(
            new UploadInput($source, 'Report Final.png', 'image/png'),
        );

        self::assertSame('report-final.pdf', $resolved->name);
        self::assertSame('application/pdf', $resolved->mime);
        self::assertNull($resolved->image);
    }

    public function testRejectsDetectedTypeThatIsNotInTheAllowlist(): void
    {
        // Opaque binary bytes → finfo reports application/octet-stream, which is
        // not a storable type. The client's image/png claim is ignored.
        $source = $this->dir . '/blob';
        file_put_contents($source, "\x00\x01\x02\x03\xFF\xFE\xFD\xFC\x00\x10");

        $this->expectException(UnsupportedTypeException::class);

        (new DefaultUploadResolver())->resolve(new UploadInput($source, 'blob.png', 'image/png'));
    }

    public function testRejectsWhenClientFilenameHasNoSlugSafeCharacters(): void
    {
        $this->expectException(InvalidPathException::class);

        (new DefaultUploadResolver())->resolve(new UploadInput($this->makePng(), '@@@.png', null));
    }

    public function testRejectsUnreadableSource(): void
    {
        $this->expectException(UnsupportedTypeException::class);

        (new DefaultUploadResolver())->resolve(
            new UploadInput($this->dir . '/does-not-exist', 'whatever.png', 'image/png'),
        );
    }

    public function testInjectedExtensionMapIsTheAllowlist(): void
    {
        $resolver = new DefaultUploadResolver(['image/png' => 'png']);

        $this->expectException(UnsupportedTypeException::class);

        $resolver->resolve(new UploadInput($this->makeJpeg(), 'snap.jpg', null));
    }

    private function makePng(int $width = 10, int $height = 10): string
    {
        $path = $this->dir . '/' . uniqid('png', true) . '.bin';
        $img  = imagecreatetruecolor($width, $height);
        imagepng($img, $path);
        imagedestroy($img);

        return $path;
    }

    private function makeJpeg(int $width = 10, int $height = 10): string
    {
        $path = $this->dir . '/' . uniqid('jpg', true) . '.bin';
        $img  = imagecreatetruecolor($width, $height);
        imagejpeg($img, $path);
        imagedestroy($img);

        return $path;
    }
}
