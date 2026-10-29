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
use function get_object_vars;
use function implode;
use function is_array;
use function is_string;
use function json_decode;
use function json_encode;
use function str_starts_with;
use function strcmp;
use function strlen;
use function substr;
use function usort;
use const JSON_THROW_ON_ERROR;

/**
 * Checks the key order of every object that the Composer schema defines.
 *
 * tests/data/composer-schema.json is res/composer-schema.json of composer/composer (MIT license),
 * commit c1722067b98b from 2026-10-22.
 */
final class ComposerSchemaTest extends TestCase
{

    private const MAX_DEPTH = 20;

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
        $schema = json_decode((string) file_get_contents(__DIR__ . '/data/composer-schema.json'), flags: JSON_THROW_ON_ERROR);

        if (!$schema instanceof stdClass) {
            throw new LogicException('Schema is not an object');
        }

        $keyOrders = [];
        self::collectKeyOrders($schema, $schema, [], $keyOrders);

        return $keyOrders;
    }

    /**
     * @param list<string> $path
     * @param array<string, array{list<string>, list<string>}> $keyOrders
     */
    private static function collectKeyOrders(
        stdClass $schema,
        mixed $node,
        array $path,
        array &$keyOrders,
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
                self::collectKeyOrders($schema, $branch, $path, $keyOrders);
            }
        }

        $properties = $node->properties ?? null;

        if ($properties instanceof stdClass) {
            $keys = [];

            foreach (get_object_vars($properties) as $key => $propertySchema) {
                $keys[] = (string) $key;
                self::collectKeyOrders($schema, $propertySchema, [...$path, (string) $key], $keyOrders);
            }

            $keyOrders[($path === [] ? '(root)' : implode('/', $path)) . ': ' . implode(', ', $keys)] = [$path, $keys];
        }

        $items = $node->items ?? null;

        if ($items instanceof stdClass) {
            self::collectKeyOrders($schema, $items, [...$path, '#'], $keyOrders);
        }

        $additionalProperties = $node->additionalProperties ?? null;

        if ($additionalProperties instanceof stdClass) {
            self::collectKeyOrders($schema, $additionalProperties, [...$path, '*'], $keyOrders);
        }
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
