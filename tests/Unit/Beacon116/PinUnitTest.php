<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Unit\Beacon116;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\Exception as DriverException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\FieldMapping;
use Exception;
use LogicException;
use Nowo\WorkflowBundle\Contract\WorkflowRegistryInterface;
use Nowo\WorkflowBundle\Contract\WorkflowSubjectReferenceResolverInterface;
use Nowo\WorkflowBundle\Contract\WorkflowTenantResolverInterface;
use Nowo\WorkflowBundle\Entity\WorkflowDefinition;
use Nowo\WorkflowBundle\Entity\WorkflowPlace;
use Nowo\WorkflowBundle\Entity\WorkflowSubjectPin;
use Nowo\WorkflowBundle\Enum\WorkflowPinMode;
use Nowo\WorkflowBundle\Model\WorkflowSubject;
use Nowo\WorkflowBundle\Model\WorkflowSubjectRef;
use Nowo\WorkflowBundle\Model\WorkflowTenant;
use Nowo\WorkflowBundle\Repository\WorkflowDefinitionRepository;
use Nowo\WorkflowBundle\Service\WorkflowApplicator;
use Nowo\WorkflowBundle\Service\WorkflowResolver;
use Nowo\WorkflowBundle\Tenancy\SubjectPinStore;
use Nowo\WorkflowBundle\Tenancy\TenancyContext;
use Nowo\WorkflowBundle\Tenancy\WorkflowDefinitionLocator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use stdClass;

/**
 * 1.16: the explicit pin (unit level; the flows are in TenantClonesKernelTest).
 */
final class PinUnitTest extends TestCase
{
    #[Test]
    public function aConcurrentPinWinsAndTheResultSaysSo(): void
    {
        $proposed = $this->definition(1);
        $winner   = $this->definition(2);
        $row      = ['pin_id' => 7, 'pin_definition' => 2, 'pin_tenant' => '', 'pin_slug' => 'leave', 'pin_ref' => 'leave-1'];

        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn(new SQLitePlatform());
        $connection->method('isTransactionActive')->willReturn(false);
        // Locator read (no pin), store read (no pin), locked read and locator re-read (the winner's pin).
        $connection->method('fetchAllAssociative')->willReturnOnConsecutiveCalls([], [], [$row], [$row]);
        $connection->method('insert')->willThrowException($this->uniqueViolation());

        $repository = $this->repository($connection);
        $repository->method('findForTenant')->willReturn($proposed);
        $repository->method('find')->with(2)->willReturn($winner);

        $result = $this->locator($repository)->pinSubject(new stdClass(), 'leave', WorkflowPinMode::KeepExisting, static function (): void {
        }, static fn (): array => []);

        self::assertFalse($result->created);
        self::assertSame(2, $result->definitionVersion);
    }

    #[Test]
    public function repinWithoutAPinWritesNothing(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn(new SQLitePlatform());
        $connection->method('fetchAllAssociative')->willReturn([]);
        $connection->expects(self::never())->method('executeStatement');

        self::assertNull((new SubjectPinStore($this->repository($connection)))->repin(new WorkflowTenant('org-a'), 'leave', new WorkflowSubjectRef('leave-1'), $this->definition(1)));
    }

    #[Test]
    public function aRepinWhosePinVanishedPinsLikeANewSubject(): void
    {
        $pinned = $this->definition(1);
        $target = $this->definition(2);

        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn(new SQLitePlatform());
        $connection->method('isTransactionActive')->willReturn(false);
        // Locator read (pinned to 1), the locked read of the re-pin (gone), the pin's own read (none).
        $connection->method('fetchAllAssociative')->willReturnOnConsecutiveCalls(
            [['pin_id' => 7, 'pin_definition' => 1, 'pin_tenant' => '', 'pin_slug' => 'leave', 'pin_ref' => 'leave-1']],
            [],
            [],
        );
        $connection->expects(self::once())->method('insert')->willReturn(1);

        $repository = $this->repository($connection);
        $repository->method('find')->with(1)->willReturn($pinned);
        $repository->method('findForTenant')->willReturn($target);

        $result = $this->locator($repository)->pinSubject(new stdClass(), 'leave', WorkflowPinMode::Repin, static function (): void {
        }, static fn (): array => []);

        self::assertTrue($result->created);
        self::assertFalse($result->replaced);
        self::assertSame(2, $result->definitionVersion);
    }

