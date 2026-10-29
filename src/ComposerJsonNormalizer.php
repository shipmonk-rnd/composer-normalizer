<?php declare(strict_types = 1);

namespace ShipMonk\ComposerNormalizer;

use JsonException;
use LogicException;
use stdClass;
use function array_flip;
use function array_keys;
use function array_slice;
use function count;
use function explode;
use function get_object_vars;
use function in_array;
use function ini_set;
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
use function str_starts_with;
use function strcmp;
use function strlen;
use function strnatcmp;
use function strtolower;
use function uksort;
use function usort;
use const JSON_PRESERVE_ZERO_FRACTION;
use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_LINE_TERMINATORS;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;
use const PHP_INT_MAX;

/**
 * Sorts keys of composer.json in the key order of ergebnis/composer-normalize and removes empty optional keys, README.md lists the differences.
 *
 * Paths below use / as separator, * matches any object key or list index, # matches any list index.
 */
final class ComposerJsonNormalizer
{

    private const DEFAULT_INDENT = '    ';
    private const DEFAULT_NEW_LINE = "\n";

    private const AUTHOR_KEYS = ['name', 'email', 'homepage', 'role'];
    private const AUTOLOAD_KEYS = ['psr-0', 'psr-4', 'classmap', 'files', 'exclude-from-classmap'];
    private const SOURCE_KEYS = ['type', 'url', 'reference', 'mirrors'];
    private const DIST_KEYS = ['type', 'url', 'reference', 'shasum', 'mirrors'];
    private const INLINE_PACKAGE_KEYS = [
        'name',
        'type',
        'target-dir',
        'description',
        'keywords',
        'homepage',
        'version',
        'time',
        'license',
        'authors',
        'require',
        'replace',
        'conflict',
        'provide',
        'require-dev',
        'suggest',
        'extra',
        'autoload',
        'archive',
        'bin',
        'include-path',
        'source',
        'dist',
    ];
    private const AUDIT_IGNORE_KEYS = ['apply', 'reason'];
    private const POLICY_IGNORE_KEYS = ['constraint', 'reason', 'on-block', 'on-audit'];
    private const POLICY_IGNORE_ID_KEYS = ['reason', 'on-block', 'on-audit'];
    private const USERNAME_TOKEN_KEYS = ['username', 'token'];

