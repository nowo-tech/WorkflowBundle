<?php

declare(strict_types=1);

namespace Nowo\WorkflowBundle\Tests\Support;

use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\Form\Extension\HttpFoundation\HttpFoundationExtension;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\Forms;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Translation\IdentityTranslator;
use Symfony\Component\Validator\Validation;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

final class ControllerContainerFactory
{
    /**
     * @param array<string, object> $overrides
     */
    public static function create(array $overrides = []): Container
    {
        $twig = new Environment(new ArrayLoader([
            '@NowoWorkflowBundle/dashboard/index.html.twig'           => 'dashboard',
            '@NowoWorkflowBundle/workflow_definition/index.html.twig' => 'index',
            '@NowoWorkflowBundle/workflow_definition/show.html.twig'  => 'show',
            '@NowoWorkflowBundle/workflow_definition/form.html.twig'  => 'form',
        ]));

        $router = new StubUrlGenerator();

        $formFactory = Forms::createFormFactoryBuilder()
            ->addExtension(new HttpFoundationExtension())
            ->addExtension(new ValidatorExtension(Validation::createValidator()))
            ->getFormFactory();

        $session = new Session(new MockArraySessionStorage());
        $request = Request::create('/');
        $request->setSession($session);
        $requestStack = new RequestStack();
        $requestStack->push($request);

        $translator = new IdentityTranslator();

        $csrf = new class implements CsrfTokenManagerInterface {
            public function getToken(string $tokenId): CsrfToken
            {
                return new CsrfToken($tokenId, 'valid');
            }

            public function refreshToken(string $tokenId): CsrfToken
            {
                return new CsrfToken($tokenId, 'valid');
            }

            public function removeToken(string $tokenId): ?string
            {
                return null;
            }

            public function isTokenValid(CsrfToken $token): bool
            {
                return $token->getValue() === 'valid';
            }
        };

        $container = new Container();
        $container->set('twig', $twig);
        $container->set('router', $router);
        $container->set('form.factory', $formFactory);
        $container->set('request_stack', $requestStack);
        $container->set('translator', $translator);
        $container->set('security.csrf.token_manager', $csrf);

        foreach ($overrides as $id => $service) {
            $container->set($id, $service);
        }

        return $container;
    }
}
