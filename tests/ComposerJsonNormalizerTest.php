<?php declare(strict_types = 1);

namespace ShipMonk\ComposerNormalizer;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use function file_get_contents;
use function implode;
use function ini_get;
use function ini_set;
use function str_replace;

final class ComposerJsonNormalizerTest extends TestCase
{

    public function testNormalizesAllSections(): void
    {
        // composer.sorted.json is the output of ergebnis/composer-normalize 2.55.0 for composer.unsorted.json
        $unsorted = (string) file_get_contents(__DIR__ . '/data/composer.unsorted.json');
        $sorted = (string) file_get_contents(__DIR__ . '/data/composer.sorted.json');

        $normalizer = new ComposerJsonNormalizer();

        self::assertSame($sorted, $normalizer->normalize($unsorted));
        self::assertSame($sorted, $normalizer->normalize($sorted));
    }

    /**
     * @param list<string> $input
     * @param list<string> $expected
     */
    #[DataProvider('provideCases')]
    public function testNormalize(
        array $input,
        array $expected,
    ): void
    {
        $normalizer = new ComposerJsonNormalizer();
        $expectedJson = implode("\n", $expected) . "\n";

        self::assertSame($expectedJson, $normalizer->normalize(implode("\n", $input)));
        self::assertSame($expectedJson, $normalizer->normalize($expectedJson));
    }

    /**
     * @return iterable<string, array{list<string>, list<string>}>
     */
    public static function provideCases(): iterable
    {
        yield 'root keys in schema order, unknown keys last in alphabetical order' => [
            ['{', '    "z-custom": 1,', '    "a-custom": 2,', '    "require": {},', '    "name": "a/a"', '}'],
            ['{', '    "name": "a/a",', '    "require": {},', '    "a-custom": 2,', '    "z-custom": 1', '}'],
        ];

        yield 'package links like composer sort-packages' => [
            ['{', '    "require": {', '        "b/b": "1",', '        "a/a10": "1",', '        "a/a9": "1",', '        "ext-json": "*",', '        "php": "^8.1"', '    }', '}'],
            ['{', '    "require": {', '        "php": "^8.1",', '        "ext-json": "*",', '        "a/a9": "1",', '        "a/a10": "1",', '        "b/b": "1"', '    }', '}'],
        ];

        yield 'composer is a platform package, same as in composer sort-packages' => [
            ['{', '    "require": {', '        "b/b": "1",', '        "composer-runtime-api": "^2",', '        "composer": "^2",', '        "php": "^8.1"', '    }', '}'],
            ['{', '    "require": {', '        "php": "^8.1",', '        "composer": "^2",', '        "composer-runtime-api": "^2",', '        "b/b": "1"', '    }', '}'],
        ];

        yield 'nested objects in alphabetical order, numbers as ksort()' => [
            ['{', '    "extra": {', '        "b": {', '            "10": 1,', '            "9": 2,', '            "a": 3', '        },', '        "a": 1', '    }', '}'],
            ['{', '    "extra": {', '        "a": 1,', '        "b": {', '            "9": 2,', '            "10": 1,', '            "a": 3', '        }', '    }', '}'],
        ];

        yield 'numeric keys first, then other keys' => [
            ['{"extra": {"1a": 1, "10": 2, "a": 3, "9": 4}}'],
            ['{', '    "extra": {', '        "9": 4,', '        "10": 2,', '        "1a": 1,', '        "a": 3', '    }', '}'],
        ];

        yield 'bin with other values than strings keeps order' => [
            ['{"bin": ["b", 1, "a"]}'],
            ['{', '    "bin": [', '        "b",', '        1,', '        "a"', '    ]', '}'],
        ];

        yield 'lists keep their order, except bin' => [
            ['{', '    "keywords": ["b", "a"],', '    "bin": ["bin/b", "bin/a"]', '}'],
            ['{', '    "keywords": [', '        "b",', '        "a"', '    ],', '    "bin": [', '        "bin/a",', '        "bin/b"', '    ]', '}'],
        ];

        yield 'allow-plugins with wildcard in the middle keeps order' => [
            ['{', '    "config": {', '        "allow-plugins": {', '            "b/*-plugin": true,', '            "a/a": true', '        }', '    }', '}'],
            ['{', '    "config": {', '        "allow-plugins": {', '            "b/*-plugin": true,', '            "a/a": true', '        }', '    }', '}'],
        ];

        yield 'allow-plugins keeps order when sorting changes the matching key' => [
            ['{', '    "config": {', '        "allow-plugins": {', '            "*": false,', '            "a/a": true', '        }', '    }', '}'],
            ['{', '    "config": {', '        "allow-plugins": {', '            "*": false,', '            "a/a": true', '        }', '    }', '}'],
        ];

        yield 'preferred-install keeps order when sorting changes the matching key' => [
            ['{', '    "config": {', '        "preferred-install": {', '            "a/*": "dist",', '            "A/b": "source"', '        }', '    }', '}'],
            ['{', '    "config": {', '        "preferred-install": {', '            "a/*": "dist",', '            "A/b": "source"', '        }', '    }', '}'],
        ];

        yield 'allow-plugins sorts wildcard with the same value' => [
            ['{', '    "config": {', '        "allow-plugins": {', '            "a/*": true,', '            "b/b": false,', '            "a/a": true', '        }', '    }', '}'],
            ['{', '    "config": {', '        "allow-plugins": {', '            "a/a": true,', '            "a/*": true,', '            "b/b": false', '        }', '    }', '}'],
        ];

        yield 'order-sensitive objects keep order' => [
            ['{', '    "repositories": {', '        "z": {"url": "../z", "type": "path"},', '        "a": false', '    },', '    "scripts": {"auto-scripts": {"z": "x", "a": "x"}}', '}'],
            ['{', '    "repositories": {', '        "z": {', '            "type": "path",', '            "url": "../z"', '        },', '        "a": false', '    },', '    "scripts": {', '        "auto-scripts": {', '            "z": "x",', '            "a": "x"', '        }', '    }', '}'],
        ];

        yield 'scripts: events first, then other scripts in alphabetical order' => [
            ['{', '    "scripts": {', '        "test": "phpunit",', '        "post-update-cmd": "x",', '        "build": "x",', '        "post-install-cmd": "x"', '    }', '}'],
            ['{', '    "scripts": {', '        "post-install-cmd": "x",', '        "post-update-cmd": "x",', '        "build": "x",', '        "test": "phpunit"', '    }', '}'],
        ];

        yield 'values are kept' => [
            ['{"extra": {"float": 1.0, "unicode": "é", "slash": "a\/b", "object": {}, "list": [], "0": "zero", "": "empty"}}'],
            ['{', '    "extra": {', '        "0": "zero",', '        "": "empty",', '        "float": 1.0,', '        "list": [],', '        "object": {},', '        "slash": "a/b",', '        "unicode": "é"', '    }', '}'],
        ];

        yield 'line terminators stay unescaped' => [
            ["{\"description\": \"a\u{2028}b\\u2029c\"}"],
            ['{', "    \"description\": \"a\u{2028}b\u{2029}c\"", '}'],
        ];

        yield 'empty object' => [
            ['{}'],
            ['{}'],
        ];
    }

