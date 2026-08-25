<?php

declare(strict_types=1);

namespace Contenir\Storage\Tests\Unit;

use Contenir\Storage\Config\PathVariantResolver;
use InvalidArgumentException;
use Contenir\Storage\Variant;
use Contenir\Storage\VariantFit;
use Contenir\Storage\VariantRegistry;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
#[Group('storage')]
final class VariantRegistryTest extends TestCase
{
    public function testHasReturnsTrueForRegisteredVariant(): void
    {
        $registry = new VariantRegistry(new Variant('thumb', 100, 100));

        self::assertTrue($registry->has('thumb'));
    }

    public function testHasReturnsFalseForUnregisteredVariant(): void
    {
        $registry = new VariantRegistry(new Variant('thumb', 100, 100));

        self::assertFalse($registry->has('hero'));
    }

    public function testGetReturnsRegisteredVariant(): void
    {
        $variant  = new Variant('thumb', 100, 100, VariantFit::Cover);
        $registry = new VariantRegistry($variant);

        self::assertSame($variant, $registry->get('thumb'));
    }

    public function testGetThrowsForUnknownVariant(): void
    {
        $registry = new VariantRegistry();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown variant "thumb"');

        $registry->get('thumb');
    }

    public function testAllReturnsAllRegisteredVariants(): void
    {
        $thumb = new Variant('thumb', 100, 100);
        $hero  = new Variant('hero', 1200, 600);

        $registry = new VariantRegistry($thumb, $hero);

        self::assertSame([$thumb, $hero], $registry->all());
    }

    public function testEmptyRegistryHasNoVariants(): void
    {
        $registry = new VariantRegistry();

        self::assertSame([], $registry->all());
    }

    public function testAllowedForReturnsEveryVariantWhenNoResolverIsGiven(): void
    {
        $registry = new VariantRegistry(new Variant('thumb', 100, 100), new Variant('hero', 900, 600));

        self::assertCount(2, $registry->allowedFor(null, '/asset/library/news/lg/x.png'));
    }

    public function testAllowedForReturnsEveryVariantWhenOwnershipIsUndeclared(): void
    {
        // An empty map means nothing is declared, so enforcing it would strip
        // every variant; the permissive default matches the render-time guard.
        $registry = new VariantRegistry(new Variant('thumb', 100, 100), new Variant('hero', 900, 600));

        self::assertCount(2, $registry->allowedFor(new PathVariantResolver([]), '/asset/library/news/lg/x.png'));
    }

    public function testAllowedForKeepsOnlyTheFamiliesThePathOwns(): void
    {
        $registry = new VariantRegistry(
            new Variant('tile-480', 480, 480),
            new Variant('mark-240', 240, 240),
            new Variant('admin-thumb', 180, 180),
        );
        $paths = new PathVariantResolver([
            '*'                      => ['admin-thumb'],
            '/asset/library/news/lg' => ['tile'],
        ]);

        $names = array_map(
            static fn (Variant $variant): string => $variant->name,
            $registry->allowedFor($paths, '/asset/library/news/lg/photo.jpg'),
        );

        self::assertSame(['tile-480', 'admin-thumb'], $names);
    }

    public function testAllowedForGivesAnUndeclaredPathOnlyTheUniversalFamilies(): void
    {
        $registry = new VariantRegistry(new Variant('tile-480', 480, 480), new Variant('admin-thumb', 180, 180));
        $paths    = new PathVariantResolver([
            '*'                      => ['admin-thumb'],
            '/asset/library/news/lg' => ['tile'],
        ]);

        $names = array_map(
            static fn (Variant $variant): string => $variant->name,
            $registry->allowedFor($paths, '/asset/library/other/photo.jpg'),
        );

        self::assertSame(['admin-thumb'], $names);
    }
}
