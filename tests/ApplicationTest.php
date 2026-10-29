<?php declare(strict_types = 1);

namespace ShipMonk\ComposerNormalize;

use PHPUnit\Framework\TestCase;
use function chdir;
use function escapeshellarg;
use function exec;
use function file_get_contents;
use function file_put_contents;
use function fopen;
use function getcwd;
use function glob;
use function implode;
use function is_dir;
use function mkdir;
use function rewind;
use function rmdir;
use function stream_get_contents;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;
use const PHP_BINARY;
use const PHP_EOL;

final class ApplicationTest extends TestCase
{

    private const NORMALIZED = "{\n    \"name\": \"a/a\",\n    \"require\": {}\n}\n";
    private const NOT_NORMALIZED = "{\n    \"require\": {},\n    \"name\": \"a/a\"\n}\n";

    private string $tempDir;

    /**
     * @var resource
     */
    private $stdout;

    /**
     * @var resource
     */
    private $stderr;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/composer-normalize-' . uniqid();
        mkdir($this->tempDir);

        $this->stdout = $this->openMemoryStream();
        $this->stderr = $this->openMemoryStream();
    }

    protected function tearDown(): void
    {
        $files = glob($this->tempDir . '/*');

        foreach ($files === false ? [] : $files as $file) {
            unlink($file);
        }

        if (is_dir($this->tempDir)) {
            rmdir($this->tempDir);
        }
    }

    public function testDryRunWithNormalizedFile(): void
    {
        $file = $this->createFile('composer.json', self::NORMALIZED);

        self::assertSame(Application::EXIT_SUCCESS, $this->createApplication()->run(['--dry-run', $file]));
        self::assertSame("{$file} is normalized" . PHP_EOL, $this->readStream($this->stdout));
        self::assertSame('', $this->readStream($this->stderr));
    }

    public function testDryRunWithNotNormalizedFile(): void
    {
        $file = $this->createFile('composer.json', self::NOT_NORMALIZED);

        self::assertSame(Application::EXIT_NOT_NORMALIZED, $this->createApplication()->run(['--dry-run', $file]));
        self::assertSame("{$file} is not normalized, run composer-normalize without --dry-run to fix it" . PHP_EOL, $this->readStream($this->stderr));
        self::assertSame(self::NOT_NORMALIZED, file_get_contents($file));
    }

    public function testFixesFile(): void
    {
        $file = $this->createFile('composer.json', self::NOT_NORMALIZED);

        self::assertSame(Application::EXIT_SUCCESS, $this->createApplication()->run([$file]));
        self::assertSame("{$file} has been normalized" . PHP_EOL, $this->readStream($this->stdout));
        self::assertSame(self::NORMALIZED, file_get_contents($file));
    }

    public function testUsesComposerJsonInWorkingDirectory(): void
    {
        $this->createFile('composer.json', self::NOT_NORMALIZED);
        $originalDir = (string) getcwd();
        chdir($this->tempDir);

        try {
            self::assertSame(Application::EXIT_SUCCESS, $this->createApplication()->run([]));
        } finally {
            chdir($originalDir);
        }

        self::assertSame('composer.json has been normalized' . PHP_EOL, $this->readStream($this->stdout));
        self::assertSame(self::NORMALIZED, file_get_contents($this->tempDir . '/composer.json'));
    }

    public function testReturnsWorstExitCodeOfAllFiles(): void
    {
        $normalized = $this->createFile('normalized.json', self::NORMALIZED);
        $notNormalized = $this->createFile('not-normalized.json', self::NOT_NORMALIZED);
        $missing = $this->tempDir . '/missing.json';

        self::assertSame(Application::EXIT_NOT_NORMALIZED, $this->createApplication()->run(['--dry-run', $normalized, $notNormalized]));
        self::assertSame(Application::EXIT_ERROR, $this->createApplication()->run(['--dry-run', $missing, $notNormalized]));
    }

    public function testMissingFile(): void
    {
        $file = $this->tempDir . '/missing.json';

        self::assertSame(Application::EXIT_ERROR, $this->createApplication()->run([$file]));
        self::assertSame("Cannot read {$file}" . PHP_EOL, $this->readStream($this->stderr));
    }

    public function testInvalidJson(): void
    {
        $file = $this->createFile('composer.json', '{');

        self::assertSame(Application::EXIT_ERROR, $this->createApplication()->run([$file]));
        self::assertStringStartsWith("Cannot normalize {$file}: Invalid JSON: Syntax error", $this->readStream($this->stderr)); // PHP 8.6 adds location
        self::assertSame('{', file_get_contents($file));
    }

    public function testFileNameStartingWithDash(): void
    {
        $this->createFile('-composer.json', self::NOT_NORMALIZED);
        $originalDir = (string) getcwd();
        chdir($this->tempDir);

        try {
            self::assertSame(Application::EXIT_NOT_NORMALIZED, $this->createApplication()->run(['--dry-run', '--', '-composer.json']));
        } finally {
            chdir($originalDir);
        }
    }

    public function testUnknownOption(): void
    {
        self::assertSame(Application::EXIT_ERROR, $this->createApplication()->run(['--indent-size=2']));
        self::assertStringStartsWith('Unknown option --indent-size=2' . PHP_EOL . PHP_EOL . 'Usage:', $this->readStream($this->stderr));
    }

    public function testHelp(): void
    {
        self::assertSame(Application::EXIT_SUCCESS, $this->createApplication()->run(['--help']));
        self::assertStringStartsWith('Usage: composer-normalize [--dry-run] [<file>...]', $this->readStream($this->stdout));
    }

    public function testBinary(): void
    {
        $file = $this->createFile('composer.json', self::NOT_NORMALIZED);
        $command = implode(' ', [
            escapeshellarg(PHP_BINARY),
            escapeshellarg(__DIR__ . '/../bin/composer-normalize'),
            '--dry-run',
            escapeshellarg($file),
            '2>&1',
        ]);

        exec($command, $output, $exitCode);

        self::assertSame(Application::EXIT_NOT_NORMALIZED, $exitCode);
        self::assertSame(["{$file} is not normalized, run composer-normalize without --dry-run to fix it"], $output);
    }

    private function createApplication(): Application
    {
        return new Application(new ComposerJsonNormalizer(), $this->stdout, $this->stderr);
    }

    private function createFile(
        string $name,
        string $contents,
    ): string
    {
        $file = $this->tempDir . '/' . $name;
        file_put_contents($file, $contents);
        return $file;
    }

    /**
     * @return resource
     */
    private function openMemoryStream()
    {
        $stream = fopen('php://memory', 'w+');
        self::assertNotFalse($stream);
        return $stream;
    }

    /**
     * @param resource $stream
     */
    private function readStream($stream): string
    {
        rewind($stream);
        return (string) stream_get_contents($stream);
    }

}
