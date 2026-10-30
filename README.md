# Composer normalizer - sort keys of composer.json and remove empty keys

- 🪶 **Zero dependencies:** a small CLI, not a Composer plugin
- 🔤 **Sorts keys:** the key order of [ergebnis/composer-normalize](https://github.com/ergebnis/composer-normalize), without changes to your values, except for [duplicate extension names](#extension-names)
- 🧹 **Removes empty keys:** `"require-dev": {}`, `"keywords": []` and other empty optional keys
- 📐 **Keeps your format:** indentation (spaces or tabs, any size) and new lines are taken from the file
- ✨ **Compatible:** PHP 8.1+

## Installation

```sh
composer require --dev shipmonk/composer-normalizer
```

## Usage

Add it to the scripts that check and fix your coding standard:

```json
{
    "scripts": {
        "check:cs": [
            "composer-normalizer --dry-run",
            "phpcs"
        ],
        "fix:cs": [
            "composer-normalizer",
            "phpcbf"
        ]
    }
}
```

Put `composer-normalizer` before `phpcbf`: `phpcbf` exits with code 1 when it fixes a file, and Composer then stops the script list.

You can also run it directly:

```sh
vendor/bin/composer-normalizer                       # sorts composer.json in the working directory
vendor/bin/composer-normalizer path/to/composer.json # sorts the given files
vendor/bin/composer-normalizer --dry-run             # only checks, does not change files
```

Without a file, it uses the file from the `COMPOSER` environment variable, the same as Composer.

| Exit code | Meaning                                                             |
|-----------|---------------------------------------------------------------------|
| 0         | All files are normalized (or were fixed)                            |
| 1         | `--dry-run` found a file that is not normalized                     |
| 2         | Error: file cannot be read or written, invalid JSON, unknown option |

## Key order

- **Root keys** use the order of the [Composer schema](https://getcomposer.org/schema.json): `name`, `description`, `license`, `type`, ..., `config`, `extra`, `scripts`. Unknown keys go last, in alphabetical order.
- **Packages** in `require`, `require-dev`, `conflict`, `provide`, `replace` and `suggest` use the order of Composer's [`sort-packages`](https://getcomposer.org/doc/06-config.md#sort-packages): `php`, `ext-*`, `lib-*`, `composer` and its API packages, then other packages. Unmerged names that differ only in case keep their relative order.
- **Objects that the schema defines** use the schema order, for example `authors`, `support`, `autoload`, `source`, `dist`, items of `repositories` (also inline packages), `archive`, `php-ext`, `config.audit`, `config.policy` and `config.http-basic`. In `scripts`, Composer events (`post-install-cmd`, ...) go first, then your scripts in alphabetical order.
- **All other objects** are sorted alphabetically, recursively: `config`, `extra`, `autoload.psr-4`, ... Numeric keys go first, by value (`9` before `10`).
- **`config.allow-plugins` and `config.preferred-install`** are sorted alphabetically, but `vendor/*` goes after `vendor/package`. Composer uses the first entry that matches a package, so the order stays when sorting would change which entry matches.
- **The order stays** where the order has a meaning: `repositories`, `extra.installer-paths`, `extra.patches.*`, `scripts.auto-scripts` and all lists, except `bin`, `repositories.*.only` and `repositories.*.exclude`.
- **`bin`** is sorted only when the binaries have distinct destination names, including case-insensitive names and `.bat` proxies on Windows. Otherwise, the order stays: Composer installs the first binary with a given name.

## Empty keys

Optional keys of the [Composer schema](https://getcomposer.org/schema.json) are removed when their value is `{}` or `[]`, for example `"require-dev": {}`, `"keywords": []` or `"autoload": {"psr-4": {}}`. Other keys stay, for example keys in `extra`, your own scripts, `config` keys that the schema does not define and items of lists.

An empty value stays where Composer reads it differently from a missing key:

- `repositories.*.only`: an empty list matches no package
- `repositories.*.only-branches`: an empty list matches only the default branch
- `config.github-protocols` and `config.forgejo-domains`: an empty list replaces the default list
- `config.policy` and all keys in it: Composer reads `{}` as `true`, which turns a policy on, also when the global config turns it off. In `advisories` and `abandoned`, `{}` also stops the fallback to `config.audit`. An empty `ignore-unreachable` ignores nothing, the default ignores `install` and `update`.

Composer applies `config` on top of the global config (`COMPOSER_HOME/config.json`). An empty `config.platform`, `config.secure-svn-domains`, `config.audit.ignore-severity` or `config.audit.ignore-abandoned` replaces the global value. The tool removes these empty values, so the global value applies again.

## Extension names

In `require`, `require-dev`, `conflict`, `provide`, `replace` and `suggest`, names that start with `ext-` (in any case) are written in lowercase, with `-` instead of spaces, the same as Composer names the packages of PHP extensions: `ext-JSON` becomes `ext-json`. When this gives the same name two or more times, the values are joined with ` || `, in the order of the file. Equal values are written once:

```json
{
    "require": {
        "ext-JSON": "^1.0",
        "ext-json": "^2.0"
    }
}
```

becomes `"ext-json": "^1.0 || ^2.0"`. When one of the values is not a string or is `self.version`, the values cannot be joined and these names keep their spelling and relative order, so Composer still uses the same last entry.

The merge can change what Composer does. Composer uses only the last of the duplicate names (in `suggest`, it shows all of them). After the merge, `require` allows more versions and `conflict` blocks more versions. A new name can also make `composer validate` show a problem that the old name hid, for example `ext-json` in `replace` and `ext-JSON` in `conflict`. Check the result with `composer validate`.

## Format and values

- The output always ends with a new line.
- Slashes and unicode characters are not escaped, the same as when Composer writes `composer.json`.
- Values do not change, except for [duplicate extension names](#extension-names) and where the JSON functions of PHP cannot keep them:
  - numbers get their shortest form: `1e3` becomes `1000.0`, `1.50` becomes `1.5`, an integer larger than `PHP_INT_MAX` becomes a float
  - when an object has the same key two times, only the last one stays (Composer also uses the last one)

## Differences from ergebnis/composer-normalize

The key order is the same as in `ergebnis/composer-normalize` 2.55, except:

- `composer` goes with the platform packages, the same as when `composer require` sorts packages.
- Unmerged package names that differ only in case keep their relative order.
- `config.allow-plugins` and `config.preferred-install` keep their order when sorting would change which entry matches a package.
- `bin` keeps its order when destination names conflict, including Windows names and `.bat` proxies.
- Numeric keys always go before other keys.
- Repositories use one key order for all repository types. ergebnis puts keys that the schema does not define for the repository type last, for example `options` in a `vcs` repository. In repositories in object form (`"repositories": {"name": {...}}`), ergebnis sorts all keys alphabetically.

Empty keys are removed the same as in ergebnis, except:

- The empty values in the list in [Empty keys](#empty-keys) stay.
- Keys with the value `null` stay.
- In repositories, empty keys of all repository types are removed, also in repositories in object form. ergebnis removes only empty keys that the schema defines for the repository type, and none in the object form.

Extension names change the same as in ergebnis, except:

- The joined values keep the order of the file, and equal values are written once. ergebnis also normalizes the joined version constraints (when it can parse them, it sorts and simplifies them), and joins the descriptions in `suggest` with `||`.
- Values with `self.version` are not joined. Duplicate extension names that cannot be joined keep their relative order.

A `composer.json` that ergebnis normalized does not change, except in the cases above and when the file has no final new line, escaped slashes (`\/`) or escaped unicode characters (`\u00e9`).

This tool does **not**:

- **register a Composer plugin**: there is no `composer normalize` command and no `allow-plugins` entry. The tool does not load Composer internals, so it does not block an upgrade of PHP or Composer.
- **change values** (except for [duplicate extension names](#extension-names)): ergebnis also changes version constraints (`^1.0|^2.0` becomes `^1.0 || ^2.0`) and writes `1.0` as `1`.
- **show a diff**: run it without `--dry-run` and use `git diff`.
- **validate the schema**: use `composer validate --strict`.
- **update `composer.lock`**: the `content-hash` in `composer.lock` depends on `require`, `require-dev`, `conflict`, `replace`, `provide`, `repositories`, `extra`, `config.platform` and `config.policy`, also on their key order, empty values and package names. When the tool changes them, run `composer update --lock`.
- **have indent options**: the indentation comes from the file.

### Migration

```sh
composer remove --dev ergebnis/composer-normalize
composer require --dev shipmonk/composer-normalizer
```

Then remove `ergebnis/composer-normalize` from `config.allow-plugins` and change your scripts:

| Before                                                          | After                           |
|-----------------------------------------------------------------|---------------------------------|
| `composer normalize --dry-run --no-check-lock --no-update-lock` | `composer-normalizer --dry-run` |
| `composer normalize --no-check-lock --no-update-lock`           | `composer-normalizer`           |

## Contributing

- Check your code with `composer check`
- Autofix coding-style with `composer fix:cs`
- All functionality must be tested

## Supported PHP versions

- Runtime requires PHP 8.1+
