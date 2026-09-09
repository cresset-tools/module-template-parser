<?php
declare(strict_types=1);

namespace Cresset\TemplateParser;

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

        // Decode repeatedly, and BOTH encodings: a single pass leaves %252e%252e ('..'
        // double-encoded) intact, percent-encoded control bytes slip past a check applied
        // only to the raw form, and HTML entities are decoded by the browser after this
        // engine is done. One entity pass was worse than none: it made the guard see
        // `&#46;&#46;/x` as safe while the browser still saw `../x`, so a value encoded twice
        // walked straight through. Decoding here rather than in the caller also means the
        // ORIGINAL value is what gets shipped, which is what legacy emits.
        $forms = [$path];
        $decoded = $path;
        for ($i = 0; $i < 6; $i++) {
            $next = html_entity_decode(rawurldecode($decoded), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($next === $decoded) {
                break;
            }
            $decoded = $next;
            $forms[] = $decoded;
        }

        // A leading space is not a control character, so it survives every decode pass and
        // shifts the value past every ^-anchored check below - ' //evil.example' and
        // ' javascript:x' would both pass. Browsers strip ASCII whitespace from URL
        // attributes before fetching, and path normalisers trim segments, so each form is
        // examined with its whitespace removed as well as verbatim.
        $candidates = [];
        foreach ($forms as $form) {
            if (trim($form, " \t\n\r\f\v") !== $form) {
                return false;
            }
            $candidates[] = $form;
            $stripped = (string)preg_replace('/[ \t\n\r\f\v]+/', '', $form);
            if ($stripped !== $form && $stripped !== '') {
                $candidates[] = $stripped;
            }
        }

        foreach ($candidates as $candidate) {
            // Null bytes and control characters.
            if (preg_match('/[\x00-\x1F\x7F]/', $candidate)) {
                return false;
            }

            // Markup delimiters. A relative path has no business carrying one, and every
            // directive that takes a path emits its result UNESCAPED - so `x"><script>`
            // in `<img src="{{media url=$p}}">` closes the attribute and opens a tag.
            // Refused rather than escaped, so a legitimate URL still renders byte-for-byte
            // as the filter renders it.
            if (preg_match('/["\'<>`]/', $candidate)) {
                return false;
            }

            // Absolute paths, protocol-relative URLs and anything carrying a scheme.
            if (str_starts_with($candidate, '/') || str_starts_with($candidate, '\\')) {
                return false;
            }
            if (preg_match('#^[a-zA-Z][a-zA-Z0-9+.-]*:#', $candidate)) {
                return false;
            }

            // Traversal, in either separator. `....//` collapses to `../` on some
            // normalisers, so any run of dots bounded by separators is refused.
            // Separators include the ones that END a path as far as a consumer is concerned:
            // `?` and `#` terminate a URL path, and `::` is Magento's module separator in
            // Asset\Repository, so `Magento_Email::../secret.css` is a traversal too.
            if (preg_match('#(^|[/\\\\:?\#])\.{2,}([/\\\\:?\#]|$)#', $candidate)) {
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
