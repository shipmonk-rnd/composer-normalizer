<?php declare(strict_types = 1);

namespace ShipMonk\ComposerNormalizer;

use Closure;
use function basename;
use function chmod;
use function dirname;
use function file_get_contents;
use function file_put_contents;
use function fileperms;
use function fwrite;
use function getenv;
use function is_file;
use function is_string;
use function max;
use function preg_replace;
use function realpath;
use function rename;
use function restore_error_handler;
use function set_error_handler;
use function str_starts_with;
use function strlen;
use function trim;
use function uniqid;
use function unlink;
use const DIRECTORY_SEPARATOR;
use const PHP_EOL;

final class Application
{

    public const EXIT_SUCCESS = 0;
    public const EXIT_NOT_NORMALIZED = 1;
    public const EXIT_ERROR = 2;

    private const USAGE = <<<'USAGE'
        Usage: composer-normalizer [--dry-run] [<file>...]

        Sorts keys of composer.json files and removes empty keys. Without <file>, it uses the file from the COMPOSER environment variable
        or composer.json in the working directory, same as Composer.

        Options:
            --dry-run   Do not change files, exit with code 1 when a file is not normalized
            -h, --help  Show this help
        USAGE;

    /**
     * @var resource
     */
    private $stdout;

    /**
     * @var resource
     */
    private $stderr;

    /**
     * @param resource $stdout
     * @param resource $stderr
     */
    public function __construct(
        private readonly ComposerJsonNormalizer $normalizer,
        $stdout,
        $stderr,
    )
    {
        $this->stdout = $stdout;
        $this->stderr = $stderr;
    }

    /**
     * @param list<string> $args command line arguments without the script name
     */
    public function run(array $args): int
    {
        $dryRun = false;
        $files = [];
        $onlyFilesFollow = false;

        foreach ($args as $arg) {
            if ($onlyFilesFollow || !str_starts_with($arg, '-')) {
                $files[] = $arg;

            } elseif ($arg === '--') {
                $onlyFilesFollow = true;

            } elseif ($arg === '--dry-run') {
                $dryRun = true;

            } elseif ($arg === '-h' || $arg === '--help') {
                $this->write($this->stdout, self::USAGE);
                return self::EXIT_SUCCESS;

            } else {
                $this->write($this->stderr, "Unknown option {$arg}" . PHP_EOL . PHP_EOL . self::USAGE);
                return self::EXIT_ERROR;
            }
        }

        $exitCode = self::EXIT_SUCCESS;

        foreach ($files === [] ? [self::getDefaultFile()] : $files as $file) {
            $exitCode = max($exitCode, $this->processFile($file, $dryRun));
        }

        return $exitCode;
    }

    private function processFile(
        string $file,
        bool $dryRun,
    ): int
    {
        try {
            $original = self::readFile($file);
            $normalized = $this->normalizer->normalize($original);
        } catch (FileAccessException $e) {
            $this->write($this->stderr, $e->getMessage());
            return self::EXIT_ERROR;
        } catch (InvalidComposerJsonException $e) {
            $this->write($this->stderr, "Cannot normalize {$file}: {$e->getMessage()}");
            return self::EXIT_ERROR;
        }

        if ($normalized === $original) {
            $this->write($this->stdout, "{$file} is normalized");
            return self::EXIT_SUCCESS;
        }

        if ($dryRun) {
            $this->write($this->stderr, "{$file} is not normalized, run composer-normalizer without --dry-run to fix it");
            return self::EXIT_NOT_NORMALIZED;
        }

        try {
            self::writeFile($file, $normalized);
        } catch (FileAccessException $e) {
            $this->write($this->stderr, $e->getMessage());
            return self::EXIT_ERROR;
        }

        $this->write($this->stdout, "{$file} has been normalized");
        return self::EXIT_SUCCESS;
    }

    /**
     * Same as Composer\Factory::getComposerFile()
     */
    private static function getDefaultFile(): string
    {
        $file = getenv('COMPOSER');
        $file = is_string($file) ? trim($file) : '';

        return $file === '' ? 'composer.json' : $file;
    }

    /**
     * @throws FileAccessException
     */
    private static function readFile(string $file): string
    {
        if (!is_file($file)) {
            throw new FileAccessException("Cannot read {$file}: file not found");
        }

        [$contents, $warning] = self::callWithoutWarnings(static fn (): string|false => file_get_contents($file));

        if ($contents === false) {
            throw new FileAccessException("Cannot read {$file}: {$warning}");
        }

        return $contents;
    }

    /**
     * Writes a temporary file next to the target and renames it, so a failed write does not destroy the target.
     *
     * @throws FileAccessException
     */
    private static function writeFile(
        string $file,
        string $contents,
    ): void
    {
        $target = realpath($file); // keeps symlinks

        if ($target === false) {
            throw new FileAccessException("Cannot write {$file}: file not found");
        }

        $tempFile = dirname($target) . DIRECTORY_SEPARATOR . '.' . basename($target) . '.' . uniqid() . '.tmp';
        [$writtenBytes, $warning] = self::callWithoutWarnings(static fn (): int|false => file_put_contents($tempFile, $contents));

        if ($writtenBytes === strlen($contents)) {
            $permissions = fileperms($target);
            self::callWithoutWarnings(static fn (): bool => $permissions !== false && chmod($tempFile, $permissions & 0777));
            [$renamed, $warning] = self::callWithoutWarnings(static fn (): bool => rename($tempFile, $target));

            if ($renamed) {
                return;
            }
        }

        self::callWithoutWarnings(static fn (): bool => unlink($tempFile));
        throw new FileAccessException("Cannot write {$file}: {$warning}");
    }

    /**
     * Returns the result of the call and the message of the last PHP warning it triggered, the warning is not printed.
     *
     * @param Closure(): T $call
     * @return array{T, string}
     *
     * @template T
     */
    private static function callWithoutWarnings(Closure $call): array
    {
        $warning = 'unknown error';

        set_error_handler(static function (int $level, string $message) use (&$warning): bool {
            $warning = preg_replace('~^\w+\(.*?\): ~', '', $message) ?? $message; // removes "file_get_contents(path): "
            return true;
        });

        try {
            $result = $call();
        } finally {
            restore_error_handler();
        }

        return [$result, $warning];
    }

    /**
     * @param resource $stream
     */
    private function write(
        $stream,
        string $message,
    ): void
    {
        fwrite($stream, $message . PHP_EOL);
    }

}
