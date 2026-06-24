<?php

declare(strict_types=1);

namespace Contenir\Storage\Tests\Unit\Config;

use Contenir\Storage\Config\VariantProfile;
use Contenir\Storage\VariantFit;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
#[Group('storage')]
final class VariantProfileTest extends TestCase
{
    public function testExpandsCoverLadderToWidthNamedVariants(): void
    {
        $profile = VariantProfile::fromArray('card', [
            'fit'        => 'cover',
            'quality'    => 75,
            'formats'    => ['avif', 'webp'],
            'sizes'      => '(min-width: 1024px) 33vw, 50vw, 100vw',
            'dimensions' => ['320x320', '480x480', '600x600', '768x768'],
        ]);

        self::assertSame('card', $profile->name);
        self::assertSame('(min-width: 1024px) 33vw, 50vw, 100vw', $profile->sizes);
        self::assertFalse($profile->isPreview);
        self::assertCount(4, $profile->variants);

        $first = $profile->variants[0];
        self::assertSame('card-320', $first->name);
        self::assertSame(320, $first->width);
        self::assertSame(320, $first->height);
        self::assertSame(VariantFit::Cover, $first->fit);
        self::assertSame(['avif', 'webp'], $first->formats);
        self::assertSame(75, $first->quality);

        self::assertSame(
            ['card-320', 'card-480', 'card-600', 'card-768'],
            array_map(static fn ($v): string => $v->name, $profile->variants),
        );
    }

    public function testAutoHeightContainLadderKeepsHeightZeroAndWidthName(): void
    {
        $profile = VariantProfile::fromArray('image', [
            'fit'        => 'contain',
            'dimensions' => ['320x', '1920x'],
        ]);

        self::assertSame(['image-320', 'image-1920'], array_map(
            static fn ($v): string => $v->name,
            $profile->variants,
        ));
        self::assertSame(320, $profile->variants[0]->width);
        self::assertSame(0, $profile->variants[0]->height);
        self::assertSame(VariantFit::Contain, $profile->variants[0]->fit);
    }

    public function testPerRungOverrideWinsOverProfileDefaults(): void
    {
        $profile = VariantProfile::fromArray('hero', [
            'fit'        => 'contain',
            'quality'    => 80,
            'formats'    => ['avif'],
            'dimensions' => [
                '480x360',
                '1280x960' => ['fit' => 'cover', 'quality' => 70, 'formats' => ['webp']],
            ],
        ]);

        $base     = $profile->variants[0];
        $override = $profile->variants[1];

        self::assertSame(VariantFit::Contain, $base->fit);
        self::assertSame(80, $base->quality);
        self::assertSame(['avif'], $base->formats);

        self::assertSame('hero-1280', $override->name);
        self::assertSame(VariantFit::Cover, $override->fit);
        self::assertSame(70, $override->quality);
        self::assertSame(['webp'], $override->formats);
    }

    public function testPreviewRoleIsFlaggedAndExcludedFromWidthRule(): void
    {
        $profile = VariantProfile::fromArray('admin-thumb', [
            'fit'        => 'contain',
            'role'       => 'preview',
            'dimensions' => ['180x180'],
        ]);

        self::assertTrue($profile->isPreview);
        self::assertSame('admin-thumb-180', $profile->variants[0]->name);
    }

    public function testQualityDefaultsToNullWhenUnset(): void
    {
        $profile = VariantProfile::fromArray('plain', ['dimensions' => ['100x100']]);

        self::assertNull($profile->variants[0]->quality);
        self::assertSame([], $profile->variants[0]->formats);
    }

    public function testCoverWithAutoAxisThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('requires both a width and a height');

        VariantProfile::fromArray('card', ['fit' => 'cover', 'dimensions' => ['480x']]);
    }

    public function testFrontEndProfileRejectsWidthlessRung(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('needs a width for the srcset descriptor');

        VariantProfile::fromArray('hero', ['fit' => 'contain', 'dimensions' => ['x600']]);
    }

    public function testDuplicateExpandedNameThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('more than once');

        VariantProfile::fromArray('card', [
            'fit'        => 'cover',
            'dimensions' => ['320x320', '320x480'],
        ]);
    }

    /**
     * @param list<string> $dimensions
     */
    #[DataProvider('malformedConfigProvider')]
    public function testRejectsMalformedDeclarations(array $config, string $expectedMessage): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($expectedMessage);

        VariantProfile::fromArray('p', $config);
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function malformedConfigProvider(): array
    {
        return [
            'no dimensions'      => [['fit' => 'cover'], 'non-empty "dimensions" ladder'],
            'empty dimensions'   => [['dimensions' => []], 'non-empty "dimensions" ladder'],
            'bad dimension form' => [['dimensions' => ['48Ox360']], 'must be <width>x<height>'],
            'zero both axes'     => [['dimensions' => ['x']], 'at least one of width or height'],
            'unknown fit'        => [['fit' => 'squish', 'dimensions' => ['10x10']], 'unknown fit "squish"'],
        ];
    }
}