    /**
     * Key order defined by the Composer schema (https://getcomposer.org/schema.json), tests check it against tests/data/composer-schema.json.
     * Keys that are not listed go after the listed keys, in alphabetical order.
     * Objects that are not listed here have all keys in alphabetical order.
     * The first matching path is used.
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
        'authors/#' => self::AUTHOR_KEYS,
        'support' => ['email', 'issues', 'forum', 'wiki', 'irc', 'chat', 'source', 'docs', 'rss', 'security'],
        'funding/#' => ['type', 'url'],
        'source' => self::SOURCE_KEYS,
        'dist' => self::DIST_KEYS,
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
        // the package repository has one package or a list of packages
        'repositories/*/package' => self::INLINE_PACKAGE_KEYS,
        'repositories/*/package/authors/#' => self::AUTHOR_KEYS,
        'repositories/*/package/autoload' => self::AUTOLOAD_KEYS,
        'repositories/*/package/archive' => ['exclude'],
        'repositories/*/package/source' => self::SOURCE_KEYS,
        'repositories/*/package/dist' => self::DIST_KEYS,
        'repositories/*/package/#' => self::INLINE_PACKAGE_KEYS,
        'repositories/*/package/#/authors/#' => self::AUTHOR_KEYS,
        'repositories/*/package/#/autoload' => self::AUTOLOAD_KEYS,
        'repositories/*/package/#/archive' => ['exclude'],
        'repositories/*/package/#/source' => self::SOURCE_KEYS,
        'repositories/*/package/#/dist' => self::DIST_KEYS,
        'autoload' => self::AUTOLOAD_KEYS,
        'autoload-dev' => self::AUTOLOAD_KEYS,
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
        'php-ext/configure-options/#' => ['name', 'needs-value', 'description'],
        // keys of config itself are in alphabetical order, same as in ergebnis/composer-normalize
        'config/audit' => [
            'ignore',
            'abandoned',
            'ignore-severity',
            'ignore-unreachable',
            'block-insecure',
            'block-abandoned',
            'ignore-abandoned',
        ],
        'config/audit/ignore/*' => self::AUDIT_IGNORE_KEYS,
        'config/audit/ignore-abandoned/*' => self::AUDIT_IGNORE_KEYS,
        'config/audit/ignore-severity/*' => ['apply'],
        'config/policy' => ['advisories', 'malware', 'abandoned', 'cooldown', 'ignore-unreachable'],
        'config/policy/advisories' => ['block', 'audit', 'ignore-id', 'ignore', 'ignore-severity'],
        'config/policy/advisories/ignore-id/*' => self::POLICY_IGNORE_ID_KEYS,
        'config/policy/advisories/ignore-severity/*' => self::POLICY_IGNORE_ID_KEYS,
        'config/policy/malware' => ['block', 'block-scope', 'audit', 'ignore', 'ignore-source'],
        'config/policy/abandoned' => ['block', 'audit', 'ignore'],
        'config/policy/cooldown' => ['period', 'block', 'audit', 'ignore'],
        'config/policy/*' => ['block', 'block-scope', 'audit', 'sources', 'ignore'], // custom policies
        'config/policy/*/sources/#' => ['type', 'url'],
        'config/policy/*/ignore/*' => self::POLICY_IGNORE_KEYS,
        'config/policy/*/ignore/*/#' => self::POLICY_IGNORE_KEYS,
        'config/gitlab-oauth/*' => ['expires-at', 'refresh-token', 'token'],
        'config/gitlab-token/*' => self::USERNAME_TOKEN_KEYS,
        'config/forgejo-token/*' => self::USERNAME_TOKEN_KEYS,
        'config/http-basic/*' => ['username', 'password'],
        'config/client-certificate/*' => ['local_cert', 'local_pk', 'passphrase'],
        'config/bitbucket-oauth/*' => ['consumer-key', 'consumer-secret', 'access-token', 'access-token-expiration'],
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
     * Composer uses the first key that matches a package name, so a key with * goes after the keys it matches.
     * The order is kept when sorting would change which key matches.
     */
    private const FIRST_MATCH_OBJECTS = [
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
     * Keys of config that can have an object or list value. Keys of config are in alphabetical order, so they are not in SCHEMA_KEY_ORDER.
     */
    private const CONFIG_OBJECT_AND_LIST_KEYS = [
        'platform',
        'allow-plugins',
        'preferred-install',
        'audit',
        'policy',
        'github-protocols',
        'github-oauth',
        'gitlab-oauth',
        'gitlab-token',
        'bearer',
        'custom-headers',
        'forgejo-token',
        'secure-svn-domains',
        'http-basic',
        'client-certificate',
        'github-domains',
        'gitlab-domains',
        'forgejo-domains',
        'bitbucket-oauth',
    ];

    /**
     * Keys of the schema are removed when the value is {} or [], except these.
     */
    private const KEEP_EMPTY = [
        'repositories/*/package', // required in package repositories
        'repositories/*/only', // matches no package
        'repositories/*/only-branches', // matches only the default branch
        'config/github-protocols', // replaces the default list
        'config/forgejo-domains', // replaces the default list
        'config/policy', // {} is the same as true, it turns on a policy that the global config turns off
        'config/policy/*', // same as above; advisories and abandoned also stop the fallback to config.audit; ignore-unreachable ignores nothing
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

        // -1 gives the shortest float that decodes to the same value, the ini can have another value
        $previousSerializePrecision = ini_set('serialize_precision', '-1');

        try {
            $encoded = json_encode(
                $this->normalizeObject($decoded, []),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $e) {
            throw new InvalidComposerJsonException('Cannot encode JSON: ' . $e->getMessage(), $e); // e.g. 1e400 is decoded as INF
        } finally {
            if ($previousSerializePrecision !== false) {
                ini_set('serialize_precision', $previousSerializePrecision);
            }
        }

        $indent = preg_match('~(?:^|[\r\n])( +|\t+)~', $json, $indentMatch) === 1 ? $indentMatch[1] : self::DEFAULT_INDENT;
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
     * @param list<int|string> $path
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
     * @param list<int|string> $path
     */
    private function normalizeObject(
        stdClass $object,
        array $path,
    ): stdClass
    {
        $normalized = new stdClass();

        foreach ($this->sortProperties(get_object_vars($object), $path) as $key => $value) {
            $normalizedValue = $this->normalizeValue($value, [...$path, (string) $key]);

            if (self::isEmpty($normalizedValue) && self::isRemovableWhenEmpty($path, $key)) {
                continue;
            }

            $normalized->{$key} = $normalizedValue;
        }

        return $normalized;
    }

    private static function isEmpty(mixed $value): bool
    {
        return $value === [] || ($value instanceof stdClass && get_object_vars($value) === []);
    }

    /**
     * Same as ergebnis/composer-normalize: only keys of the schema are removed.
     *
     * @param list<int|string> $path
     */
    private static function isRemovableWhenEmpty(
        array $path,
        int|string $key,
    ): bool
    {
        $schemaKeys = $path === ['config'] ? self::CONFIG_OBJECT_AND_LIST_KEYS : self::getSchemaKeyOrder($path);

        return in_array($key, $schemaKeys, true) && !self::matchesAny([...$path, $key], self::KEEP_EMPTY);
    }

    /**
     * @param array<mixed> $list
     * @param list<int|string> $path
     * @return list<mixed>
     */
    private function normalizeList(
        array $list,
        array $path,
    ): array
    {
        $normalized = [];

        foreach ($list as $index => $item) {
            $normalized[] = $this->normalizeValue($item, [...$path, $index]);
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
     * @param list<int|string> $path
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

        if (self::matchesAny($path, self::FIRST_MATCH_OBJECTS)) {
            return self::canSortFirstMatchKeys($properties) ? self::sortKeysWithWildcards($properties) : $properties;
        }

        $schemaPositions = array_flip(self::getSchemaKeyOrder($path));

        uksort($properties, static function (int|string $a, int|string $b) use ($schemaPositions): int {
            $positionA = $schemaPositions[$a] ?? PHP_INT_MAX;
            $positionB = $schemaPositions[$b] ?? PHP_INT_MAX;

            if ($positionA !== $positionB) {
                return $positionA <=> $positionB;
            }

            // numeric keys first, by value (9 before 10, same as ksort()), then other keys
            if (is_int($a) && is_int($b)) {
                return $a <=> $b;
            }

            if (is_int($a) || is_int($b)) {
                return is_int($a) ? -1 : 1;
            }

            return strcmp($a, $b);
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
     * @param array<int|string, mixed> $properties
     * @return array<int|string, mixed>
     */
    private static function sortKeysWithWildcards(array $properties): array
    {
        uksort($properties, self::compareWithWildcards(...));
        return $properties;
    }

    /**
     * Sorting is safe when no two keys that match the same package and have different values swap their order.
     *
     * @param array<int|string, mixed> $properties
     */
    private static function canSortFirstMatchKeys(array $properties): bool
    {
        if (!self::canSortWithWildcards(array_keys($properties))) {
            return false;
        }

        $entries = [];

        foreach ($properties as $key => $value) {
            $entries[] = [(string) $key, $value];
        }

        foreach ($entries as $index => [$key, $value]) {
            foreach (array_slice($entries, $index + 1) as [$laterKey, $laterValue]) {
                $swapsOrder = self::compareWithWildcards($key, $laterKey) > 0;

                if ($swapsOrder && $value !== $laterValue && self::canMatchSamePackage($key, $laterKey)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Works for keys with * only at the end; Composer matches them case-insensitively.
     */
    private static function canMatchSamePackage(
        string $a,
        string $b,
    ): bool
    {
        $a = strtolower($a);
        $b = strtolower($b);
        $prefixA = rtrim($a, '*');
        $prefixB = rtrim($b, '*');

        if ($prefixA !== $a && $prefixB !== $b) {
            return str_starts_with($prefixA, $prefixB) || str_starts_with($prefixB, $prefixA);
        }

        if ($prefixA !== $a) {
            return str_starts_with($b, $prefixA);
        }

        if ($prefixB !== $b) {
            return str_starts_with($a, $prefixB);
        }

        return $a === $b;
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
     * @param list<int|string> $path
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
     * @param list<int|string> $path
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
     * @param list<int|string> $path
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
            $segment = $path[$index] ?? null;
            $segmentMatches = match ($patternSegment) {
                '*' => true,
                '#' => is_int($segment),
                default => $segment === $patternSegment,
            };

            if (!$segmentMatches) {
                return false;
            }
        }

        return true;
    }

}
