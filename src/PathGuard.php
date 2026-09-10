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
    /**
     * Every reading of the value a consumer downstream might arrive at.
     *
     * Null when the value disqualifies itself outright, which is only the leading- or
     * trailing-whitespace case - a guard cannot meaningfully reason about a value whose
     * every ^-anchored check a browser is about to shift out from under it.
     *
     * @return string[]|null
     */
    private static function candidates(string $path): ?array
    {
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
            $next = self::decodeOnce($decoded, false);
            if ($next === $decoded) {
                break;
            }
            $decoded = $next;
            $forms[] = $decoded;
        }

        // The pass above models a character reference inside an ATTRIBUTE value. In text
        // content the tokenizer is more willing still: the missing-semicolon exception that
        // leaves `&amp` alone next to a letter exists only for attributes, so `a&ltb` really
        // is `a<b` in a body. Directive output lands in both, so the text reading is examined
        // too - as extra candidates rather than in place of the others, since neither
        // context's decoding is a superset of the other's.
        $decoded = $path;
        for ($i = 0; $i < 6; $i++) {
            $next = self::decodeOnce($decoded, true);
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
                return null;
            }
            $candidates[] = $form;
            $stripped = (string)preg_replace('/[ \t\n\r\f\v]+/', '', $form);
            if ($stripped !== $form && $stripped !== '') {
                $candidates[] = $stripped;
            }
        }

        return $candidates;
    }

    /** @return bool true when the value is safe to append to a base path or URL */
    public static function isSafeRelativePath(string $path): bool
    {
        if ($path === '' || strlen($path) > 2048) {
            return false;
        }

        $candidates = self::candidates($path);
        if ($candidates === null) {
            return false;
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

    /**
     * One percent-decode plus one HTML-entity decode, the way a browser would read it.
     *
     * html_entity_decode() only decodes a reference that ends in a semicolon, and a browser
     * does not require one. That gap is the whole bug this exists to close: `&#58;` was
     * refused while `&#58alert(1)` - the same colon as far as any browser is concerned - was
     * waved through as a relative path, so `{{protocol http=$p https=$p}}` with
     * `javascript&#58alert(1)` produced a live scheme. `&#46&#46/` and `&#47&#47host` did
     * the same for traversal and for protocol-relative URLs.
     *
     * Numeric references never need the semicolon, in any context. Named ones need it inside
     * an attribute when a letter or `=` follows, and do not need it in text content - hence
     * the flag, so both readings can be checked.
     */
    private static function decodeOnce(string $value, bool $textContent): string
    {
        // The quantifiers are POSSESSIVE, and that is the whole correctness of this. With a
        // backtracking `+`, an already-valid `&#x3A;` fails the lookahead on the full run
        // `3A`, gives back the `A`, and matches `&#x3` instead - rewriting a reference that
        // was already well-formed into `&#x3;A;`, which html_entity_decode leaves alone
        // because U+0003 is a control character. The guard would then see no colon at all and
        // pass `javascript&#x3A;alert(1)`, which is strictly worse than the bug being fixed.
        // `$0` appends the terminator to whatever matched; the hex branch is first so `&#x41`
        // is not read as an empty decimal run.
        $value = (string)preg_replace('/&#(?:[xX][0-9a-fA-F]++|[0-9]++)(?!;)/', '$0;', $value);
        if ($textContent) {
            $value = (string)preg_replace('/&[a-zA-Z][a-zA-Z0-9]*+(?!;)/', '$0;', $value);
        }

        return html_entity_decode(rawurldecode($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * True when the value really is an absolute URL on the scheme the caller demands.
     *
     * `{{protocol http= https=}}` is the one directive whose parameters are DEFINED to carry
     * absolute URLs, so the relative-path guard is exactly wrong for it - and it was being
     * used, which meant every well-formed value rendered empty while
     * `javascript&#58alert(1)`, having no colon to find, passed as a relative path. Legacy
     * checks the scheme with parse_url and nothing else; the scheme is checked here on every
     * DECODED reading as well, because parse_url sees the bytes and the browser does not.
     */
    public static function isSafeAbsoluteUrl(string $url, string $requiredScheme): bool
    {
        if ($url === '' || strlen($url) > 2048) {
            return false;
        }

        $candidates = self::candidates($url);
        if ($candidates === null) {
            return false;
        }

        foreach ($candidates as $candidate) {
            if (preg_match('/[\x00-\x1F\x7F]/', $candidate)) {
                return false;
            }
            // Emitted unescaped, so a markup delimiter closes the attribute it lands in -
            // and an entity-encoded one is a delimiter by the time anything reads it.
            if (preg_match('/["\'<>`]/', $candidate)) {
                return false;
            }

            $parts = parse_url($candidate);
            if (!is_array($parts)
                || strtolower((string)($parts['scheme'] ?? '')) !== $requiredScheme
                || ($parts['host'] ?? '') === ''
            ) {
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
