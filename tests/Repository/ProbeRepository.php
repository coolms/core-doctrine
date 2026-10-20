<?php

declare(strict_types=1);

namespace CoolMS\Core\Doctrine\Tests\Repository;

use CoolMS\Core\Doctrine\Repository\DoctrineRepository;
use CoolMS\Core\Identifier\IdentifierProviderInterface;

/**
 * The smallest concrete repository, for tests of the abstract one.
 *
 * @extends DoctrineRepository<IdentifierProviderInterface>
 */
final class ProbeRepository extends DoctrineRepository
{
}
