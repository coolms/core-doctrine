<?php

declare(strict_types=1);

namespace CoolMS\Core\Doctrine\Repository;

use CoolMS\Core\Identifier\IdentifierProviderInterface;
use CoolMS\Core\Repository\RepositoryInterface;
use CoolMS\Rql\Doctrine\DoctrineRqlVisitor;
use CoolMS\Rql\RqlContext;
use CoolMS\Rql\RqlQuery;
use CoolMS\Rql\RqlResult;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Exception\ORMException;
use Doctrine\Persistence\ManagerRegistry;
use LogicException;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

/**
 * Abstract Doctrine Repository.
 *
 * Provides save() and delete() with optional deferred-flush support
 * (controlled by the coolms.persistence.transaction_mode container parameter).
 * Actual flush is handled by DeferredFlushSubscriber on kernel.response /
 * kernel.terminate / console.terminate when transaction mode is active.
 *
 * NOTE: find(Uuid $id) from RepositoryInterface is satisfied by the parent
 * ServiceEntityRepository::find(mixed $id) -- PHP allows a wider parameter
 * type in the implementing class (contravariance). We do NOT override find()
 * here to avoid a declaration incompatibility with the parent's full signature
 * find(mixed $id, LockMode|int|null $lockMode = null, ?int $lockVersion = null).
 *
 * @template T of object
 *
 * @extends ServiceEntityRepository<T>
 */
abstract class DoctrineRepository extends ServiceEntityRepository implements RepositoryInterface
{
    public function __construct(
        ManagerRegistry $registry,
        string $entityClass,
        private readonly ParameterBagInterface $parameterBag,
        protected readonly ?DoctrineRqlVisitor $rqlVisitor = null,
    ) {
        parent::__construct($registry, $entityClass);
    }

    /**
     * Default RQL-driven search. Concrete repositories may override
     * to customize the base QueryBuilder (e.g., joins, EXISTS
     * guards, custom sort projections); otherwise this delegates
     * the whole pipeline -- filters, sort, pagination, and total
     * count -- to `DoctrineRqlVisitor::apply`.
     *
     * Subclasses that intend to expose this method MUST pass a
     * `DoctrineRqlVisitor` to the parent constructor; auxiliary
     * repositories without an RQL surface (e.g., AccessToken,
     * IdentityProfile) can omit it and never call this method.
     */
    public function findByRql(RqlQuery $query, RqlContext $context): RqlResult
    {
        if (null === $this->rqlVisitor) {
            throw new LogicException(sprintf('%s has no DoctrineRqlVisitor wired. Pass one to the parent constructor or override findByRql().', static::class));
        }
        $qb = $this->createQueryBuilder($context->entityAlias);

        return $this->rqlVisitor->apply($query, $qb, $context);
    }

    public function save(IdentifierProviderInterface $entity): void
    {
        $em = $this->getEntityManager();
        if (!$em->contains($entity)) {
            $em->persist($entity);
        }
        if ($this->isTransactionMode()) {
            return;
        }
        $em->flush();
    }

    public function delete(IdentifierProviderInterface $entity): void
    {
        $em = $this->getEntityManager();
        $em->remove($entity);
        if ($this->isTransactionMode()) {
            return;
        }
        $em->flush();
    }

    /**
     * @throws ORMException
     */
    public function refresh(IdentifierProviderInterface $entity): void
    {
        $this->getEntityManager()->refresh($entity);
    }

    private function isTransactionMode(): bool
    {
        return $this->parameterBag->has('coolms.persistence.transaction_mode')
            && $this->parameterBag->get('coolms.persistence.transaction_mode');
    }
}
