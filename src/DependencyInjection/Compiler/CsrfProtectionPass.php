<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\DependencyInjection\Compiler;

use LogicException;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Fails closed when the Workflow UI is enabled without form CSRF protection (REQ-SEC-005).
 *
 * Every mutation of the Workflow UI (create / edit section forms and the delete POST) relies on Symfony's form CSRF
 * extension or the CSRF token manager. A host that disables `framework.form.csrf_protection` (or has no CSRF token
 * manager) would silently turn the form checks off, so the container refuses to compile instead.
 */
final class CsrfProtectionPass implements CompilerPassInterface
{
    /**
     * Throws when `web_ui.enabled` is true and form CSRF protection or the CSRF token manager is unavailable.
     */
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasParameter('nowo_workflow.web_ui.enabled') || $container->getParameter('nowo_workflow.web_ui.enabled') !== true) {
            return;
        }

        $formCsrfDisabled = $container->hasParameter('form.type_extension.csrf.enabled')
            && $container->getParameter('form.type_extension.csrf.enabled') === false;

        if (
            !$container->has('security.csrf.token_manager')
            || !$container->hasDefinition('form.type_extension.csrf')
            || $formCsrfDisabled
        ) {
            throw new LogicException('NowoWorkflowBundle: the Workflow UI (nowo_workflow.web_ui.enabled: true) requires CSRF protection. Enable framework.csrf_protection and framework.form.csrf_protection (symfony/security-csrf), or set nowo_workflow.web_ui.enabled: false (every Workflow UI route then answers 404).');
        }
    }
}
