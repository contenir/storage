<?php

declare(strict_types=1);

namespace Contenir\Storage\Tests\Unit;

use Contenir\Storage\UploadInput;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
#[Group('storage')]
final class UploadInputTest extends TestCase
{
    public function testFromFilesArrayMapsCorePhpUploadKeys(): void
    {
        $input = UploadInput::fromFilesArray([
            'name'     => 'photo.jpg',
            'tmp_name' => '/tmp/php1234',
            'type'     => 'image/jpeg',
            'size'     => 12345,
            'error'    => 0,
        ]);

        self::assertSame('/tmp/php1234', $input->sourcePath);
        self::assertSame('photo.jpg', $input->clientFilename);
        self::assertSame('image/jpeg', $input->clientMime);
    }

    public function testFromFilesArrayDefaultsMimeToNullWhenMissing(): void
    {
        $input = UploadInput::fromFilesArray([
            'name'     => 'doc.txt',
            'tmp_name' => '/tmp/up',
        ]);

        self::assertNull($input->clientMime);
    }

    public function testFromFilesArrayCoercesEmptyStringsForMissingPaths(): void
    {
        $input = UploadInput::fromFilesArray([]);

        self::assertSame('', $input->sourcePath);
        self::assertSame('', $input->clientFilename);
        self::assertNull($input->clientMime);
    }
}
