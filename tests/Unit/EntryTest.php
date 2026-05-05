<?php

declare(strict_types=1);

namespace Contenir\Storage\Tests\Unit;

use DateTimeImmutable;
use Contenir\Storage\Entry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
#[Group('storage')]
final class EntryTest extends TestCase
{
    public function testIsFileReturnsTrueForNonDirectory(): void
    {
        $entry = $this->makeEntry(isDir: false, mime: 'image/jpeg');

        self::assertTrue($entry->isFile());
    }

    public function testIsFileReturnsFalseForDirectory(): void
    {
        $entry = $this->makeEntry(isDir: true, mime: 'inode/directory');

        self::assertFalse($entry->isFile());
    }

    #[DataProvider('imageMimeProvider')]
    public function testIsImageReturnsTrueForImageMimeTypes(string $mime): void
    {
        $entry = $this->makeEntry(isDir: false, mime: $mime);

        self::assertTrue($entry->isImage());
    }

    /** @return array<string, array{0: string}> */
    public static function imageMimeProvider(): array
    {
        return [
            'jpeg'    => ['image/jpeg'],
            'png'     => ['image/png'],
            'gif'     => ['image/gif'],
            'webp'    => ['image/webp'],
            'svg+xml' => ['image/svg+xml'],
        ];
    }

    #[DataProvider('nonImageMimeProvider')]
    public function testIsImageReturnsFalseForNonImageMimeTypes(string $mime): void
    {
        $entry = $this->makeEntry(isDir: false, mime: $mime);

        self::assertFalse($entry->isImage());
    }

    /** @return array<string, array{0: string}> */
    public static function nonImageMimeProvider(): array
    {
        return [
            'plain text'      => ['text/plain'],
            'pdf'             => ['application/pdf'],
            'octet stream'    => ['application/octet-stream'],
            'html'            => ['text/html'],
        ];
    }

    public function testIsImageReturnsFalseForDirectoryEvenIfMimeIsImage(): void
    {
        $entry = $this->makeEntry(isDir: true, mime: 'image/jpeg');

        self::assertFalse($entry->isImage());
    }

    private function makeEntry(bool $isDir, string $mime): Entry
    {
        return new Entry(
            id: md5('foo'),
            name: 'foo',
            path: 'dir/foo',
            isDir: $isDir,
            size: 0,
            mtime: new DateTimeImmutable('2026-01-01'),
            mime: $mime,
        );
    }
}
