<?php
declare(strict_types=1);

namespace Cresset\TemplateParser\Test;

use Cresset\TemplateParser\PathGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PathGuardTest extends TestCase
{
    #[DataProvider('safePaths')]
    public function testSafeRelativePathsAreAccepted(string $path): void
    {
        self::assertTrue(PathGuard::isSafeRelativePath($path), $path);
    }

    public static function safePaths(): array
    {
        return [
            'simple file'      => ['logo.png'],
            'nested'           => ['images/logo.png'],
            'deep'             => ['a/b/c/d/logo.png'],
            'module notation'  => ['Magento_Email::css/email.css'],
            'dot in name'      => ['email-inline.min.css'],
            'dots not travers' => ['a..b/c.png'],
            'query-ish'        => ['img.png?v=2'],
        ];
    }

    #[DataProvider('unsafePaths')]
    public function testUnsafeRelativePathsAreRejected(string $path): void
    {
        self::assertFalse(PathGuard::isSafeRelativePath($path), $path);
    }

    public static function unsafePaths(): array
    {
        return [
            'empty'              => [''],
            'traversal'          => ['../etc/passwd'],
            'traversal nested'   => ['images/../../etc/passwd'],
            'traversal trailing' => ['images/..'],
            'backslash travers'  => ['..\\windows\\win.ini'],
            'encoded traversal'  => ['%2e%2e/etc/passwd'],
            'absolute unix'      => ['/etc/passwd'],
            'absolute windows'   => ['\\\\server\\share'],
            'protocol relative'  => ['//evil.example'],
            'http scheme'        => ['http://evil.example/x.png'],
            'data scheme'        => ['data:text/html,<script>'],
            'php wrapper'        => ['php://filter/resource=x'],
            'null byte'          => ["logo.png\0.php"],
            'newline'            => ["logo\n.png"],
            'far too long'       => [str_repeat('a', 3000)],
        ];
    }

    #[DataProvider('configPaths')]
    public function testConfigPaths(string $path, bool $expected): void
    {
        self::assertSame($expected, PathGuard::isSafeConfigPath($path), $path);
    }

    public static function configPaths(): array
    {
        return [
            ['trans_email/ident_general/name', true],
            ['web/unsecure/base_url', true],
            ['general', true],
            ['', false],
            ['../../etc', false],
            ['a/b/../c', false],
            ['a b', false],
            ["a\0b", false],
            ['a/b;c', false],
        ];
    }

    #[DataProvider('identifiers')]
    public function testIdentifiers(string $value, bool $expected): void
    {
        self::assertSame($expected, PathGuard::isSafeIdentifier($value), $value);
    }

    public static function identifiers(): array
    {
        return [
            ['customer_account_created', true],
            ['Magento\\Cms\\Block\\Widget\\Block', true],
            ['some-handle.xml', true],
            ['', false],
            ['has space', false],
            ['../traversal', false],
            ["nul\0byte", false],
            ['quote"inside', false],
            [str_repeat('a', 300), false],
        ];
    }
}
