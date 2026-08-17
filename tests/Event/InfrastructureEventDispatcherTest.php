<?php

declare(strict_types=1);

namespace CoolMS\Core\Doctrine\Tests\Event;

use CoolMS\Core\Doctrine\Event\InfrastructureEventDispatcher;
use CoolMS\Core\Lifecycle\OnCreateEvent;
use CoolMS\Core\Lifecycle\OnUpdateEvent;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Guards the Doctrine -> Tier-3 infrastructure event bridge that powers
 * BlameableEntityEventListener (createdBy/updatedBy) and TimestampableEntityEventListener
 * (timestamp refresh). Without this dispatcher, OnCreateEvent / OnUpdateEvent never fire
 * for ORM-persisted entities and downstream listeners stay dormant.
 */
final class InfrastructureEventDispatcherTest extends TestCase
{
    public function testPrePersistDispatchesOnCreateEventCarryingTheEntity(): void
    {
        $entity = new stdClass();
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects(self::once())
            ->method('dispatch')
            ->with(self::callback(static fn (object $event): bool => $event instanceof OnCreateEvent && $event->subject === $entity))
            ->willReturnArgument(0);

        $bridge = new InfrastructureEventDispatcher($dispatcher);
        $em = $this->createStub(EntityManagerInterface::class);
        $bridge->prePersist(new PrePersistEventArgs($entity, $em));
    }

    public function testPreUpdateDispatchesOnUpdateEventCarryingTheEntity(): void
    {
        $entity = new stdClass();
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects(self::once())
            ->method('dispatch')
            ->with(self::callback(static fn (object $event): bool => $event instanceof OnUpdateEvent && $event->subject === $entity))
            ->willReturnArgument(0);

        $bridge = new InfrastructureEventDispatcher($dispatcher);
        $em = $this->createStub(EntityManagerInterface::class);
        $changeSet = [];
        $bridge->preUpdate(new PreUpdateEventArgs($entity, $em, $changeSet));
    }
}
