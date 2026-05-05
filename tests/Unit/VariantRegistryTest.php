<?php

declare(strict_types=1);

namespace Contenir\Storage\Tests\Unit;

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
}
