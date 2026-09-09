<?php
declare(strict_types=1);

namespace Cresset\TemplateParser\Console\Magerun;

use Cresset\TemplateParser\Console\Command\CheckCommand as BaseCommand;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * The standalone Check command, renamed for magerun and given its ObjectManager.
 *
 * Nothing is reimplemented: the behaviour lives in the base command so the two entrypoints
 * cannot drift. Prefixed with template-parser: because magerun's namespace is shared with
 * every other module.
 */
#[AsCommand(name: 'template-parser:check')]
class CheckCommand extends BaseCommand
{
    use MagerunBridge;
}
