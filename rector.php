<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
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
    ]);
