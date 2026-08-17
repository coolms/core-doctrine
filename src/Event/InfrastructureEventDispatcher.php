<?php

declare(strict_types=1);

namespace CoolMS\Core\Doctrine\Event;

use CoolMS\Core\Lifecycle\OnCreateEvent;
use CoolMS\Core\Lifecycle\OnUpdateEvent;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Events;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Bridges Doctrine ORM lifecycle hooks to Tier-3 infrastructure events.
 *
 * Without this bridge, OnCreateEvent / OnUpdateEvent are declared but never
 * fire for entities persisted through the ORM, leaving downstream listeners
 * (Blameable createdBy/updatedBy/accessedBy backfill, Timestampable refresh)
 * dormant. Application-layer dispatch existed historically for command-bus
 * paths but never covered the API-platform / Doctrine processor cascade.
 *
 * Why prePersist + preUpdate (not postPersist / postFlush): Blameable + Timestampable
 * listeners mutate entity properties that must be written in the same flush as
 * the entity itself. prePersist runs before INSERT generation; preUpdate runs
 * before UPDATE generation and inside Doctrine's UoW change-set computation,
 * so the mutated property is included automatically.
 *
 * Idempotent across entities: every Doctrine-persisted entity passes through
 * this bridge. Listeners decide whether they apply (instanceof checks against
 * the relevant provider interfaces).
 */
#[AsDoctrineListener(event: Events::prePersist)]
#[AsDoctrineListener(event: Events::preUpdate)]
final readonly class InfrastructureEventDispatcher
{
    public function __construct(
        private EventDispatcherInterface $eventDispatcher,
    ) {
    }

    public function prePersist(PrePersistEventArgs $args): void
    {
        $this->eventDispatcher->dispatch(new OnCreateEvent(subject: $args->getObject()));
    }

    public function preUpdate(PreUpdateEventArgs $args): void
    {
        $this->eventDispatcher->dispatch(new OnUpdateEvent(subject: $args->getObject()));
    }
}
