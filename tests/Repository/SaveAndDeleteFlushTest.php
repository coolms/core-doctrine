<?php

declare(strict_types=1);

namespace CoolMS\Core\Doctrine\Tests\Repository;

use CoolMS\Core\Doctrine\Repository\DoctrineRepository;
use CoolMS\Core\Identifier\IdentifierProviderInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;

/**
 * save() and delete() flush, always: there is no deferred-flush mode. The parameter that switched it,
 * `coolms.persistence.transaction_mode`, no longer changes anything -- a unit of work's owner decides the commit, and a
 * flush inside the unit is part of the unit's transaction.
 */
#[CoversClass(DoctrineRepository::class)]
final class SaveAndDeleteFlushTest extends TestCase
{
    #[Test]
    public function saveFlushesWhateverTheOldModeParameterSays(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('contains')->willReturn(false);
        $em->expects(self::once())->method('persist');
        $em->expects(self::once())->method('flush');

        $this->repository($em)->save($this->entity());
    }

    #[Test]
    public function deleteFlushesWhateverTheOldModeParameterSays(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('remove');
        $em->expects(self::once())->method('flush');

        $this->repository($em)->delete($this->entity());
    }

    private function repository(EntityManagerInterface&MockObject $em): ProbeRepository
    {
        $em->method('getClassMetadata')->willReturn(new ClassMetadata(IdentifierProviderInterface::class));
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($em);

        return new ProbeRepository(
            $registry,
            IdentifierProviderInterface::class,
            new ParameterBag(['coolms.persistence.transaction_mode' => true]),
        );
    }

    private function entity(): IdentifierProviderInterface
    {
        return $this->createStub(IdentifierProviderInterface::class);
    }
}
