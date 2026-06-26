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
     * Backend key for the implicit local backend when none is declared.
     */
    public const DEFAULT_PROFILE = 'local';

    /** @var array<string, StorageInterface> */
    private array $backends = [];

    /** Key of the primary backend: holds originals + variants that don't pin their own. */
    private ?string $primary = null;

    public function register(string $profile, StorageInterface $backend, bool $isPrimary = false): void
    {
        if ($profile === '') {
            throw new InvalidArgumentException('Storage profile name cannot be empty.');
        }
        if (isset($this->backends[$profile])) {
            throw new InvalidArgumentException(sprintf('Storage profile "%s" is already registered.', $profile));
        }
        $this->backends[$profile] = $backend;

        if ($isPrimary) {
            $this->primary = $profile;
        }
        // Fall back to the first registered backend if none is explicitly primary.
        $this->primary ??= $profile;
    }

    /** @throws InvalidArgumentException If no backend is registered. */
    public function primaryKey(): string
    {
        return $this->primary
            ?? throw new InvalidArgumentException('No storage backend registered.');
    }

    /** @throws InvalidArgumentException If no backend is registered. */
    public function primary(): StorageInterface
    {
        return $this->get($this->primaryKey());
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
