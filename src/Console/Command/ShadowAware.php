<?php
declare(strict_types=1);

namespace Cresset\TemplateParser\Console\Command;

use Cresset\TemplateParser\Console\Shadow\ShadowTable;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Shared wiring for the commands that read what Shadow mode recorded.
 *
 * Unlike check and diff these have no storeless fallback: the data lives in the store's
 * database, and a report over nothing would read as "no divergences", which is the one
 * answer that must never be given by accident. So each failure to reach the table says which
 * of the two things is missing - a store at all, or this module's table in it.
 */
trait ShadowAware
{
    private ?ShadowTable $shadowTable = null;

    /** For tests, and for anything that already holds a connection. */
    public function setShadowTable(ShadowTable $table): static
    {
        $this->shadowTable = $table;

        return $this;
    }

    /** The store's database, whether or not this module's table is in it yet. */
    protected function database(OutputInterface $output): ?ShadowTable
    {
        if ($this->shadowTable !== null) {
            return $this->shadowTable;
        }

        $magento = $this->magento();
        $table = $magento->isAvailable() ? ShadowTable::fromMagento($magento) : null;

        if ($table === null) {
            $output->writeln(sprintf('<error>%s needs a Magento store to read from.</error>', $this->getName()));
            $output->writeln('  ' . ($magento->reason() ?? 'the database connection could not be resolved'));

            return null;
        }

        return $this->shadowTable = $table;
    }

    /** The database, and only if this module's table is in it. */
    protected function shadowTable(OutputInterface $output): ?ShadowTable
    {
        $table = $this->database($output);
        if ($table === null) {
            return null;
        }

        if (!$table->exists()) {
            $output->writeln(sprintf('<error>No %s table in this store.</error>', ShadowTable::TABLE));
            $output->writeln('  Enable the module and run <comment>bin/magento setup:upgrade</comment>.');

            return null;
        }

        return $table;
    }

    /**
     * The name a sibling command has in whichever entrypoint this one runs in.
     *
     * The three entrypoints prefix differently - nothing standalone, `template:` under
     * bin/magento, `template-parser:` under magerun - and advice naming a command that does
     * not exist where the reader is typing is worse than none.
     */
    protected function sibling(string $command): string
    {
        $name = (string)$this->getName();
        $at = strpos($name, 'shadow:');
        if ($at === false) {
            $at = strrpos($name, ':');
            $at = $at === false ? 0 : $at + 1;
        }

        return substr($name, 0, $at) . $command;
    }
}
