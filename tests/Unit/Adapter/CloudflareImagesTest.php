<?php

declare(strict_types=1);

namespace Contenir\Storage\Tests\Unit\Adapter;

use InvalidArgumentException;
use Contenir\Storage\Entry;
use Contenir\Storage\Exception\NotFoundException;
use Contenir\Storage\ListOptions;
use Contenir\Storage\Adapter\CloudflareImages;
use Contenir\Storage\UploadInput;
use Contenir\Storage\Variant;
use Contenir\Storage\VariantFit;
use Contenir\Storage\VariantRegistry;
use Contenir\Storage\Tests\TestAsset\InMemoryStorage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
#[Group('storage')]
final class CloudflareImagesTest extends TestCase
{
    public function testUrlWithoutVariantDelegatesToObjectStore(): void
    {
        $inner = new InMemoryStorage();
        $inner->putFile('products/hero.jpg', 'data', 'image/jpeg');
        $backend = $this->backend($inner);

        self::assertSame('memory://products/hero.jpg', $backend->url('products/hero.jpg'));
    }

    public function testUrlWithVariantBuildsCloudflareTransformUrl(): void
    {
        $inner = new InMemoryStorage();
        $inner->putFile('products/hero.jpg', 'data', 'image/jpeg');
        $backend = $this->backend($inner, [
            new Variant('admin-thumb', 180, 180, VariantFit::Contain),
        ]);

        self::assertSame(
            'https://cdn.example.com/cdn-cgi/image/width=180,height=180,fit=contain/products/hero.jpg',
            $backend->url('products/hero.jpg', 'admin-thumb'),
        );
    }

    public function testUrlReturnsNullForMissingFileEvenWithVariant(): void
    {
        $backend = $this->backend(new InMemoryStorage(), [
            new Variant('admin-thumb', 180, 180),
        ]);

        self::assertNull($backend->url('does-not-exist.jpg', 'admin-thumb'));
    }

    public function testUrlThrowsForUnknownVariant(): void
    {
        $inner = new InMemoryStorage();
        $inner->putFile('a.jpg', 'data', 'image/jpeg');
        $backend = $this->backend($inner);

        $this->expectException(InvalidArgumentException::class);

        $backend->url('a.jpg', 'no-such-variant');
    }

    public function testUrlStripsLeadingSlashesAndDoubleSlashes(): void
    {
        $inner = new InMemoryStorage();
        $inner->putFile('products/hero.jpg', 'data', 'image/jpeg');
        $backend = $this->backend($inner, [new Variant('thumb', 100, 100, VariantFit::Contain)]);

        self::assertSame(
            'https://cdn.example.com/cdn-cgi/image/width=100,height=100,fit=contain/products/hero.jpg',
            $backend->url('products/hero.jpg', 'thumb'),
        );
    }

    #[DataProvider('fitParamProvider')]
    public function testFitMapsToCloudflareParam(VariantFit $fit, string $expected): void
    {
        $inner = new InMemoryStorage();
        $inner->putFile('a.jpg', 'data', 'image/jpeg');
        $backend = $this->backend($inner, [new Variant('v', 100, 100, $fit)]);

        $url = $backend->url('a.jpg', 'v');

        self::assertNotNull($url);
        self::assertStringContainsString('fit=' . $expected . '/', $url);
    }

    /** @return array<string, array{0: VariantFit, 1: string}> */
    public static function fitParamProvider(): array
    {
        return [
            'Cover → cover'     => [VariantFit::Cover, 'cover'],
            'Contain → contain' => [VariantFit::Contain, 'contain'],
            'Fill → crop'       => [VariantFit::Fill, 'crop'],
        ];
    }

    public function testStoreDelegatesToObjectStoreUnchanged(): void
    {
        $tmp = sys_get_temp_dir() . '/cf_images_test_' . uniqid('', true);
        file_put_contents($tmp, 'data');

        try {
            $inner   = new InMemoryStorage();
            $backend = $this->backend($inner);

            $entry = $backend->store(new UploadInput($tmp, 'note.txt', 'text/plain'), 'docs');

            self::assertInstanceOf(Entry::class, $entry);
            self::assertTrue($inner->exists('docs/note.txt'));
        } finally {
            @unlink($tmp);
        }
    }

    public function testListDelegatesToObjectStore(): void
    {
        $inner = new InMemoryStorage();
        $inner->putFile('docs/a.txt', 'a');
        $inner->putFile('docs/b.txt', 'b');
        $backend = $this->backend($inner);

        $entries = iterator_to_array($this->iter($backend->list('docs')));
        $names   = array_map(static fn (Entry $e): string => $e->name, $entries);
        sort($names);

        self::assertSame(['a.txt', 'b.txt'], $names);
    }

    public function testDeleteDelegatesToObjectStore(): void
    {
        $inner = new InMemoryStorage();
        $inner->putFile('a.txt', 'data');
        $backend = $this->backend($inner);

        $backend->delete('a.txt');

        self::assertFalse($inner->exists('a.txt'));
    }

    public function testDeleteThrowsForMissingFile(): void
    {
        $backend = $this->backend(new InMemoryStorage());

        $this->expectException(NotFoundException::class);

        $backend->delete('nope.txt');
    }

    public function testRenameDelegatesToObjectStore(): void
    {
        $inner = new InMemoryStorage();
        $inner->putFile('a.txt', 'data');
        $backend = $this->backend($inner);

        $backend->rename('a.txt', 'renamed.txt');

        self::assertFalse($inner->exists('a.txt'));
        self::assertTrue($inner->exists('renamed.txt'));
    }

    public function testExistsDelegatesToObjectStore(): void
    {
        $inner = new InMemoryStorage();
        $inner->putFile('a.txt', 'data');
        $backend = $this->backend($inner);

        self::assertTrue($backend->exists('a.txt'));
        self::assertFalse($backend->exists('nope.txt'));
    }

    public function testImageMetaDelegatesToObjectStore(): void
    {
        $inner = new InMemoryStorage();
        $inner->putFile('a.png', 'bytes', 'image/png', 100, 50);
        $backend = $this->backend($inner);

        $meta = $backend->imageMeta('a.png');

        self::assertSame(100, $meta->width);
        self::assertSame(50, $meta->height);
    }

    public function testListPassesOptionsThroughToObjectStore(): void
    {
        $inner = new InMemoryStorage();
        $inner->putFile('docs/report.pdf', 'r');
        $inner->putFile('docs/notes.txt', 'n');
        $backend = $this->backend($inner);

        $entries = iterator_to_array($this->iter(
            $backend->list('docs', new ListOptions(keyword: 'report')),
        ));

        self::assertCount(1, $entries);
        self::assertSame('report.pdf', $entries[0]->name);
    }

    /**
     * @param list<Variant> $variants
     */
    private function backend(InMemoryStorage $inner, array $variants = []): CloudflareImages
    {
        return new CloudflareImages(
            objectStore:     $inner,
            deliveryBaseUrl: 'https://cdn.example.com',
            variants:        new VariantRegistry(...$variants),
        );
    }

    /**
     * @param iterable<Entry> $iterable
     * @return \Generator<int, Entry>
     */
    private function iter(iterable $iterable): \Generator
    {
        foreach ($iterable as $key => $value) {
            yield $key => $value;
        }
    }
}
