<?php declare(strict_types = 1);

namespace ShipMonk\ComposerNormalizer;

use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;
use function array_keys;
use function array_pop;
use function array_reverse;
use function count;
use function file_get_contents;
use function fnmatch;
use function get_object_vars;
use function implode;
use function in_array;
use function is_array;
use function is_string;
use function json_decode;
use function json_encode;
use function str_starts_with;
use function strcmp;
use function strlen;
use function substr;
use function usort;
use const FNM_PATHNAME;
use const JSON_THROW_ON_ERROR;

/**
 * Checks the key order and the removal of empty keys of every object that the Composer schema defines.
 *
 * tests/data/composer-schema.json is res/composer-schema.json of composer/composer (MIT license),
 * commit c1722067b98b from 2026-10-22.
 */
final class ComposerSchemaTest extends TestCase
{

    private const MAX_DEPTH = 20;

    /**
     * Composer reads an empty value of these keys differently from a missing key.
     */
    private const KEEP_EMPTY = [
        'repositories/*/only',
        'repositories/*/only-branches',
        'config/github-protocols',
        'config/forgejo-domains',
        'config/policy/advisories',
        'config/policy/abandoned',
        'config/policy/ignore-unreachable',
    ];

    /**
     * @param list<string> $path * is an object key, # is a list item
     * @param list<string> $keys
     */
    #[DataProvider('provideKeyOrders')]
    public function testKeyOrderMatchesSchema(
        array $path,
        array $keys,
    ): void
    {
        $object = new stdClass();

        foreach (array_reverse($keys) as $key) {
            $object->{$key} = true;
        }

        $normalizer = new ComposerJsonNormalizer();
        $normalized = $normalizer->normalize(json_encode(self::wrap($object, $path), JSON_THROW_ON_ERROR));

        if ($path === ['config']) {
            usort($keys, strcmp(...)); // keys of config are in alphabetical order, same as in ergebnis/composer-normalize
        }

        self::assertSame($keys, array_keys(get_object_vars(self::unwrap(json_decode($normalized, flags: JSON_THROW_ON_ERROR), $path))));
    }

    /**
     * @return array<string, array{list<string>, list<string>}>
     */
    public static function provideKeyOrders(): array
    {
        $keyOrders = [];

        foreach (self::collectObjects() as $name => [$path, $properties]) {
            $keyOrders[$name] = [$path, array_keys($properties)];
        }

        return $keyOrders;
    }

    /**
     * @param list<string> $path * is an object key, # is a list item
     * @param array<string, stdClass|list<never>> $emptyValues
     * @param list<string> $keptKeys
     */
    #[DataProvider('provideEmptyValues')]
    public function testRemovesEmptyOptionalKeys(
        array $path,
        array $emptyValues,
        array $keptKeys,
    ): void
    {
        $object = new stdClass();
        $object->{'x-custom'} = true; // keeps the object

        foreach ($emptyValues as $key => $emptyValue) {
            $object->{$key} = $emptyValue;
        }

        $normalizer = new ComposerJsonNormalizer();
        $normalized = $normalizer->normalize(json_encode(self::wrap($object, $path), JSON_THROW_ON_ERROR));

        self::assertEqualsCanonicalizing(['x-custom', ...$keptKeys], array_keys(get_object_vars(self::unwrap(json_decode($normalized, flags: JSON_THROW_ON_ERROR), $path))));
    }

    /**
     * @return array<string, array{list<string>, array<string, stdClass|list<never>>, list<string>}>
     */
    public static function provideEmptyValues(): array
    {
        $cases = [];

        foreach (self::collectObjects() as $name => [$path, $properties, $required]) {
            $emptyValues = [];
            $keptKeys = [];

            foreach ($properties as $key => $types) {
                if (in_array('object', $types, true)) {
                    $emptyValues[$key] = new stdClass();
                } elseif (in_array('array', $types, true)) {
                    $emptyValues[$key] = [];
                } else {
                    continue;
                }

                if (in_array($key, $required, true) || self::matchesAny(implode('/', [...$path, $key]), self::KEEP_EMPTY)) {
                    $keptKeys[] = $key;
                }
            }

            if ($emptyValues !== []) {
                $cases[$name] = [$path, $emptyValues, $keptKeys];
            }
        }

        return $cases;
    }