    #[Test]
    public function aFailedRepinRollsBack(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn(new SQLitePlatform());
        $connection->method('fetchAllAssociative')->willReturn([['pin_id' => 7, 'pin_definition' => 1, 'pin_tenant' => 'org-a', 'pin_slug' => 'leave', 'pin_ref' => 'leave-1']]);
        $connection->method('executeStatement')->willThrowException(new LogicException('lost'));
        $connection->expects(self::once())->method('beginTransaction');
        $connection->expects(self::once())->method('rollBack');
        $connection->expects(self::never())->method('commit');

        $this->expectExceptionMessage('lost');
        (new SubjectPinStore($this->repository($connection)))->repin(new WorkflowTenant('org-a'), 'leave', new WorkflowSubjectRef('leave-1'), $this->definition(2));
    }

    #[Test]
    public function anApplicatorWithoutLocatorCannotPin(): void
    {
        $applicator = new WorkflowApplicator(
            $this->createMock(WorkflowRegistryInterface::class),
            $this->createMock(WorkflowDefinitionRepository::class),
            new WorkflowResolver($this->createMock(WorkflowDefinitionRepository::class)),
            $this->createMock(EntityManagerInterface::class),
        );

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('needs tenancy');
        $applicator->pin(new stdClass(), 'leave');
    }

    private function locator(WorkflowDefinitionRepository $repository): WorkflowDefinitionLocator
    {
        $ports = new class implements WorkflowTenantResolverInterface, WorkflowSubjectReferenceResolverInterface {
            public function tenantOf(WorkflowSubject $subject): ?WorkflowTenant
            {
                return null;
            }

            public function referenceOf(WorkflowSubject $subject): WorkflowSubjectRef
            {
                return new WorkflowSubjectRef('leave-1');
            }
        };

        return new WorkflowDefinitionLocator($repository, new WorkflowResolver($repository), new TenancyContext($ports, $ports), new SubjectPinStore($repository));
    }

    /**
     * @return MockObject&WorkflowDefinitionRepository
     */
    private function repository(Connection $connection): WorkflowDefinitionRepository
    {
        $metadata = new ClassMetadata(WorkflowSubjectPin::class);
        foreach (['id', 'tenantKey', 'workflowSlug', 'subjectRef', 'createdAt'] as $field) {
            $metadata->fieldMappings[$field] = FieldMapping::fromMappingArray(['fieldName' => $field, 'columnName' => $field, 'type' => 'string']);
            $metadata->columnNames[$field]   = $field;
        }
        $metadata->setTableName('workflow_subject_pin');
        $metadata->mapManyToOne(['fieldName' => 'definition', 'targetEntity' => WorkflowDefinition::class, 'joinColumns' => [['name' => 'definition_id', 'referencedColumnName' => 'id']]]);
        $repository = $this->createMock(WorkflowDefinitionRepository::class);
        $repository->method('connection')->willReturn($connection);
        $repository->method('bundleMetadata')->willReturn($metadata);

        return $repository;
    }

    private function definition(int $id): WorkflowDefinition
    {
        $definition = new WorkflowDefinition('Leave', 'leave', 'requested', stdClass::class);
        $definition->addPlace(new WorkflowPlace('requested'));
        (new ReflectionProperty(WorkflowDefinition::class, 'id'))->setValue($definition, $id);
        (new ReflectionProperty(WorkflowDefinition::class, 'version'))->setValue($definition, $id);

        return $definition;
    }

    private function uniqueViolation(): UniqueConstraintViolationException
    {
        $driver = new class('duplicate') extends Exception implements DriverException {
            public function getSQLState(): string
            {
                return '23000';
            }
        };

        return new UniqueConstraintViolationException($driver, null);
    }
}
