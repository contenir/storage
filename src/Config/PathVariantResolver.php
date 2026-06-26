<?php

declare(strict_types=1);

namespace Contenir\Storage\Config;

/**
 * Resolves which variant families a stored path owns.
 *
 * The `storage.paths` map keys variant ownership by base path. An asset stored
 * below a base path (e.g. `/asset/library/news/lg/photo.jpg` under the base
 * `/asset/library/news/lg`) inherits that path's variants by longest-prefix
 * match at a segment boundary. The `'*'` wildcard entry declares families owned
 * by every path (e.g. `'*' => ['admin-thumb']` for the CMS preview).
 *
 * One instance is the single source consulted by both generation (which variants
 * to create for a key) and render-time validation (whether a requested variant
 * is allowed for a path).
 */
final readonly class PathVariantResolver
{
    /** Path key declaring families owned by every path. */
    public const WILDCARD = '*';

    /** @var array<string, list<string>> Normalised base path => owned family names. */
    private array $paths;

    /** @var list<string> Families owned by every path (the '*' wildcard entry). */
    private array $universal;

    /**
     * @param array<string, list<string>> $paths Base path => family names it owns.
     *                                            The '*' key declares families
     *                                            owned by every path.
     */
    public function __construct(array $paths)
    {
        $universal  = array_values($paths[self::WILDCARD] ?? []);
        $normalised = [];
        foreach ($paths as $base => $families) {
            if ((string) $base === self::WILDCARD) {
                continue;
            }
            $normalised[self::normalise((string) $base)] = array_values($families);
        }

        $this->paths     = $normalised;
        $this->universal = array_values(array_unique($universal));
    }

    /**
     * Family names owned by $path: the longest base entry that matches at a
     * segment boundary, unioned with the universal set. No match → universal only.
     *
     * @return list<string>
     */
    public function familiesFor(string $path): array
    {
        $path = self::normalise($path);

        $bestKey = null;
        foreach ($this->paths as $base => $families) {
            if ($path !== $base && ! str_starts_with($path, $base . '/')) {
                continue;
            }
            if ($bestKey === null || strlen($base) > strlen($bestKey)) {
                $bestKey = $base;
            }
        }

        $owned = $bestKey === null ? [] : $this->paths[$bestKey];

        return array_values(array_unique([...$owned, ...$this->universal]));
    }

    /**
     * Whether $path owns the family behind $variant. $variant may be a bare
     * family (`gallery`), a compiled rung (`gallery-480`), or a universal
     * variant (`admin-thumb`).
     */
    public function allows(string $path, string $variant): bool
    {
        return in_array(self::family($variant), $this->familiesFor($path), true);
    }

    /**
     * Whether any ownership is declared at all (a concrete path or the '*'
     * wildcard). When false the map is unconfigured, so callers should treat
     * every variant as permitted rather than enforce an empty map.
     */
    public function isConfigured(): bool
    {
        return $this->paths !== [] || $this->universal !== [];
    }

    /**
     * Map a variant name to its owning family: a compiled rung `<family>-<width>`
     * or `<family>-x<height>` strips to `<family>`; anything else (a bare family,
     * or a flat variant like `admin-thumb`) is already its own family.
     */
    public static function family(string $variant): string
    {
        return preg_replace('/-(?:\d+|x\d+)$/', '', $variant) ?? $variant;
    }

    private static function normalise(string $path): string
    {
        $path = '/' . ltrim($path, '/');

        return $path === '/' ? '/' : rtrim($path, '/');
    }
}
