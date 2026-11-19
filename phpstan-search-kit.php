<?php

declare(strict_types=1);

// Since 1.21.0: SearchKit (PHP >= 8.3) is optional. Without it (the PHP 8.2 jobs) the SearchKit group and its tests
// cannot be analysed: they extend its classes. They are analysed wherever SearchKit is installed (the PHPStan job).
return is_file(__DIR__ . '/vendor/nowo-tech/search-kit-bundle/src/Provider/Entity/EntitySearchDefinition.php')
    ? []
    : ['parameters' => ['excludePaths' => ['analyseAndScan' => [
        __DIR__ . '/src/Bridge/SearchKit',
        __DIR__ . '/tests/Integration/SearchKit',
        __DIR__ . '/tests/Support/SearchKit',
    ]]]];
