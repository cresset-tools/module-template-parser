<?php
declare(strict_types=1);

namespace MageOS\TemplateParser\Test;

use PHPUnit\Framework\TestCase;

/** What composer.json promises, checked against what the package actually is. */
final class ComposerManifestTest extends TestCase
{
    /** @return array<string,mixed> */
    private static function manifest(): array
    {
        return json_decode((string)file_get_contents(dirname(__DIR__) . '/composer.json'), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * The package before the rename registers the same plugins, the same bin and a table the
     * rename patch drops. Installed side by side, every filter is wrapped twice and the old
     * module recreates the table empty on the next setup:upgrade.
     */
    public function testThePackageBeforeTheRenameCannotBeInstalledAlongside(): void
    {
        self::assertArrayHasKey('cresset/module-template-parser', self::manifest()['conflict'] ?? []);
    }

    public function testEveryRuntimeRequirementIsUsed(): void
    {
        $namespaces = ['symfony/console' => 'Symfony\\Component\\Console\\'];
        $source = '';
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__) . '/src', \FilesystemIterator::SKIP_DOTS)) as $file) {
            $source .= file_get_contents($file->getPathname());
        }

        foreach (array_keys(self::manifest()['require']) as $package) {
            if ($package === 'php' || str_starts_with($package, 'ext-')) {
                continue;
            }
            self::assertArrayHasKey($package, $namespaces, "$package is required; say which namespace it provides here");
            self::assertStringContainsString($namespaces[$package], $source, "$package is required but nothing in src/ uses it");
        }
    }

    /**
     * tests/bootstrap.php falls back to its own autoloader when vendor's is stale; a prefix
     * missing there is a class the suite can only find while composer happens to be current.
     */
    public function testTheTestAutoloaderCoversEveryAutoloadedNamespace(): void
    {
        // The bootstrap writes each prefix as a single-quoted PHP string, `\\` per separator.
        $bootstrap = str_replace('\\\\', '\\', (string)file_get_contents(__DIR__ . '/bootstrap.php'));

        foreach (array_keys(self::manifest()['autoload']['psr-4']) as $prefix) {
            self::assertStringContainsString("'$prefix'", $bootstrap, "$prefix is autoloaded by composer but not by tests/bootstrap.php");
        }
    }
}
