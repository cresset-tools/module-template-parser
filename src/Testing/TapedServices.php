<?php
declare(strict_types=1);

namespace Cresset\TemplateParser\Testing;

use Cresset\TemplateParser\HostServices;
use Cresset\TemplateParser\Port\BlockRenderer;
use Cresset\TemplateParser\Port\ConfigReader;
use Cresset\TemplateParser\Port\CustomVariableReader;
use Cresset\TemplateParser\Port\LayoutRenderer;
use Cresset\TemplateParser\Port\StylesheetLoader;
use Cresset\TemplateParser\Port\TemplateLoader;
use Cresset\TemplateParser\Port\TemplateUrlBuilder;
use Cresset\TemplateParser\Port\Translator;
use Cresset\TemplateParser\Port\UrlBuilder;
use Cresset\TemplateParser\Port\WidgetRenderer;

/**
 * Wraps a set of ports so every call goes on a tape - or comes off one.
 *
 * Recording needs a store and replaying must not, so the same wrappers do both: with a live
 * set behind them they record, with none they answer from the tape. A port the recording host
 * did not have stays null, so replaying reproduces that host exactly rather than inventing a
 * capability it did not offer.
 */
final class TapedServices
{
    /** Ports that call through to a real host, recording as they go. */
    public static function recording(HostServices $live, PortTape $tape): HostServices
    {
        return self::wrap($live, $tape, true);
    }

    /**
     * Ports with nothing behind them, answering from the tape.
     *
     * $shape says which ports the recording host had; a null one stays null so the replayed
     * engine registers exactly the directives the recorded one did.
     *
     * @param array<string,bool> $shape
     */
    public static function replaying(array $shape, PortTape $tape): HostServices
    {
        return self::wrap(null, $tape, false, $shape);
    }

    /** @param array<string,bool> $shape */
    private static function wrap(?HostServices $live, PortTape $tape, bool $recording, array $shape = []): HostServices
    {
        $has = static fn (string $name): bool => $recording
            ? ($live?->{$name} ?? null) !== null
            : ($shape[$name] ?? false);

        return new HostServices(
            blocks: $has('blocks') ? new class ($tape, $live?->blocks) implements BlockRenderer {
                use TapedPort;
                public function render(string $class, array $data, string $method): string
                {
                    return (string)$this->call('blocks', 'render', [$class, $data, $method]);
                }
            } : null,
            translator: $has('translator') ? new class ($tape, $live?->translator) implements Translator {
                use TapedPort;
                public function translate(string $text, array $arguments): string
                {
                    return (string)$this->call('translator', 'translate', [$text, $arguments]);
                }
            } : null,
            templates: $has('templates') ? new class ($tape, $live?->templates) implements TemplateLoader {
                use TapedPort;
                public function load(string $configPath): ?string
                {
                    $v = $this->call('templates', 'load', [$configPath]);
                    return $v === null ? null : (string)$v;
                }
            } : null,
            config: $has('config') ? new class ($tape, $live?->config) implements ConfigReader {
                use TapedPort;
                public function value(string $path): ?string
                {
                    $v = $this->call('config', 'value', [$path]);
                    return $v === null ? null : (string)$v;
                }
            } : null,
            customVariables: $has('customVariables')
                ? new class ($tape, $live?->customVariables) implements CustomVariableReader {
                    use TapedPort;
                    public function value(string $code, bool $plainText): ?string
                    {
                        $v = $this->call('customVariables', 'value', [$code, $plainText]);
                        return $v === null ? null : (string)$v;
                    }
                } : null,
            urls: $has('urls') ? new class ($tape, $live?->urls) implements UrlBuilder {
                use TapedPort;
                public function storeUrl(string $path, array $parameters): string
                {
                    return (string)$this->call('urls', 'storeUrl', [$path, $parameters]);
                }
                public function mediaUrl(string $path): string
                {
                    return (string)$this->call('urls', 'mediaUrl', [$path]);
                }
                public function viewUrl(string $path, array $parameters): string
                {
                    return (string)$this->call('urls', 'viewUrl', [$path, $parameters]);
                }
                public function isSecure(): bool
                {
                    return (bool)$this->call('urls', 'isSecure', []);
                }
            } : null,
            stylesheets: $has('stylesheets') ? new class ($tape, $live?->stylesheets) implements StylesheetLoader {
                use TapedPort;
                public function load(string $file): ?string
                {
                    $v = $this->call('stylesheets', 'load', [$file]);
                    return $v === null ? null : (string)$v;
                }
            } : null,
            layouts: $has('layouts') ? new class ($tape, $live?->layouts) implements LayoutRenderer {
                use TapedPort;
                public function render(string $handle, string $area, array $parameters): string
                {
                    return (string)$this->call('layouts', 'render', [$handle, $area, $parameters]);
                }
            } : null,
            widgets: $has('widgets') ? new class ($tape, $live?->widgets) implements WidgetRenderer {
                use TapedPort;
                public function render(string $type, array $parameters): string
                {
                    return (string)$this->call('widgets', 'render', [$type, $parameters]);
                }
            } : null,
            templateUrls: $has('templateUrls')
                ? new class ($tape, $live?->templateUrls) implements TemplateUrlBuilder {
                    use TapedPort;
                    public function urlFor(object $target, array $arguments): ?string
                    {
                        // The receiver is an object, so it cannot go on a tape. Its CLASS can,
                        // and that is what the engine's decision actually turns on.
                        $v = $this->call('templateUrls', 'urlFor', [$target::class, $arguments], [$target, $arguments]);
                        return $v === null ? null : (string)$v;
                    }
                } : null,
        );
    }
}
