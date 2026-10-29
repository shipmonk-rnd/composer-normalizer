<?php declare(strict_types = 1);

namespace ShipMonk\ComposerNormalizer;

use PHPUnit\Framework\TestCase;
use function array_diff;
use function array_values;
use function chdir;
use function chmod;
use function escapeshellarg;
use function exec;
use function file_get_contents;
use function file_put_contents;
use function fileperms;
use function fopen;
use function getcwd;
use function getenv;
use function implode;
use function is_link;
use function is_readable;
use function is_writable;
use function mkdir;
use function putenv;
use function rewind;
use function rmdir;
use function scandir;
use function stream_get_contents;
use function symlink;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;
use const PHP_BINARY;
use const PHP_EOL;
use const PHP_OS_FAMILY;

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
        $this->tempDir = sys_get_temp_dir() . '/composer-normalizer-' . uniqid();
        mkdir($this->tempDir);

        $this->stdout = $this->openMemoryStream();
        $this->stderr = $this->openMemoryStream();
    }

    protected function tearDown(): void
    {
        chmod($this->tempDir, 0755);

        foreach ($this->listTempDir() as $file) {
            unlink($this->tempDir . '/' . $file);
        }

        rmdir($this->tempDir);
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
        self::assertSame("{$file} is not normalized, run composer-normalizer without --dry-run to fix it" . PHP_EOL, $this->readStream($this->stderr));
        self::assertSame('', $this->readStream($this->stdout));
        self::assertSame(self::NOT_NORMALIZED, file_get_contents($file));
    }

    public function testFixesFile(): void
    {
        $file = $this->createFile('composer.json', self::NOT_NORMALIZED);

        self::assertSame(Application::EXIT_SUCCESS, $this->createApplication()->run([$file]));
        self::assertSame("{$file} has been normalized" . PHP_EOL, $this->readStream($this->stdout));
        self::assertSame('', $this->readStream($this->stderr));
        self::assertSame(self::NORMALIZED, file_get_contents($file));
        self::assertSame(['composer.json'], $this->listTempDir());
    }

    public function testKeepsSymlinkAndPermissions(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('Symlinks and Unix permissions are not available on Windows');
        }

        $file = $this->createFile('composer.json', self::NOT_NORMALIZED);
        $link = $this->tempDir . '/link.json';
        chmod($file, 0640);
        symlink($file, $link);

        self::assertSame(Application::EXIT_SUCCESS, $this->createApplication()->run([$link]));
        self::assertTrue(is_link($link));
        self::assertSame(self::NORMALIZED, file_get_contents($file));
        self::assertSame(0640, fileperms($file) & 0777);
        self::assertSame(['composer.json', 'link.json'], $this->listTempDir());
    }

    public function testFailedWriteKeepsFile(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('Windows ignores the read-only attribute of directories');
        }

        $file = $this->createFile('composer.json', self::NOT_NORMALIZED);
        chmod($this->tempDir, 0555);

        if (is_writable($this->tempDir)) {
            self::markTestSkipped('Directory permissions are not enforced for root');
        }

        self::assertSame(Application::EXIT_ERROR, $this->createApplication()->run([$file]));
        self::assertSame("Cannot write {$file}: Failed to open stream: Permission denied" . PHP_EOL, $this->readStream($this->stderr));
        self::assertSame('', $this->readStream($this->stdout));
        self::assertSame(self::NOT_NORMALIZED, file_get_contents($file));
        self::assertSame(['composer.json'], $this->listTempDir());
    }

    public function testUnreadableFile(): void
    {
        $file = $this->createFile('composer.json', self::NOT_NORMALIZED);
        chmod($file, 0200);

        if (is_readable($file)) {
            self::markTestSkipped('File permissions are not enforced on Windows and for root');
        }

        self::assertSame(Application::EXIT_ERROR, $this->createApplication()->run([$file]));
        self::assertSame("Cannot read {$file}: Failed to open stream: Permission denied" . PHP_EOL, $this->readStream($this->stderr));
        self::assertSame('', $this->readStream($this->stdout));
    }

    public function testUsesComposerJsonInWorkingDirectory(): void
    {
        $this->createFile('composer.json', self::NOT_NORMALIZED);

        self::assertSame(Application::EXIT_SUCCESS, $this->runInTempDir([], composerEnv: ' '));
        self::assertSame('composer.json has been normalized' . PHP_EOL, $this->readStream($this->stdout));
        self::assertSame(self::NORMALIZED, file_get_contents($this->tempDir . '/composer.json'));
    }

    public function testUsesComposerEnvironmentVariable(): void
    {
        $this->createFile('composer.json', self::NOT_NORMALIZED);
        $this->createFile('custom.json', self::NOT_NORMALIZED);

        self::assertSame(Application::EXIT_SUCCESS, $this->runInTempDir([], composerEnv: 'custom.json'));
        self::assertSame('custom.json has been normalized' . PHP_EOL, $this->readStream($this->stdout));
        self::assertSame(self::NORMALIZED, file_get_contents($this->tempDir . '/custom.json'));
        self::assertSame(self::NOT_NORMALIZED, file_get_contents($this->tempDir . '/composer.json'));
    }

    public function testReturnsWorstExitCodeOfAllFiles(): void
    {
        $normalized = $this->createFile('normalized.json', self::NORMALIZED);
        $notNormalized = $this->createFile('not-normalized.json', self::NOT_NORMALIZED);
        $missing = $this->tempDir . '/missing.json';

        self::assertSame(Application::EXIT_NOT_NORMALIZED, $this->createApplication()->run(['--dry-run', $normalized, $notNormalized]));
        self::assertSame(Application::EXIT_ERROR, $this->createApplication()->run(['--dry-run', $missing, $notNormalized]));
        self::assertSame(Application::EXIT_ERROR, $this->createApplication()->run([$missing, $notNormalized]));
        self::assertSame(self::NORMALIZED, file_get_contents($notNormalized)); // files after an error are processed too
    }

    public function testMissingFile(): void
    {
        $file = $this->tempDir . '/missing.json';

        self::assertSame(Application::EXIT_ERROR, $this->createApplication()->run([$file]));
        self::assertSame("Cannot read {$file}: file not found" . PHP_EOL, $this->readStream($this->stderr));
        self::assertSame('', $this->readStream($this->stdout));
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

        self::assertSame(Application::EXIT_NOT_NORMALIZED, $this->runInTempDir(['--dry-run', '--', '-composer.json'], composerEnv: ''));
    }

    public function testUnknownOption(): void
    {
        self::assertSame(Application::EXIT_ERROR, $this->createApplication()->run(['--indent-size=2']));
        self::assertStringStartsWith('Unknown option --indent-size=2' . PHP_EOL . PHP_EOL . 'Usage:', $this->readStream($this->stderr));
    }

    public function testHelp(): void
    {
        self::assertSame(Application::EXIT_SUCCESS, $this->createApplication()->run(['--help']));
        self::assertStringStartsWith('Usage: composer-normalizer [--dry-run] [<file>...]', $this->readStream($this->stdout));
    }

    public function testBinary(): void
    {
        $file = $this->createFile('composer.json', self::NOT_NORMALIZED);
        $command = implode(' ', [
            escapeshellarg(PHP_BINARY),
            escapeshellarg(__DIR__ . '/../bin/composer-normalizer'),
            '--dry-run',
            escapeshellarg($file),
            '2>&1',
        ]);

        exec($command, $output, $exitCode);

        self::assertSame(Application::EXIT_NOT_NORMALIZED, $exitCode);
        self::assertSame(["{$file} is not normalized, run composer-normalizer without --dry-run to fix it"], $output);
    }

    /**
     * @return list<string>
     */
    private function listTempDir(): array
    {
        $files = scandir($this->tempDir);
        self::assertNotFalse($files);

        return array_values(array_diff($files, ['.', '..']));
    }

    /**
     * @param list<string> $args
     */
    private function runInTempDir(
        array $args,
        string $composerEnv,
    ): int
    {
        $originalDir = (string) getcwd();
        $originalComposerEnv = getenv('COMPOSER');
        chdir($this->tempDir);
        putenv("COMPOSER={$composerEnv}");

        try {
            return $this->createApplication()->run($args);
        } finally {
            chdir($originalDir);
            putenv($originalComposerEnv === false ? 'COMPOSER' : "COMPOSER={$originalComposerEnv}");
        }
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
