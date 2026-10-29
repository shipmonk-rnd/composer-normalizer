<?php declare(strict_types = 1);

namespace ShipMonk\ComposerNormalize;

use function file_get_contents;
use function file_put_contents;
use function fwrite;
use function is_file;
use function max;
use function str_starts_with;
use const PHP_EOL;

final class Application
{

    public const EXIT_SUCCESS = 0;
    public const EXIT_NOT_NORMALIZED = 1;
    public const EXIT_ERROR = 2;

    private const USAGE = <<<'USAGE'
        Usage: composer-normalize [--dry-run] [<file>...]

        Sorts keys of composer.json files. Without <file>, it uses composer.json in the working directory.

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

        foreach ($files === [] ? ['composer.json'] : $files as $file) {
            $exitCode = max($exitCode, $this->processFile($file, $dryRun));
        }

        return $exitCode;
    }

    private function processFile(
        string $file,
        bool $dryRun,
    ): int
    {
        $original = is_file($file) ? file_get_contents($file) : false;

        if ($original === false) {
            $this->write($this->stderr, "Cannot read {$file}");
            return self::EXIT_ERROR;
        }

        try {
            $normalized = $this->normalizer->normalize($original);
        } catch (InvalidComposerJsonException $e) {
            $this->write($this->stderr, "Cannot normalize {$file}: {$e->getMessage()}");
            return self::EXIT_ERROR;
        }

        if ($normalized === $original) {
            $this->write($this->stdout, "{$file} is normalized");
            return self::EXIT_SUCCESS;
        }

        if ($dryRun) {
            $this->write($this->stderr, "{$file} is not normalized, run composer-normalize without --dry-run to fix it");
            return self::EXIT_NOT_NORMALIZED;
        }

        if (file_put_contents($file, $normalized) === false) {
            $this->write($this->stderr, "Cannot write {$file}");
            return self::EXIT_ERROR;
        }

        $this->write($this->stdout, "{$file} has been normalized");
        return self::EXIT_SUCCESS;
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