    #[DataProvider('provideIndents')]
    public function testKeepsIndent(string $indent): void
    {
        $input = "{\n{$indent}\"require\": {\n{$indent}{$indent}\"b/b\": \"1\",\n{$indent}{$indent}\"a/a\": \"1\"\n{$indent}},\n{$indent}\"name\": \"a/a\"\n}\n";
        $expected = "{\n{$indent}\"name\": \"a/a\",\n{$indent}\"require\": {\n{$indent}{$indent}\"a/a\": \"1\",\n{$indent}{$indent}\"b/b\": \"1\"\n{$indent}}\n}\n";

        $normalizer = new ComposerJsonNormalizer();

        self::assertSame($expected, $normalizer->normalize($input));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideIndents(): iterable
    {
        yield 'two spaces' => ['  '];
        yield 'four spaces' => ['    '];
        yield 'tab' => ["\t"];
    }

    #[DataProvider('provideNewLines')]
    public function testKeepsNewLine(string $newLine): void
    {
        $input = "{\n  \"require\": {},\n  \"name\": \"a/a\"\n}\n";
        $expected = "{\n  \"name\": \"a/a\",\n  \"require\": {}\n}\n";

        $normalizer = new ComposerJsonNormalizer();

        self::assertSame(
            str_replace("\n", $newLine, $expected),
            $normalizer->normalize(str_replace("\n", $newLine, $input)),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideNewLines(): iterable
    {
        yield 'LF' => ["\n"];
        yield 'CRLF' => ["\r\n"];
        yield 'CR' => ["\r"];
    }

    public function testAddsFinalNewLine(): void
    {
        $normalizer = new ComposerJsonNormalizer();

        self::assertSame("{\n    \"name\": \"a/a\"\n}\n", $normalizer->normalize("{\n    \"name\": \"a/a\"\n}"));
    }

    public function testKeyOrderDoesNotDependOnInputOrder(): void
    {
        $normalizer = new ComposerJsonNormalizer();
        $expected = $normalizer->normalize('{"extra": {"9": 1, "10": 2, "1a": 3}}');

        foreach (['{"extra": {"10": 2, "1a": 3, "9": 1}}', '{"extra": {"1a": 3, "9": 1, "10": 2}}', '{"extra": {"1a": 3, "10": 2, "9": 1}}'] as $input) {
            self::assertSame($expected, $normalizer->normalize($input));
        }
    }

    public function testIgnoresSerializePrecisionSetting(): void
    {
        $normalizer = new ComposerJsonNormalizer();
        $previousSerializePrecision = ini_set('serialize_precision', '17');

        try {
            self::assertSame("{\n    \"extra\": 0.1\n}\n", $normalizer->normalize('{"extra": 0.1}'));
            self::assertSame('17', ini_get('serialize_precision'));
        } finally {
            ini_set('serialize_precision', (string) $previousSerializePrecision);
        }
    }

    public function testNumberOutOfRange(): void
    {
        $normalizer = new ComposerJsonNormalizer();

        $this->expectException(InvalidComposerJsonException::class);
        $this->expectExceptionMessage('Cannot encode JSON: Inf and NaN cannot be JSON encoded');

        $normalizer->normalize('{"extra": 1e400}');
    }

    public function testInvalidJson(): void
    {
        $normalizer = new ComposerJsonNormalizer();

        $this->expectException(InvalidComposerJsonException::class);
        $this->expectExceptionMessage('Invalid JSON: Syntax error');

        $normalizer->normalize('{"name": ');
    }

    public function testJsonWithoutObject(): void
    {
        $normalizer = new ComposerJsonNormalizer();

        $this->expectException(InvalidComposerJsonException::class);
        $this->expectExceptionMessage('Expected a JSON object at the top level');

        $normalizer->normalize('[]');
    }

}
