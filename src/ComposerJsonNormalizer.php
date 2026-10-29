<?php declare(strict_types = 1);

namespace ShipMonk\ComposerNormalizer;

use JsonException;
use LogicException;
use stdClass;
use function array_flip;
use function array_keys;
use function count;
use function explode;
use function get_object_vars;
use function intdiv;
use function is_array;
use function is_int;
use function is_string;
use function json_decode;
use function json_encode;
use function preg_match;
use function preg_replace;
use function preg_replace_callback;
use function rtrim;
use function str_contains;
use function str_repeat;
use function str_replace;
use function strcmp;
use function strlen;
use function strnatcmp;
use function uksort;
use function usort;
use const JSON_PRESERVE_ZERO_FRACTION;
use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;
use const PHP_INT_MAX;

/**
 * Sorts keys of composer.json, the result is the same as with ergebnis/composer-normalize except for version constraints.
 *
 * Paths below use / as separator, * matches any object key or list index.
 */
final class ComposerJsonNormalizer
{

    private const DEFAULT_INDENT = '    ';
    private const DEFAULT_NEW_LINE = "\n";

    /**
     * Key order defined by the Composer schema (https://getcomposer.org/schema.json).
     * Keys that are not listed go after the listed keys, in alphabetical order.
     * Objects that are not listed here have all keys in alphabetical order.
     */
    private const SCHEMA_KEY_ORDER = [
        '' => [
            'name',
            'description',
            'license',
            'type',
            'abandoned',
            'version',
            'default-branch',
            'non-feature-branches',
            'keywords',
            'readme',
            'time',
            'authors',
            'homepage',
            'support',
            'funding',
            'source',
            'dist',
            '_comment',
            'require',
            'require-dev',
            'replace',
            'conflict',
            'provide',
            'suggest',
            'repositories',
            'minimum-stability',
            'prefer-stable',
            'autoload',
            'autoload-dev',
            'target-dir',
            'include-path',
            'bin',
            'archive',
            'php-ext',
            'config',
            'extra',
            'scripts',
            'scripts-descriptions',
            'scripts-aliases',
        ],
        'authors/*' => ['name', 'email', 'homepage', 'role'],
        'support' => ['email', 'issues', 'forum', 'wiki', 'irc', 'chat', 'source', 'docs', 'rss', 'security'],
        'funding/*' => ['type', 'url'],
        'source' => ['type', 'url', 'reference', 'mirrors'],
        'dist' => ['type', 'url', 'reference', 'shasum', 'mirrors'],
        // all repository types in one list, each type uses a subset of these keys in this order
        'repositories/*' => [
            'name',
            'type',
            'url',
            'canonical',
            'only',
            'exclude',
            'options',
            'allow_ssl_downgrade',
            'require-published-time',
            'force-lazy-providers',
            'filter',
            'no-api',
            'only-branches',
            'secure-http',
            'svn-cache-credentials',
            'trunk-path',
            'branches-path',
            'tags-path',
            'package-path',
            'depot',
            'branch',
            'unique_perforce_client_name',
            'p4user',
            'p4password',
            'vendor-alias',
            'package',
        ],
        'repositories/*/options' => ['reference', 'symlink', 'relative', 'versions'],
        'autoload' => ['psr-0', 'psr-4', 'classmap', 'files', 'exclude-from-classmap'],
        'autoload-dev' => ['psr-0', 'psr-4', 'classmap', 'files', 'exclude-from-classmap'],
        'archive' => ['name', 'exclude'],
        'php-ext' => [
            'extension-name',
            'priority',
            'support-zts',
            'support-nts',
            'build-path',
            'download-url-method',
            'os-families',
            'os-families-exclude',
            'configure-options',
        ],
        'php-ext/configure-options/*' => ['name', 'needs-value', 'description'],
        'config/audit' => [
            'ignore',
            'abandoned',
            'ignore-severity',
            'ignore-unreachable',
            'block-insecure',
            'block-abandoned',
            'ignore-abandoned',
        ],
        'config/policy' => ['advisories', 'malware', 'abandoned', 'cooldown', 'ignore-unreachable'],
        'config/policy/advisories' => ['block', 'audit', 'ignore-id', 'ignore', 'ignore-severity'],
        'config/policy/malware' => ['block', 'block-scope', 'audit', 'ignore', 'ignore-source'],
        'config/policy/abandoned' => ['block', 'audit', 'ignore'],
        'config/policy/cooldown' => ['period', 'block', 'audit', 'ignore'],
        'scripts' => [
            'pre-install-cmd',
            'post-install-cmd',
            'pre-update-cmd',
            'post-update-cmd',
            'pre-status-cmd',
            'post-status-cmd',
            'pre-package-install',
            'post-package-install',
            'pre-package-update',
            'post-package-update',
            'pre-package-uninstall',
            'post-package-uninstall',
            'pre-autoload-dump',
            'post-autoload-dump',
            'post-root-package-install',
            'post-create-project-cmd',
        ],
    ];

