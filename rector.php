<?php

declare(strict_types=1);

use Rector\CodeQuality\Rector\ClassMethod\LocallyCalledStaticMethodToNonStaticRector;
use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\MethodCall\RemoveNullArgOnNullDefaultParamRector;
use Rector\Symfony\CodeQuality\Rector\Class_\EventSubscriberToAttributeRector;
use Rector\ValueObject\PhpVersion;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ])
    ->withPhpVersion(PhpVersion::PHP_82)
    ->withComposerBased(symfony: true)
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        typeDeclarations: true,
    )
    ->withSkip([
        // rector 2.8 (symfony set): a bundle must not rely on the host's autoconfiguration for #[AsEventListener];
        // the subscribers are tested through getSubscribedEvents() (same skip as LegalKit / RuntimeEnv).
        EventSubscriberToAttributeRector::class,
        __DIR__ . '/vendor',

        // Each skip below is deliberate and scoped to one rule in one file. Do not "fix" `composer rector-dry` by
        // applying the hunks or by dropping the skip: the reviewed intent is recorded here.

        // UnprefixedTablesGuard::renameSql() is a pure function (it reads only its arguments, never $this), so it is
        // intentionally `static`; an instance method would imply state it does not have.
        LocallyCalledStaticMethodToNonStaticRector::class => [
            __DIR__ . '/src/Doctrine/UnprefixedTablesGuard.php',
        ],

        // The explicit `null` in `$this->registry(new MariaDBPlatform(), [], null)` documents the case under test (no
        // server version is reported, and MariaDB must never ask the server); dropping it would read as if that
        // argument were irrelevant, when it is the whole point of the test.
        RemoveNullArgOnNullDefaultParamRector::class => [
            __DIR__ . '/tests/Unit/Doctrine/BinaryKeyCollationSubscriberTest.php',
        ],
    ]);
