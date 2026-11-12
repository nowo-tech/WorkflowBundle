<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Integration\Tenancy;

use LogicException;
use Nowo\WorkflowBundle\Entity\WorkflowDefinition;
use Nowo\WorkflowBundle\Model\WorkflowTenant;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Routing\RequestContextAwareInterface;

use function sys_get_temp_dir;

/**
 * Tenant isolation matrix of the embeddable UI (list, show / diagram, editor, publish, delete, clone), mounted under
 * `/organizations/{uuid}/settings` with the organization passed to the host's UI tenant resolver.
 */
final class TenantUiKernelTest extends TestCase
{
    use TenancyKernelSupport;

    private ?TenancyTestKernel $kernel = null;

    private WorkflowDefinition $global;

    private WorkflowDefinition $aDraft;

    private WorkflowDefinition $cOwn;

    public static function setUpBeforeClass(): void
    {
        (new Filesystem())->remove(sys_get_temp_dir() . TenancyTestKernel::CACHE_ROOT);
    }

    protected function setUp(): void
    {
        $this->kernel = $kernel = self::boot('tenancy');
        $this->global = self::seedLeaveTemplate($kernel);
        $this->aDraft = self::versioning($kernel)->cloneTemplate('leave', new WorkflowTenant('org-a'));
        $this->cOwn   = self::seedLeaveTemplate($kernel, 'days <= 0', new WorkflowTenant('org-c'));
        $this->cOwn->setName('Leave C only');
        self::repository($kernel)->save($this->cOwn);
    }

    protected function tearDown(): void
    {
        $this->kernel?->shutdown();
        $this->kernel = null;
    }

    #[Test]
    public function eachScopeListsOnlyItsOwnDefinitionsAndTheTemplatesItHasNotCustomized(): void
    {
        $kernel = $this->kernel();

        $a = self::send($kernel, self::ui('org-a', '/definitions'), 'admin-org-a');
        self::assertSame(200, $a->getStatusCode());
        self::assertStringContainsString('workflow-badge-draft', (string) $a->getContent());
        self::assertStringNotContainsString('workflow-badge-template', (string) $a->getContent(), 'customized: the template is shadowed');
        self::assertStringNotContainsString('Leave C only', (string) $a->getContent());
        self::assertStringContainsString('/organizations/org-a/settings/workflow/definitions/' . $this->aDraft->getId(), (string) $a->getContent(), 'links keep the host prefix');

        $b = self::send($kernel, self::ui('org-b', '/definitions'), 'admin-org-b');
        self::assertSame(200, $b->getStatusCode());
        self::assertStringContainsString('workflow-badge-template', (string) $b->getContent());
        self::assertStringNotContainsString('Leave C only', (string) $b->getContent());
        self::assertStringNotContainsString('/definitions/' . $this->aDraft->getId() . '"', (string) $b->getContent());

        $global = self::send($kernel, self::ui('global', '/definitions'), 'root');
        self::assertSame(200, $global->getStatusCode());
        self::assertStringNotContainsString('Leave C only', (string) $global->getContent());
        self::assertStringNotContainsString('workflow-badge-draft', (string) $global->getContent());

        $dashboard = self::send($kernel, self::ui('org-c', '/'), 'admin-org-c');
        self::assertSame(200, $dashboard->getStatusCode());
        self::assertStringContainsString('Leave C only', (string) $dashboard->getContent());

        // The prefix variables are gone from the router context once the request finished.
        self::assertArrayNotHasKey('uuid', self::service($kernel, RequestContextAwareInterface::class, 'router')->getContext()->getParameters());
    }