    /**
     * Sorted like Composer does with the sort-packages option: platform packages first.
     */
    private const PACKAGE_LINKS = [
        'require',
        'require-dev',
        'conflict',
        'provide',
        'replace',
        'suggest',
    ];

    /**
     * The first matching entry wins, so a key with * goes after the keys it matches.
     */
    private const WILDCARD_OBJECTS = [
        'config/allow-plugins',
        'config/preferred-install',
    ];

    private const WILDCARD_LISTS = [
        'repositories/*/only',
        'repositories/*/exclude',
    ];

    private const SORTED_LISTS = [
        'bin',
    ];

    /**
     * The order of keys has a meaning here, so it is kept.
     */
    private const KEEP_ORDER = [
        'repositories',
        'extra/installer-paths',
        'extra/patches/*',
        'scripts/auto-scripts',
    ];

    /**
     * Same as Composer\Repository\PlatformRepository::PLATFORM_PACKAGE_REGEX
     */
    private const PLATFORM_PACKAGE_REGEX = '{^(?:php(?:-64bit|-ipv6|-zts|-debug)?|hhvm|(?:ext|lib)-[a-z0-9](?:[_.-]?[a-z0-9]+)*|composer(?:-(?:plugin|runtime)-api)?)$}iD';

    /**
     * Returns the normalized JSON, with the same indent and new line style as the input. It always ends with a new line.
     *
     * @throws InvalidComposerJsonException
     */
    public function normalize(string $json): string
    {
        try {
            $decoded = json_decode($json, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new InvalidComposerJsonException('Invalid JSON: ' . $e->getMessage(), $e);
        }

        if (!$decoded instanceof stdClass) {
            throw new InvalidComposerJsonException('Expected a JSON object at the top level');
        }

        try {
            $encoded = json_encode(
                $this->normalizeObject($decoded, []),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $e) {
            throw new LogicException('Decoded JSON cannot be encoded: ' . $e->getMessage(), 0, $e);
        }

        $indent = preg_match('~^( +|\t+)~m', $json, $indentMatch) === 1 ? $indentMatch[1] : self::DEFAULT_INDENT;
        $newLine = preg_match('~\r\n|\n|\r~', $json, $newLineMatch) === 1 ? $newLineMatch[0] : self::DEFAULT_NEW_LINE;

        if ($indent !== self::DEFAULT_INDENT) {
            $encoded = preg_replace_callback(
                '~^(?:    )+~m',
                static fn (array $match): string => str_repeat($indent, intdiv(strlen($match[0]), 4)),
                $encoded,
            ) ?? throw new LogicException('Failed to change indent');
        }

        return str_replace("\n", $newLine, $encoded) . $newLine;
    }

    /**
     * @param list<string> $path
     */
    private function normalizeValue(
        mixed $value,
        array $path,
    ): mixed
    {
        if ($value instanceof stdClass) {
            return $this->normalizeObject($value, $path);
        }

        if (is_array($value)) {
            return $this->normalizeList($value, $path);
        }

        return $value;
    }

    /**
     * @param list<string> $path
     */
    private function normalizeObject(
        stdClass $object,
        array $path,
    ): stdClass
    {
        $normalized = new stdClass();

        foreach ($this->sortProperties(get_object_vars($object), $path) as $key => $value) {
            $normalized->{$key} = $this->normalizeValue($value, [...$path, (string) $key]);
        }

        return $normalized;
    }

    /**
     * @param array<mixed> $list
     * @param list<string> $path
     * @return list<mixed>
     */
    private function normalizeList(
        array $list,
        array $path,
    ): array
    {
        $normalized = [];

        foreach ($list as $index => $item) {
            $normalized[] = $this->normalizeValue($item, [...$path, (string) $index]);
        }

        if (self::matchesAny($path, self::SORTED_LISTS) && self::containsOnlyStrings($normalized)) {
            usort($normalized, strcmp(...));
        }

        if (self::matchesAny($path, self::WILDCARD_LISTS) && self::containsOnlyStrings($normalized) && self::canSortWithWildcards($normalized)) {
            usort($normalized, self::compareWithWildcards(...));
        }

        return $normalized;
    }

    /**
     * @param list<mixed> $values
     *
     * @phpstan-assert-if-true list<string> $values
     */
    private static function containsOnlyStrings(array $values): bool
    {
        foreach ($values as $value) {
            if (!is_string($value)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<int|string, mixed> $properties numeric keys are int
     * @param list<string> $path
     * @return array<int|string, mixed>
     */
    private function sortProperties(
        array $properties,
        array $path,
    ): array
    {
        if (self::matchesAny($path, self::KEEP_ORDER)) {
            return $properties;
        }

        if (self::matchesAny($path, self::PACKAGE_LINKS)) {
            uksort($properties, static fn (int|string $a, int|string $b): int => strnatcmp(self::getPackageSortKey((string) $a), self::getPackageSortKey((string) $b)));
            return $properties;
        }

        if (self::matchesAny($path, self::WILDCARD_OBJECTS)) {
            if (self::canSortWithWildcards(array_keys($properties))) {
                uksort($properties, self::compareWithWildcards(...));
            }

            return $properties;
        }

        $schemaPositions = array_flip(self::getSchemaKeyOrder($path));

        uksort($properties, static function (int|string $a, int|string $b) use ($schemaPositions): int {
            $positionA = $schemaPositions[$a] ?? PHP_INT_MAX;
            $positionB = $schemaPositions[$b] ?? PHP_INT_MAX;

            if ($positionA !== $positionB) {
                return $positionA <=> $positionB;
            }

            // same as ksort() for numeric keys: 9 before 10
            return is_int($a) && is_int($b) ? $a <=> $b : strcmp((string) $a, (string) $b);
        });

        return $properties;
    }

    /**
     * Copy of Composer\Json\JsonManipulator::sortPackages()
     */
    private static function getPackageSortKey(string $packageName): string
    {
        if (preg_match(self::PLATFORM_PACKAGE_REGEX, $packageName) !== 1) {
            return '5-' . $packageName;
        }

        return preg_replace(
            ['/^php/', '/^hhvm/', '/^ext/', '/^lib/', '/^\D/'],
            ['0-$0', '1-$0', '2-$0', '3-$0', '4-$0'],
            $packageName,
        ) ?? throw new LogicException('Failed to compute sort key of ' . $packageName);
    }

    /**
     * Same as ergebnis/json-normalizer WildcardSorter: the order is kept when * is not only at the end.
     *
     * @param list<int|string> $values
     */
    private static function canSortWithWildcards(array $values): bool
    {
        foreach ($values as $value) {
            if (str_contains(rtrim((string) $value, '*'), '*')) {
                return false;
            }
        }

        return true;
    }

    /**
     * ~ sorts after all characters of package names, so a/* goes after a/b
     */
    private static function compareWithWildcards(
        int|string $a,
        int|string $b,
    ): int
    {
        return strcmp(str_replace('*', '~', (string) $a), str_replace('*', '~', (string) $b));
    }

    /**
     * @param list<string> $path
     * @return list<string>
     */
    private static function getSchemaKeyOrder(array $path): array
    {
        foreach (self::SCHEMA_KEY_ORDER as $pattern => $keyOrder) {
            if (self::matches($path, $pattern)) {
                return $keyOrder;
            }
        }

        return [];
    }

    /**
     * @param list<string> $path
     * @param list<string> $patterns
     */
    private static function matchesAny(
        array $path,
        array $patterns,
    ): bool
    {
        foreach ($patterns as $pattern) {
            if (self::matches($path, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $path
     */
    private static function matches(
        array $path,
        string $pattern,
    ): bool
    {
        $patternSegments = $pattern === '' ? [] : explode('/', $pattern);

        if (count($patternSegments) !== count($path)) {
            return false;
        }

        foreach ($patternSegments as $index => $patternSegment) {
            if ($patternSegment !== '*' && $patternSegment !== ($path[$index] ?? null)) {
                return false;
            }
        }

        return true;
    }

}
