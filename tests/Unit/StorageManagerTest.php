<?php

declare(strict_types=1);

namespace Contenir\Storage\Tests\Unit;

use InvalidArgumentException;
use Contenir\Storage\StorageInterface;
use Contenir\Storage\StorageManager;
use Contenir\Storage\Adapter\InMemoryStorage;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
#[Group('storage')]
final class StorageManagerTest extends TestCase
{
    public function testGetReturnsRegisteredBackend(): void
    {
        $backend = new InMemoryStorage();
        $manager = new StorageManager();

        $manager->register('local', $backend);

        self::assertSame($backend, $manager->get('local'));
    }

    public function testHasReportsRegistrationStatus(): void
    {
        $manager = new StorageManager();

        self::assertFalse($manager->has('local'));

        $manager->register('local', new InMemoryStorage());

        self::assertTrue($manager->has('local'));
    }

    public function testProfilesListsAllRegistered(): void
    {
        $manager = new StorageManager();
        $manager->register('local', new InMemoryStorage());
        $manager->register('r2-cdn', new InMemoryStorage());

        self::assertSame(['local', 'r2-cdn'], $manager->profiles());
    }

    public function testGetThrowsForUnknownProfile(): void
    {
        $manager = new StorageManager();

        $this->expectException(InvalidArgumentException::class);

        $manager->get('nope');
    }

    public function testRegisterRejectsEmptyProfileName(): void
    {
        $manager = new StorageManager();

        $this->expectException(InvalidArgumentException::class);

        $manager->register('', new InMemoryStorage());
    }

    public function testRegisterRejectsDuplicateProfileName(): void
    {
        $manager = new StorageManager();
        $manager->register('local', new InMemoryStorage());

        $this->expectException(InvalidArgumentException::class);

        $manager->register('local', new InMemoryStorage());
    }

    public function testRegisteredBackendImplementsContract(): void
    {
        $manager = new StorageManager();
        $manager->register('local', new InMemoryStorage());

        self::assertInstanceOf(StorageInterface::class, $manager->get('local'));
    }

    public function testDefaultProfileConstantIsLocal(): void
    {
        self::assertSame('local', StorageManager::DEFAULT_PROFILE);
    }
}