    #[Test]
    public function anotherTenantsDefinitionIsNotFoundAndATemplateIsReadOnlyFromATenant(): void
    {
        $kernel = $this->kernel();
        $c      = (int) $this->cOwn->getId();
        $g      = (int) $this->global->getId();
        $a      = (int) $this->aDraft->getId();

        $matrix = [
            // [method, path, user, status]
            ['GET', self::ui('org-a', '/definitions/' . $c), 'admin-org-a', 404],
            ['GET', self::ui('org-a', '/definitions/' . $c . '/edit/general'), 'admin-org-a', 404],
            ['GET', self::ui('org-a', '/definitions/' . $c . '/edit/transitions'), 'admin-org-a', 404],
            ['POST', self::ui('org-a', '/definitions/' . $c . '/delete'), 'admin-org-a', 404],
            ['POST', self::ui('org-a', '/definitions/' . $c . '/publish'), 'admin-org-a', 404],
            ['POST', self::ui('org-a', '/definitions/' . $c . '/draft'), 'admin-org-a', 404],
            ['POST', self::ui('org-a', '/definitions/' . $c . '/clone'), 'admin-org-a', 404],
            ['GET', self::ui('org-a', '/definitions/by-slug/leave'), 'admin-org-a', 200],
            ['GET', self::ui('org-b', '/definitions/' . $a), 'admin-org-b', 404],
            ['GET', self::ui('global', '/definitions/' . $a), 'root', 404],
            ['GET', self::ui('org-b', '/definitions/' . $g), 'admin-org-b', 200],
            ['GET', self::ui('org-b', '/definitions/' . $g . '/edit/general'), 'admin-org-b', 403],
            ['POST', self::ui('org-b', '/definitions/' . $g . '/publish'), 'admin-org-b', 403],
            ['POST', self::ui('org-b', '/definitions/' . $g . '/delete'), 'admin-org-b', 403],
            ['GET', self::ui('org-a', '/definitions/' . $a), 'admin-org-b', 403],
            ['GET', self::ui('org-a', '/definitions/' . $a), 'viewer-org-a', 200],
            ['GET', self::ui('org-a', '/definitions/' . $a . '/edit/general'), 'viewer-org-a', 403],
            ['POST', self::ui('org-a', '/definitions/' . $a . '/publish'), 'viewer-org-a', 403],
            ['GET', self::ui('org-a', '/definitions'), null, 403],
            ['GET', self::ui('global', '/definitions'), 'admin-org-a', 403],
        ];
        foreach ($matrix as [$method, $path, $user, $status]) {
            self::assertSame($status, self::send($kernel, $path, $user, $method)->getStatusCode(), $method . ' ' . $path . ' as ' . ($user ?? 'anonymous'));
        }

        $show = self::send($kernel, self::ui('org-b', '/definitions/' . $g), 'admin-org-b');
        self::assertStringContainsString('/definitions/' . $g . '/clone', (string) $show->getContent(), 'a template offers the clone action');
        self::assertStringNotContainsString('/definitions/' . $g . '/edit/general', (string) $show->getContent());
    }

