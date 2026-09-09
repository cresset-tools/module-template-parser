<?php
declare(strict_types=1);

namespace Cresset\TemplateParser\Console\Source;

use Cresset\TemplateParser\Console\TemplateSubject;

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
                // The convention every module follows: view/<area>/email/<name>.html
                if (!str_ends_with($path, '.html') || !str_contains($path, '/email/')) {
                    continue;
                }
                if (str_contains($path, '/Test/') || str_contains($path, '/tests/')) {
                    continue;
                }

                yield new TemplateSubject(
                    id: 'file:' . substr($path, strlen($this->root) + 1),
                    label: substr($path, strlen($this->root) + 1),
                    origin: 'codebase',
                    content: (string)file_get_contents($path),
                    variables: $this->variables,
                );
            }
        }
    }
}
