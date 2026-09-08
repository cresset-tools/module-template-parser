<?php
declare(strict_types=1);

namespace MageOS\TemplateParser;

/**
 * Rejects file and URL fragments that should never come out of a template.
 *
 * Applied in the directive handlers rather than left to each port implementation, so a host
 * cannot forget it. Legacy concatenates these straight onto a base URL - mediaDirective is
 * literally `getBaseUrl(MEDIA) . $params['url']` - which is how a template ends up able to
 * point at whatever it likes.
 */
final class PathGuard
{
    /** @return bool true when the value is safe to append to a base path or URL */
    public static function isSafeRelativePath(string $path): bool
    {
        if ($path === '' || strlen($path) > 2048) {
            return false;
        }

        // Null bytes and control characters.
        if (preg_match('/[\x00-\x1F\x7F]/', $path)) {
            return false;
        }

        // Absolute paths, protocol-relative URLs and anything carrying a scheme.
        if (str_starts_with($path, '/') || str_starts_with($path, '\\') || str_starts_with($path, '//')) {
            return false;
        }
        if (preg_match('#^[a-zA-Z][a-zA-Z0-9+.-]*:#', $path)) {
            return false;
        }

        // Traversal, in either separator, before or after decoding.
        $decoded = rawurldecode($path);
        foreach ([$path, $decoded] as $candidate) {
            if (preg_match('#(^|[/\\\\])\.\.([/\\\\]|$)#', $candidate)) {
                return false;
            }
        }

        return true;
    }

    /** A config path is a dotted/slashed identifier, nothing more. */
    public static function isSafeConfigPath(string $path): bool
    {
        return $path !== '' && (bool)preg_match('#^[a-zA-Z0-9_]+(/[a-zA-Z0-9_]+)*$#', $path);
    }

    /** A layout handle or widget/block type is an identifier, not a path. */
    public static function isSafeIdentifier(string $value): bool
    {
        return $value !== '' && (bool)preg_match('#^[a-zA-Z0-9_\\\\.-]{1,255}$#', $value);
    }
}