    /**
     * @param list<string> $patterns
     */
    private static function matchesAny(
        string $keyPath,
        array $patterns,
    ): bool
    {
        foreach ($patterns as $pattern) {
            if (fnmatch($pattern, $keyPath, FNM_PATHNAME)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, array{list<string>, array<string, list<string>>, list<string>}> path, types of each key, required keys
     */
    private static function collectObjects(): array
    {
        $schema = json_decode((string) file_get_contents(__DIR__ . '/data/composer-schema.json'), flags: JSON_THROW_ON_ERROR);

        if (!$schema instanceof stdClass) {
            throw new LogicException('Schema is not an object');
        }

        $objects = [];
        self::collectObjectsAt($schema, $schema, [], $objects);

        return $objects;
    }

    /**
     * @param list<string> $path
     * @param array<string, array{list<string>, array<string, list<string>>, list<string>}> $objects
     */
    private static function collectObjectsAt(
        stdClass $schema,
        mixed $node,
        array $path,
        array &$objects,
    ): void
    {
        if (count($path) > self::MAX_DEPTH) {
            throw new LogicException('Schema is too deep at ' . implode('/', $path));
        }

        $node = self::resolveReference($schema, $node);

        if (!$node instanceof stdClass) {
            return;
        }

        foreach (['anyOf', 'oneOf', 'allOf'] as $keyword) {
            $branches = $node->{$keyword} ?? [];

            foreach (is_array($branches) ? $branches : [] as $branch) {
                self::collectObjectsAt($schema, $branch, $path, $objects);
            }
        }

        $properties = $node->properties ?? null;

        if ($properties instanceof stdClass) {
            $types = [];

            foreach (get_object_vars($properties) as $key => $propertySchema) {
                $types[(string) $key] = self::getTypes($schema, $propertySchema);
                self::collectObjectsAt($schema, $propertySchema, [...$path, (string) $key], $objects);
            }

            $objects[($path === [] ? '(root)' : implode('/', $path)) . ': ' . implode(', ', array_keys($types))] = [$path, $types, self::getStrings($node->required ?? [])];
        }

        $items = $node->items ?? null;

        if ($items instanceof stdClass) {
            self::collectObjectsAt($schema, $items, [...$path, '#'], $objects);
        }

        $additionalProperties = $node->additionalProperties ?? null;

        if ($additionalProperties instanceof stdClass) {
            self::collectObjectsAt($schema, $additionalProperties, [...$path, '*'], $objects);
        }
    }

    /**
     * @return list<string>
     */
    private static function getTypes(
        stdClass $schema,
        mixed $node,
    ): array
    {
        $node = self::resolveReference($schema, $node);

        if (!$node instanceof stdClass) {
            return [];
        }

        $types = self::getStrings($node->type ?? []);

        foreach (['anyOf', 'oneOf'] as $keyword) {
            $branches = $node->{$keyword} ?? [];

            foreach (is_array($branches) ? $branches : [] as $branch) {
                $types = [...$types, ...self::getTypes($schema, $branch)];
            }
        }

        return $types;
    }

    /**
     * @return list<string>
     */
    private static function getStrings(mixed $value): array
    {
        $strings = [];

        foreach (is_array($value) ? $value : [$value] as $item) {
            if (is_string($item)) {
                $strings[] = $item;
            }
        }

        return $strings;
    }

    private static function resolveReference(
        stdClass $schema,
        mixed $node,
    ): mixed
    {
        if (!$node instanceof stdClass) {
            return $node;
        }

        $reference = $node->{'$ref'} ?? null;

        if (!is_string($reference)) {
            return $node;
        }

        $prefix = '#/definitions/';
        $definitions = $schema->definitions ?? null;

        if (!str_starts_with($reference, $prefix) || !$definitions instanceof stdClass) {
            throw new LogicException("Unsupported reference {$reference}");
        }

        return self::resolveReference($schema, $definitions->{substr($reference, strlen($prefix))} ?? null);
    }

    /**
     * @param list<string> $path
     */
    private static function wrap(
        mixed $value,
        array $path,
    ): mixed
    {
        $segment = array_pop($path);

        if ($segment === null) {
            return $value;
        }

        if ($segment === '#') {
            return self::wrap([$value], $path);
        }

        $wrapper = new stdClass();
        $wrapper->{$segment === '*' ? 'any' : $segment} = $value;

        return self::wrap($wrapper, $path);
    }

    /**
     * @param list<string> $path
     */
    private static function unwrap(
        mixed $value,
        array $path,
    ): stdClass
    {
        foreach ($path as $segment) {
            if ($segment === '#') {
                self::assertIsArray($value);
                $value = $value[0] ?? null;
                continue;
            }

            self::assertInstanceOf(stdClass::class, $value);
            $value = $value->{$segment === '*' ? 'any' : $segment} ?? null;
        }

        self::assertInstanceOf(stdClass::class, $value);
        return $value;
    }

}
