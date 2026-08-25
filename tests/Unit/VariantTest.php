<?php

declare(strict_types=1);

namespace Contenir\Storage\Tests\Unit;

use Contenir\Storage\Variant;
use Contenir\Storage\VariantFit;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
#[Group('storage')]
final class VariantTest extends TestCase
{
    public function testTargetFormatsIsSourceOnlyWhenNoFormatsAreDeclared(): void
    {
        $variant = new Variant('thumb', 100, 100);

        self::assertSame([null], $variant->targetFormats());
    }

    public function testTargetFormatsAppendsTheSourceExtensionToDeclaredFormats(): void
    {
        // The <img> fallback resolves against the source extension, so it must
        // be materialised even when modern formats are declared.
        $variant = new Variant('card', 600, 600, VariantFit::Cover, ['avif', 'webp']);

        self::assertSame(['avif', 'webp', null], $variant->targetFormats());
    }

    public function testTargetFormatsKeepsADeclaredFormatFirstForRepresentativeUrls(): void
    {
        // StorageInterface::url() takes the first entry as the representative
        // format; it must stay a modern one rather than the source.
        $variant = new Variant('card', 600, 600, VariantFit::Cover, ['avif']);

        self::assertSame('avif', $variant->targetFormats()[0]);
    }
}
