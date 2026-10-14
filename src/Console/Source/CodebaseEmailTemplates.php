<?php
declare(strict_types=1);

namespace MageOS\TemplateParser\Console\Source;

use MageOS\TemplateParser\Console\TemplateSubject;

/**
 * Email templates shipped as files: app/code, vendor and app/design.
 *
 * These are the ones a CI run can check, because they are in the repository. A merchant who
 * has never touched an email still renders these.
 */
class CodebaseEmailTemplates implements TemplateSource
{
    /** @param array<string,mixed> $variables */
    public function __construct(
        private readonly ?string $root,
        private readonly array $variables = [],
    ) {
    }

    public function name(): string
    {
        return 'codebase email templates';
    }

    public function isAvailable(): bool
    {
        return $this->root !== null && is_dir($this->root);
    }

    public function subjects(): iterable
    {
        if (!$this->isAvailable()) {
            return;
        }

        foreach (['app/code', 'app/design', 'vendor'] as $area) {
            $base = $this->root . '/' . $area;
            if (!is_dir($base)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST
            );

            foreach ($iterator as $file) {
                $path = $file->getPathname();
                // Judged on the path below the root, not the absolute one: a checkout that
                // happens to live under a `tests/` directory - a CI workspace - matched the
                // test-fixture rule for every file, found nothing, and passed.
                $relative = substr($path, strlen($this->root) + 1);
                $judged = '/' . $relative;
                // The convention every module follows: view/<area>/email/<name>.html
                if (!str_ends_with($judged, '.html') || !str_contains($judged, '/email/')) {
                    continue;
                }
                if (str_contains($judged, '/Test/') || str_contains($judged, '/tests/')) {
                    continue;
                }

                yield new TemplateSubject(
                    id: 'file:' . $relative,
                    label: $relative,
                    origin: 'codebase',
                    content: (string)file_get_contents($path),
                    variables: $this->variables,
                );
            }
        }
    }
}
