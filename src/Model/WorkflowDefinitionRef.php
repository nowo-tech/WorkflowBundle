<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Model;

use InvalidArgumentException;
use Nowo\WorkflowBundle\Contract\WorkflowVersionManagerInterface;
use Nowo\WorkflowBundle\Entity\WorkflowDefinition;

use function sprintf;

/**
 * Framework-free reference to one stored definition version (since 1.19, REQ-ARCH-001 rule 1): what
 * {@see WorkflowVersionManagerInterface} takes instead of the {@see WorkflowDefinition} aggregate.
 *
 * The four fields together name exactly one row: the version is looked up in the scope (slug and tenant, byte for
 * byte, never another tenant's) and the id is then checked, so a reference built against another scope can never
 * reach a definition of that scope.
 */
final readonly class WorkflowDefinitionRef
{
    /**
     * @param int $id row id of the version (as stored, always ≥ 1)
     * @param string $slug workflow slug
     * @param WorkflowTenant|null $tenant owner tenant (null = global template)
     * @param int $version version number within (tenant, slug), always ≥ 1
     *
     * @throws InvalidArgumentException when the id or the version is not positive, or the slug is empty
     */
    public function __construct(
        public int $id,
        public string $slug,
        public ?WorkflowTenant $tenant,
        public int $version,
    ) {
        if ($id < 1 || $version < 1 || $slug === '') {
            throw new InvalidArgumentException(sprintf('A workflow definition reference needs a non-empty slug and a positive id and version, got id %d, slug "%s", version %d.', $id, $slug, $version));
        }
    }

    /**
     * The reference of a stored definition.
     *
     * @throws InvalidArgumentException when the definition was never saved (no id)
     */
    public static function fromDefinition(WorkflowDefinition $definition): self
    {
        return new self($definition->getId() ?? 0, $definition->getSlug(), $definition->getTenant(), $definition->getVersion());
    }
}
