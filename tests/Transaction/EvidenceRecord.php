<?php

declare(strict_types=1);

namespace CoolMS\Core\Doctrine\Tests\Transaction;

use Doctrine\ORM\Mapping as ORM;

/** A record as persist() takes one: a row of the tests' evidence table. */
#[ORM\Entity]
#[ORM\Table(name: 'evidence')]
class EvidenceRecord
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'string')]
        public string $v,
    ) {
    }
}
