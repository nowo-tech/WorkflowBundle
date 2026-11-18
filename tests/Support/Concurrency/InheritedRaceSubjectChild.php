<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Support\Concurrency;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'wfrt_inherited_race_subject_child')]
class InheritedRaceSubjectChild extends InheritedRaceSubject
{
    #[ORM\Column(type: 'string', length: 32)]
    private string $extra = 'child';

    public function getExtra(): string
    {
        return $this->extra;
    }
}
