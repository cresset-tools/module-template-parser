<?php
declare(strict_types=1);

namespace Cresset\TemplateParser\Console\Magerun;

use Cresset\TemplateParser\Console\Command\DiffCommand as BaseCommand;
use Symfony\Component\Console\Attribute\AsCommand;

/** The standalone Diff command, renamed for magerun. See Magerun\CheckCommand. */
#[AsCommand(name: 'template-parser:diff')]
class DiffCommand extends BaseCommand
{
    use MagerunBridge;
}
