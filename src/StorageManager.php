<?php

declare(strict_types=1);

namespace Contenir\Storage;

use InvalidArgumentException;

/**
 * Registry mapping profile names (e.g. "local", "r2-cdn") to backend
 * instances. Field XML config declares a profile name; controllers and view
 * helpers resolve to a backend through this manager.
 *
 * The first registration for a given profile wins; re-registering the same
 * profile name throws so accidental shadowing fails loudly.
 */
final class StorageManager
{
    /**
     * Profile name used when a field config omits the optional <storage> element.
     */
    public const DEFAULT_PROFILE = 'local';

    /** @var array<string, StorageInterface> */
    private array $backends = [];

    public function register(string $profile, StorageInterface $backend): void
    {
        if ($profile === '') {
            throw new InvalidArgumentException('Storage profile name cannot be empty.');
        }
        if (isset($this->backends[$profile])) {
            throw new InvalidArgumentException(sprintf('Storage profile "%s" is already registered.', $profile));
        }
        $this->backends[$profile] = $backend;
    }

    /** @throws InvalidArgumentException If $profile is not registered. */
    public function get(string $profile): StorageInterface
    {
        return $this->backends[$profile]
            ?? throw new InvalidArgumentException(sprintf('Unknown storage profile "%s".', $profile));
    }

    public function has(string $profile): bool
    {
        return isset($this->backends[$profile]);
    }

    /** @return list<string> */
    public function profiles(): array
    {
        return array_keys($this->backends);
    }
}