    #[Test]
    public function cloneEditPublishAndDraftThroughTheUi(): void
    {
        $kernel = $this->kernel();
        $g      = (int) $this->global->getId();

        $clone = self::send($kernel, self::ui('org-b', '/definitions/' . $g . '/clone'), 'admin-org-b', 'POST', ['_token' => self::csrf($kernel, 'clone' . $g)]);
        self::assertSame(302, $clone->getStatusCode());
        $copy = self::repository($kernel)->findDraft('leave', new WorkflowTenant('org-b'));
        self::assertInstanceOf(WorkflowDefinition::class, $copy);
        self::assertSame(1, $copy->getTemplateVersion());
        self::assertStringContainsString('/organizations/org-b/settings/workflow/definitions/' . $copy->getId() . '/edit/general', (string) $clone->headers->get('Location'));

        $again = self::send($kernel, self::ui('org-b', '/definitions/' . $g . '/clone'), 'admin-org-b', 'POST', ['_token' => self::csrf($kernel, 'clone' . $g)]);
        self::assertSame(302, $again->getStatusCode(), 'already customized: back to the list');
        self::assertStringEndsWith('/organizations/org-b/settings/workflow/definitions', (string) $again->headers->get('Location'));

        $id     = (int) $copy->getId();
        $editor = self::send($kernel, self::ui('org-b', '/definitions/' . $id . '/edit/transitions'), 'admin-org-b');
        self::assertSame(200, $editor->getStatusCode());
        self::assertStringContainsString('guardExpression', (string) $editor->getContent());
        self::assertStringContainsString('guardPermission', (string) $editor->getContent());
        self::assertStringContainsString('days, type', (string) $editor->getContent(), 'the whitelist is shown');

        self::assertSame(403, self::send($kernel, self::ui('org-b', '/definitions/' . $id . '/publish'), 'admin-org-b', 'POST', ['_token' => 'wrong'])->getStatusCode(), 'CSRF');
        $publish = self::send($kernel, self::ui('org-b', '/definitions/' . $id . '/publish'), 'admin-org-b', 'POST', ['_token' => self::csrf($kernel, 'publish' . $id)]);
        self::assertSame(302, $publish->getStatusCode());
        self::assertSame(404, self::send($kernel, self::ui('org-b', '/definitions/' . $id . '/publish'), 'admin-org-b', 'POST', ['_token' => self::csrf($kernel, 'publish' . $id)])->getStatusCode(), 'only a draft');

        $readOnly = self::send($kernel, self::ui('org-b', '/definitions/' . $id . '/edit/general'), 'admin-org-b');
        self::assertSame(302, $readOnly->getStatusCode(), 'a published version is read-only');

        $draft = self::send($kernel, self::ui('org-b', '/definitions/' . $id . '/draft'), 'admin-org-b', 'POST', ['_token' => self::csrf($kernel, 'draft' . $id)]);
        self::assertSame(302, $draft->getStatusCode());
        $v2 = self::repository($kernel)->findDraft('leave', new WorkflowTenant('org-b'));
        self::assertInstanceOf(WorkflowDefinition::class, $v2);
        self::assertSame(2, $v2->getVersion());

        $delete = self::send($kernel, self::ui('org-b', '/definitions/' . $v2->getId() . '/delete'), 'admin-org-b', 'POST', ['_token' => self::csrf($kernel, 'delete' . $v2->getId())]);
        self::assertSame(302, $delete->getStatusCode());
        self::assertNull(self::repository($kernel)->findDraft('leave', new WorkflowTenant('org-b')), 'the draft only');
        self::assertNotNull(self::repository($kernel)->findCurrent('leave', new WorkflowTenant('org-b')));
    }

    #[Test]
    public function aNewDefinitionBelongsToTheScopeAndItsSlugIsUniqueThere(): void
    {
        $kernel = $this->kernel();
        $form   = static fn (string $slug): array => ['workflow_definition_form' => [
            'name'            => 'Expenses',
            'slug'            => $slug,
            'type'            => 'state_machine',
            'initialPlace'    => 'draft',
            'subjectClass'    => 'App\\Expense',
            'markingProperty' => 'status',
            'enabled'         => '1',
            'priority'        => '0',
            '_token'          => self::csrf($kernel, 'workflow_definition_form'),
        ]];

        $taken = self::send($kernel, self::ui('org-c', '/definitions/new'), 'admin-org-c', 'POST', $form('leave'));
        self::assertSame(422, $taken->getStatusCode());

        $created = self::send($kernel, self::ui('org-c', '/definitions/new'), 'admin-org-c', 'POST', $form('leave_extra'));
        self::assertSame(302, $created->getStatusCode());
        $draft = self::repository($kernel)->findDraft('leave_extra', new WorkflowTenant('org-c'));
        self::assertInstanceOf(WorkflowDefinition::class, $draft);
        self::assertNull(self::repository($kernel)->findDraft('leave_extra', null));
    }

    private function kernel(): TenancyTestKernel
    {
        return $this->kernel ?? throw new LogicException('No kernel.');
    }
}
