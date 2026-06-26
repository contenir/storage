<?php

declare(strict_types=1);

namespace Contenir\Storage\Tests\Unit\Config;

use Contenir\Storage\Config\PathVariantResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
#[Group('storage')]
final class PathVariantResolverTest extends TestCase
{
    private const PATHS = [
        '*'                           => ['admin-thumb'],
        '/asset/library/news/lg'      => ['gallery'],
        '/asset/library/news/sm'      => ['tile'],
        '/asset/library/news/lg/hero' => ['gallery', 'mark'],
        '/asset/library/footer/lg'    => ['mark'],
    ];

    private static function resolver(): PathVariantResolver
    {
        return new PathVariantResolver(self::PATHS);
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('familyCases')]
    public function testFamiliesForResolvesOwnership(string $path, array $expected): void
    {
        self::assertSame($expected, self::resolver()->familiesFor($path));
    }

    /**
     * @return array<string, array{0: string, 1: list<string>}>
     */
    public static function familyCases(): array
    {
        return [
            'exact base'       => ['/asset/library/news/lg', ['gallery', 'admin-thumb']],
            'nested asset'     => ['/asset/library/news/lg/photo.jpg', ['gallery', 'admin-thumb']],
            'deeply nested'    => ['/asset/library/news/lg/2026/photo.jpg', ['gallery', 'admin-thumb']],
            'longest prefix'   => ['/asset/library/news/lg/hero/x.jpg', ['gallery', 'mark', 'admin-thumb']],
            'sibling base'     => ['/asset/library/news/sm/thumb.jpg', ['tile', 'admin-thumb']],
            'unmapped path'    => ['/asset/library/slide/lg/x.jpg', ['admin-thumb']],
            'no leading slash' => ['asset/library/footer/lg/logo.png', ['mark', 'admin-thumb']],
        ];
    }

    public function testSegmentBoundaryDoesNotMatchSiblingPrefix(): void
    {
        $resolver = new PathVariantResolver([
            '*'                   => ['admin-thumb'],
            '/asset/library/news' => ['gallery'],
        ]);

        // "news-archive" must NOT match the "news" base on a raw string prefix.
        self::assertSame(['admin-thumb'], $resolver->familiesFor('/asset/library/news-archive/x.jpg'));
        self::assertSame(['gallery', 'admin-thumb'], $resolver->familiesFor('/asset/library/news/x.jpg'));
    }

    /**
     * @param non-empty-string $variant
     */
    #[DataProvider('familyNameCases')]
    public function testFamilyMapsVariantToOwningFamily(string $variant, string $expected): void
    {
        self::assertSame($expected, PathVariantResolver::family($variant));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function familyNameCases(): array
    {
        return [
            'bare family'        => ['gallery', 'gallery'],
            'width rung'         => ['gallery-480', 'gallery'],
            'large width rung'   => ['gallery-1600', 'gallery'],
            'auto-height rung'   => ['gallery-x960', 'gallery'],
            'flat preview variant' => ['admin-thumb', 'admin-thumb'],
        ];
    }

    public function testIsConfiguredReflectsWhetherAnyOwnershipIsDeclared(): void
    {
        self::assertFalse((new PathVariantResolver([]))->isConfigured());
        self::assertTrue((new PathVariantResolver(['*' => ['admin-thumb']]))->isConfigured());
        self::assertTrue((new PathVariantResolver(['/asset/x' => ['gallery']]))->isConfigured());
    }

    public function testAllowsAcceptsFamilyRungAndUniversal(): void
    {
        $resolver = self::resolver();
        $path     = '/asset/library/news/lg/photo.jpg';

        self::assertTrue($resolver->allows($path, 'gallery'));      // bare family
        self::assertTrue($resolver->allows($path, 'gallery-480'));  // compiled rung
        self::assertTrue($resolver->allows($path, 'admin-thumb'));  // universal
        self::assertFalse($resolver->allows($path, 'tile'));        // not owned here
        self::assertFalse($resolver->allows($path, 'mark-160'));    // family not owned here
    }
}
