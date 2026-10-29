# Composer normalize - sort keys of composer.json

- 🪶 **Zero dependencies:** a small CLI, not a Composer plugin
- 🔤 **Only sorts keys:** the same key order as [ergebnis/composer-normalize](https://github.com/ergebnis/composer-normalize), values stay as they are
- 📐 **Keeps your format:** indentation (spaces or tabs, any size) and new lines are taken from the file
- ✨ **Compatible:** PHP 8.1+

## Installation

```sh
composer require --dev shipmonk/composer-normalize
```

## Usage

Add it to the scripts that check and fix your coding standard:

```json
{
    "scripts": {
        "check:cs": [
            "composer-normalize --dry-run",
            "phpcs"
        ],
        "fix:cs": [
            "composer-normalize",
            "phpcbf"
        ]
    }
}
```

Put `composer-normalize` before `phpcbf`: `phpcbf` exits with code 1 when it fixes a file, and Composer then stops the script list.

You can also run it directly:

```sh
vendor/bin/composer-normalize                       # sorts composer.json in the working directory
vendor/bin/composer-normalize path/to/composer.json # sorts the given files
vendor/bin/composer-normalize --dry-run             # only checks, does not change files
```

| Exit code | Meaning                                                  |
|-----------|----------------------------------------------------------|
| 0         | All files are normalized (or were fixed)                 |
| 1         | `--dry-run` found a file that is not normalized          |
| 2         | Error: file cannot be read, invalid JSON, unknown option |

## Key order

- **Root keys** use the order of the [Composer schema](https://getcomposer.org/schema.json): `name`, `description`, `license`, `type`, ..., `config`, `extra`, `scripts`. Unknown keys go last, in alphabetical order.
- **Packages** in `require`, `require-dev`, `conflict`, `provide`, `replace` and `suggest` use the order of Composer's [`sort-packages`](https://getcomposer.org/doc/06-config.md#sort-packages): `php`, `ext-*`, `lib-*`, then other packages.
- **Known sections** use the schema order: `authors`, `support`, `funding`, `autoload`, `autoload-dev`, items of `repositories`, `archive`, `php-ext`, `config.audit`, `config.policy`. In `scripts`, Composer events (`post-install-cmd`, ...) go first, then your scripts in alphabetical order.
- **All other objects** are sorted alphabetically, recursively: `config`, `extra`, `autoload.psr-4`, ...
- **`config.allow-plugins` and `config.preferred-install`** are sorted alphabetically, but `vendor/*` goes after `vendor/package`, because Composer uses the first entry that matches.
- **The order stays** where the order has a meaning: `repositories`, `extra.installer-paths`, `extra.patches.*`, `scripts.auto-scripts` and all lists, except `bin`, `repositories.*.only` and `repositories.*.exclude`.

Output always ends with a new line. Slashes and unicode characters are not escaped (the same as when Composer writes `composer.json`).

## Differences from ergebnis/composer-normalize

This tool replaces `ergebnis/composer-normalize` when you only need a stable key order. A `composer.json` that ergebnis normalized does not change.

It does **not**:

- **register a Composer plugin**: there is no `composer normalize` command and no `allow-plugins` entry. The tool does not load Composer internals, so it does not block an upgrade of PHP or Composer.
- **change version constraints**: `^1.0|^2.0` stays as it is.
- **show a diff**: run it without `--dry-run` and use `git diff`.
- **validate the schema**: use `composer validate --strict`.
- **update `composer.lock`**: if the order in `require` changes, the `content-hash` in `composer.lock` changes too. Run `composer update --lock`.
- **have indent options**: the indentation comes from the file.

### Migration

```sh
composer remove --dev ergebnis/composer-normalize
composer require --dev shipmonk/composer-normalize
```

Then remove `ergebnis/composer-normalize` from `config.allow-plugins` and change your scripts:

| Before                                                          | After                          |
|-----------------------------------------------------------------|--------------------------------|
| `composer normalize --dry-run --no-check-lock --no-update-lock` | `composer-normalize --dry-run` |
| `composer normalize --no-check-lock --no-update-lock`           | `composer-normalize`           |

## Contributing

- Check your code with `composer check`
- Autofix coding-style with `composer fix:cs`
- All functionality must be tested
