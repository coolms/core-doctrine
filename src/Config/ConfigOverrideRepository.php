<?php

declare(strict_types=1);

namespace CoolMS\Core\Doctrine\Config;

use CoolMS\Core\Config\ConfigOverride;
use CoolMS\Core\Config\ConfigOverrideRepositoryInterface;
use CoolMS\Core\Doctrine\Repository\DoctrineRepository;
use CoolMS\Rql\Doctrine\DoctrineRqlVisitor;
use Doctrine\DBAL\Exception\TableNotFoundException;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

/**
 * @extends DoctrineRepository<ConfigOverride>
 */
final class ConfigOverrideRepository extends DoctrineRepository implements ConfigOverrideRepositoryInterface
{
    public function __construct(
        ManagerRegistry $registry,
        ParameterBagInterface $parameterBag,
        private readonly LoggerInterface $logger,
        ?DoctrineRqlVisitor $rqlVisitor = null,
    ) {
        parent::__construct($registry, ConfigOverride::class, $parameterBag, $rqlVisitor);
    }

    /**
     * ## "No table" reads as "no override", and only here.
     *
     * Config is read on databases that have not been migrated yet — a fresh
     * checkout, a CI job that builds before it migrates, an installer running
     * its own first migration. The platform managed without this table until
     * now, so a missing one must degrade to file-only config rather than take
     * the request down.
     *
     * The catch lives in this class because Doctrine does: everything above
     * talks to {@see ConfigOverrideRepositoryInterface} and cannot name a DBAL
     * exception, which is the platform's ORM-agnostic rule and is enforced by a
     * phpstan boundary check.
     *
     * ⚠️ `TableNotFoundException` ONLY, never its parent. The first cut caught
     * `DbalException` in the caller, and when this table's migration turned out
     * to be missing `accessed_at` — a column the entity's own trait maps — every
     * SELECT was refused and the platform quietly served files instead. Saving
     * worked, reading "worked", and no override ever appeared. A missing table
     * is an expected state; a table the entity cannot read is a bug, and a bug
     * has to be allowed to say so.
     */
    public function findOverride(string $type, string $id): ?ConfigOverride
    {
        try {
            return $this->findOneBy(['configType' => $type, 'configId' => $id]);
        } catch (TableNotFoundException $e) {
            // Debug, not warning: on an unmigrated database this is the
            // expected path and would otherwise fill the log on every read.
            $this->logger->debug('Config overrides unavailable ({error}); reading files only.', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    public function upsert(string $type, string $id, array $data): ConfigOverride
    {
        $entity = $this->findOverride($type, $id);
        if (null === $entity) {
            $entity = new ConfigOverride($type, $id, $data);
        } else {
            $entity->data = $data;
        }

        // save() honours the codebase's deferred-flush transaction mode.
        $this->save($entity);

        return $entity;
    }

    public function deleteOverride(string $type, string $id): bool
    {
        $entity = $this->findOverride($type, $id);
        if (null === $entity) {
            return false;
        }

        $this->delete($entity);

        return true;
    }
}
