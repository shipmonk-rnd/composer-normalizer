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
            ['{', '    "z-custom": 1,', '    "a-custom": 2,', '    "type": "library",', '    "name": "a/a"', '}'],
            ['{', '    "name": "a/a",', '    "type": "library",', '    "a-custom": 2,', '    "z-custom": 1', '}'],
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

        yield 'extension names in lowercase, duplicates merged' => [
            ['{"require": {"ext-JSON": "^2.0", "php": "^8.1", "ext-json": "^1.0", "ext-Zend OPcache": "*", "Vendor/Package": "^1.0"}}'],
            ['{', '    "require": {', '        "php": "^8.1",', '        "ext-json": "^2.0 || ^1.0",', '        "ext-zend-opcache": "*",', '        "Vendor/Package": "^1.0"', '    }', '}'],
        ];

        yield 'same values of duplicate extension names merged into one' => [
            ['{"conflict": {"ext-json": "<1", "ext-JSON": "<1", "EXT-JSON": ">3"}}'],
            ['{', '    "conflict": {', '        "ext-json": "<1 || >3"', '    }', '}'],
        ];

        yield 'duplicate extension names in suggest merged' => [
            ['{"suggest": {"ext-Redis": "for cache", "ext-redis": "for cache", "EXT-REDIS": "for sessions"}}'],
            ['{', '    "suggest": {', '        "ext-redis": "for cache || for sessions"', '    }', '}'],
        ];

        yield 'duplicate extension names with other values than strings stay' => [
            ['{"require": {"ext-json": 1, "ext-JSON": "*", "ext-MBSTRING": "*", "ext-INTL": true}}'],
            ['{', '    "require": {', '        "ext-intl": true,', '        "ext-json": 1,', '        "ext-JSON": "*",', '        "ext-mbstring": "*"', '    }', '}'],
        ];

        yield 'duplicate extension names with self.version stay' => [
            ['{"replace": {"ext-json": "^1.0", "ext-JSON": "self.version"}}'],
            ['{', '    "replace": {', '        "ext-json": "^1.0",', '        "ext-JSON": "self.version"', '    }', '}'],
        ];

        yield 'duplicate extension names keep order when self.version comes first' => [
            ['{"replace": {"ext-JSON": "self.version", "ext-json": "^1.0"}}'],
            ['{', '    "replace": {', '        "ext-JSON": "self.version",', '        "ext-json": "^1.0"', '    }', '}'],
        ];

        yield 'unmerged extension groups sort without changing their internal order' => [
            ['{"replace": {"z/z": "*", "ext-json": "^1.0", "php": "^8.1", "ext-MBSTRING": "self.version", "ext-JSON": "self.version", "ext-mbstring": "^2.0"}}'],
            ['{', '    "replace": {', '        "php": "^8.1",', '        "ext-json": "^1.0",', '        "ext-JSON": "self.version",', '        "ext-MBSTRING": "self.version",', '        "ext-mbstring": "^2.0",', '        "z/z": "*"', '    }', '}'],
        ];

        yield 'names of other packages stay' => [
            ['{"require": {"EXT-": "*", "PHP": "^8.1", "lib-ICU": "*"}}'],
            ['{', '    "require": {', '        "lib-ICU": "*",', '        "PHP": "^8.1",', '        "EXT-": "*"', '    }', '}'],
        ];

        yield 'extension names outside of package links stay' => [
            ['{"extra": {"ext-JSON": "*"}, "repositories": [{"type": "package", "package": {"name": "a/a", "version": "1.0.0", "require": {"ext-JSON": "*"}}}]}'],
            ['{', '    "repositories": [', '        {', '            "type": "package",', '            "package": {', '                "name": "a/a",', '                "version": "1.0.0",', '                "require": {', '                    "ext-JSON": "*"', '                }', '            }', '        }', '    ],', '    "extra": {', '        "ext-JSON": "*"', '    }', '}'],
        ];

        yield 'bin with other values than strings keeps order' => [
            ['{"bin": ["b", 1, "a"]}'],
            ['{', '    "bin": [', '        "b",', '        1,', '        "a"', '    ]', '}'],
        ];

        yield 'bin with conflicting basenames keeps order' => [
            ['{"bin": ["scripts/tool", "bin/tool"]}'],
            ['{', '    "bin": [', '        "scripts/tool",', '        "bin/tool"', '    ]', '}'],
        ];

        yield 'bin with conflicting basenames in the opposite order keeps order' => [
            ['{"bin": ["bin/tool", "scripts/tool"]}'],
            ['{', '    "bin": [', '        "bin/tool",', '        "scripts/tool"', '    ]', '}'],
        ];

        yield 'bin with case-insensitive basename conflicts keeps order' => [
            ['{"bin": ["scripts/tool", "bin/TOOL"]}'],
            ['{', '    "bin": [', '        "scripts/tool",', '        "bin/TOOL"', '    ]', '}'],
        ];

        yield 'bin with Windows path separator conflicts keeps order' => [
            ['{"bin": ["scripts\\\\tool", "bin/tool"]}'],
            ['{', '    "bin": [', '        "scripts\\\\tool",', '        "bin/tool"', '    ]', '}'],
        ];

        yield 'bin with Windows proxy conflicts keeps order' => [
            ['{"bin": ["scripts/tool.bat", "bin/tool"]}'],
            ['{', '    "bin": [', '        "scripts/tool.bat",', '        "bin/tool"', '    ]', '}'],
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

        yield 'empty optional keys are removed' => [
            ['{"keywords": [], "require-dev": {}, "autoload": {"psr-4": {}}, "config": {"platform": {}}, "scripts": {"post-install-cmd": [], "test": []}, "extra": {"a": {}}}'],
            ['{', '    "extra": {', '        "a": {}', '    },', '    "scripts": {', '        "test": []', '    }', '}'],
        ];

        yield 'empty values stay where Composer reads them differently from a missing key' => [
            ['{"repositories": [{"type": "composer", "url": "https://a", "only": [], "options": {}}], "config": {"github-protocols": [], "policy": {"advisories": {}, "malware": {}}}}'],
            ['{', '    "repositories": [', '        {', '            "type": "composer",', '            "url": "https://a",', '            "only": []', '        }', '    ],', '    "config": {', '        "github-protocols": [],', '        "policy": {', '            "advisories": {},', '            "malware": {}', '        }', '    }', '}'],
        ];

        yield 'kept empty value after its content is removed' => [
            ['{"config": {"policy": {"advisories": {"ignore": {}}}}}'],
            ['{', '    "config": {', '        "policy": {', '            "advisories": {}', '        }', '    }', '}'],
        ];

        yield 'config keys that the schema does not define stay empty' => [
            ['{"config": {"x-custom": [], "0": {}, "platform": {}}}'],
            ['{', '    "config": {', '        "0": {},', '        "x-custom": []', '    }', '}'],
        ];

        yield 'list items and keys of order-sensitive objects stay empty' => [
            ['{"authors": [{}], "extra": {"installer-paths": {}}, "scripts": {"auto-scripts": {}}}'],
            ['{', '    "authors": [', '        {}', '    ],', '    "extra": {', '        "installer-paths": {}', '    },', '    "scripts": {', '        "auto-scripts": {}', '    }', '}'],
        ];

        yield 'null values stay' => [
            ['{"require-dev": null}'],
            ['{', '    "require-dev": null', '}'],
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
        $input = "{\n  \"type\": \"library\",\n  \"name\": \"a/a\"\n}\n";
        $expected = "{\n  \"name\": \"a/a\",\n  \"type\": \"library\"\n}\n";

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
