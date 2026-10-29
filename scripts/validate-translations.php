<?php

declare(strict_types=1);

/**
 * Validates the bundle translation catalogues (REQ-MAKE-004, REQ-I18N-002).
 *
 * - every YAML file under src/Resources/translations parses;
 * - every domain ships the seven required locales (en, es, it, fr, pt, de, nl);
 * - every required locale has exactly the flattened key set of the `en` reference catalogue (no missing / extra keys).
 *
 * Exit code 0 when all checks pass, 1 otherwise.
 *
 * Usage: php scripts/validate-translations.php [translations-dir]
 */

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

require dirname(__DIR__) . '/vendor/autoload.php';

const REQUIRED_LOCALES = ['en', 'es', 'it', 'fr', 'pt', 'de', 'nl'];

$dir = $argv[1] ?? dirname(__DIR__) . '/src/Resources/translations';

/**
 * @param array<array-key, mixed> $tree
 *
 * @return list<string>
 */
function flattenKeys(array $tree, string $prefix = ''): array
{
    $keys = [];
    foreach ($tree as $key => $value) {
        $path = $prefix === '' ? (string) $key : $prefix . '.' . $key;
        if (is_array($value)) {
            $keys = array_merge($keys, flattenKeys($value, $path));
        } else {
            $keys[] = $path;
        }
    }

    return $keys;
}

$errors  = [];
$domains = [];
$files   = array_merge(glob($dir . '/*.yaml') ?: [], glob($dir . '/*.yml') ?: []);

if ($files === []) {
    fwrite(\STDERR, "No YAML translation files found in {$dir}\n");
    exit(1);
}

foreach ($files as $file) {
    if (preg_match('/^(.+)\.([a-zA-Z_]+)\.ya?ml$/', basename($file), $m) !== 1) {
        $errors[] = basename($file) . ': file name is not <domain>.<locale>.yaml';
        continue;
    }

    try {
        $data = Yaml::parseFile($file);
    } catch (ParseException $exception) {
        $errors[] = basename($file) . ': ' . $exception->getMessage();
        continue;
    }

    $domains[$m[1]][$m[2]] = is_array($data) ? flattenKeys($data) : [];
}

foreach ($domains as $domain => $locales) {
    foreach (REQUIRED_LOCALES as $locale) {
        if (!isset($locales[$locale])) {
            $errors[] = "{$domain}: missing required locale file {$domain}.{$locale}.yaml";
        }
    }

    $reference = $locales['en'] ?? null;
    if ($reference === null) {
        continue;
    }

    foreach ($locales as $locale => $keys) {
        if ($locale === 'en' || !in_array($locale, REQUIRED_LOCALES, true)) {
            continue;
        }
        foreach (array_diff($reference, $keys) as $key) {
            $errors[] = "{$domain}.{$locale}: missing key {$key}";
        }
        foreach (array_diff($keys, $reference) as $key) {
            $errors[] = "{$domain}.{$locale}: extra key {$key} (not in en)";
        }
    }

    printf("%s: %d keys, locales %s\n", $domain, count($reference), implode(', ', array_keys($locales)));
}

if ($errors !== []) {
    fwrite(\STDERR, implode("\n", $errors) . "\n");
    fwrite(\STDERR, sprintf("Translation validation failed (%d problem(s)).\n", count($errors)));
    exit(1);
}

echo "Translations OK (syntax + key parity).\n";
