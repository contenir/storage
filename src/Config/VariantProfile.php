<?php

declare(strict_types=1);

namespace Contenir\Storage\Config;

use Contenir\Storage\Variant;
use Contenir\Storage\VariantFit;
use InvalidArgumentException;

/**
 * One art-directed image profile, declared in a single place.
 *
 * A profile (e.g. `card`, `hero`) carries a responsive `dimensions` ladder plus
 * the shared fit / quality / formats and the front-end `sizes` attribute. It
 * compiles to the flat {@see Variant} list the generator materialises AND
 * exposes the render config the front-end consumes — so the family is no longer
 * duplicated between the generation catalogue and the front-end profiles.
 *
 * Each ladder rung is a dimension string — `WxH`, `Wx` (auto height) or `xH`
 * (auto width, generator-only) — given as a bare value or a `"WxH" => overrides`
 * map entry. Expanded variant names are `"<profile>-<width>"` so existing
 * sibling keys are reproduced exactly (no regeneration).
 */
final class VariantProfile
{
    /**
     * @param list<Variant> $variants Expanded, in ladder order.
     */
    private function __construct(
        public readonly string $name,
        public readonly string $sizes,
        public readonly bool $isPreview,
        public readonly array $variants,
    ) {
    }

    /**
     * @param array<string, mixed> $config
     *
     * @throws InvalidArgumentException If the declaration is malformed.
     */
    public static function fromArray(string $name, array $config): self
    {
        $dimensions = $config['dimensions'] ?? null;
        if (! is_array($dimensions) || $dimensions === []) {
            throw new InvalidArgumentException(sprintf(
                'Profile "%s" must declare a non-empty "dimensions" ladder.',
                $name,
            ));
        }

        $defaultFit     = self::parseFit((string) ($config['fit'] ?? 'contain'), $name);
        $defaultQuality = isset($config['quality']) ? (int) $config['quality'] : null;
        $defaultFormats = self::parseFormats($config['formats'] ?? []);
        $sizes          = (string) ($config['sizes'] ?? '');
        $isPreview      = ($config['role'] ?? null) === 'preview';

        $variants = [];
        $seen     = [];
        foreach ($dimensions as $key => $value) {
            // Bare dimension string vs `"WxH" => [overrides]` map entry.
            if (is_string($value)) {
                $spec     = $value;
                $override = [];
            } else {
                $spec     = (string) $key;
                $override = is_array($value) ? $value : [];
            }

            [$width, $height] = self::parseDimension($spec, $name);
            $fit     = isset($override['fit'])
                ? self::parseFit((string) $override['fit'], $name)
                : $defaultFit;
            $quality = isset($override['quality']) ? (int) $override['quality'] : $defaultQuality;
            $formats = isset($override['formats']) ? self::parseFormats($override['formats']) : $defaultFormats;

            if ($fit !== VariantFit::Contain && ($width <= 0 || $height <= 0)) {
                throw new InvalidArgumentException(sprintf(
                    'Profile "%s" rung "%s" uses fit "%s", which requires both a width and a height.',
                    $name,
                    $spec,
                    $fit->value,
                ));
            }

            if (! $isPreview && $width <= 0) {
                throw new InvalidArgumentException(sprintf(
                    'Profile "%s" rung "%s" has no width; a front-end profile needs a width for the srcset descriptor.',
                    $name,
                    $spec,
                ));
            }

            $variantName = $name . '-' . ($width > 0 ? (string) $width : 'x' . $height);
            if (isset($seen[$variantName])) {
                throw new InvalidArgumentException(sprintf(
                    'Profile "%s" produces variant "%s" more than once; each rung must be unique.',
                    $name,
                    $variantName,
                ));
            }
            $seen[$variantName] = true;

            $variants[] = new Variant($variantName, $width, $height, $fit, $formats, $quality);
        }

        return new self($name, $sizes, $isPreview, $variants);
    }

    /**
     * @return array{0: int, 1: int}
     */
    private static function parseDimension(string $spec, string $name): array
    {
        if (! preg_match('/^(\d*)\s*x\s*(\d*)$/', trim($spec), $m)) {
            throw new InvalidArgumentException(sprintf(
                'Profile "%s" dimension "%s" must be <width>x<height>, <width>x or x<height>.',
                $name,
                $spec,
            ));
        }

        $width  = $m[1] === '' ? 0 : (int) $m[1];
        $height = $m[2] === '' ? 0 : (int) $m[2];
        if ($width <= 0 && $height <= 0) {
            throw new InvalidArgumentException(sprintf(
                'Profile "%s" dimension "%s" must set at least one of width or height.',
                $name,
                $spec,
            ));
        }

        return [$width, $height];
    }

    private static function parseFit(string $fit, string $name): VariantFit
    {
        return match (strtolower($fit)) {
            'cover'   => VariantFit::Cover,
            'contain' => VariantFit::Contain,
            'fill'    => VariantFit::Fill,
            default   => throw new InvalidArgumentException(sprintf(
                'Profile "%s" has unknown fit "%s" (expected: cover, contain, fill).',
                $name,
                $fit,
            )),
        };
    }

    /**
     * @return list<string>
     */
    private static function parseFormats(mixed $formats): array
    {
        if (! is_array($formats)) {
            return [];
        }

        $out = [];
        foreach ($formats as $format) {
            $out[] = strtolower(trim((string) $format, " \t\n\r\0\x0B."));
        }

        return $out;
    }
}
