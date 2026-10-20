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
 * Provides save() and delete(), each of which flushes. Inside a unit of work
 * (CoolMS\Core\Transaction\UnitOfWorkOwnerInterface) the flush runs inside the
 * unit's transaction, so nothing is committed before the unit's owner commits;
 * outside one it commits at once. There is no deferred-flush mode any more.
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
        // Unused since the deferred-flush mode was removed; kept so the 85+ subclasses need no change.
        ParameterBagInterface $parameterBag,
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
        $em->flush();
    }

    public function delete(IdentifierProviderInterface $entity): void
    {
        $em = $this->getEntityManager();
        $em->remove($entity);
        $em->flush();
    }

    /**
     * @throws ORMException
     */
    public function refresh(IdentifierProviderInterface $entity): void
    {
        $this->getEntityManager()->refresh($entity);
    }
}
