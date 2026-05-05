<?php

declare(strict_types=1);

namespace Contenir\Storage;

use InvalidArgumentException;

/**
 * Application-level catalogue of named variants known to storage backends.
 *
 * The registry is constructed once at boot and injected into backends so they
 * know which variants to materialise (local) or expose (cloud).
 */
final readonly class VariantRegistry
{
    /** @var array<string, Variant> */
    private array $byName;

    public function __construct(Variant ...$variants)
    {
        $byName = [];
        foreach ($variants as $variant) {
            $byName[$variant->name] = $variant;
        }
        $this->byName = $byName;
    }

    public function has(string $name): bool
    {
        return isset($this->byName[$name]);
    }

    /** @throws InvalidArgumentException If $name is not registered. */
    public function get(string $name): Variant
    {
        return $this->byName[$name]
            ?? throw new InvalidArgumentException(sprintf('Unknown variant "%s".', $name));
    }

    /** @return list<Variant> */
    public function all(): array
    {
        return array_values($this->byName);
    }
}
