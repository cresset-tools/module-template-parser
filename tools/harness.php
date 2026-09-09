<?php
declare(strict_types=1);

/**
 * Shared harness for the tools in this directory.
 *
 * The recorders run REAL Magento filter classes, so they need a Magento tree and the handful
 * of framework leaves those classes touch. Only the leaves are stubbed here; every line of
 * filter logic under test is Magento's own.
 *
 * Point MAGENTO_ROOT at a Magento or Mage-OS checkout:
 *
 *     MAGENTO_ROOT=/path/to/magento php tools/record-legacy.php
 *
 * Use a PRISTINE checkout. Recording against a tree with a fix applied turns the differential
 * into a comparison of two fixed engines, and it does so silently.
 */

// Inert unless a tool in this directory is being run directly.
//
// Magento's DI compiler (Setup\Module\Di\Code\Reader\ClassesScanner) require_once's
// every .php file that declares a class it has not already loaded, and its exclusion
// list covers only `/Test`, not `tools/`. Without this guard, `setup:di:compile` runs
// this file: it would exit(1) for want of MAGENTO_ROOT, and the stub classes below
// would collide with the real Magento ones. Returning here declares none of them.
if (!defined('CRESSET_TEMPLATE_PARSER_TOOL')) {
    return;
}

$magentoRoot = getenv('MAGENTO_ROOT') ?: '';
if ($magentoRoot === '') {
    fwrite(STDERR, "MAGENTO_ROOT is not set. Point it at a Magento or Mage-OS checkout:
"
        . "  MAGENTO_ROOT=/path/to/magento php " . ($_SERVER['argv'][0] ?? 'tools/...') . "
");
    exit(1);
}
$magentoRoot = rtrim($magentoRoot, '/');
if (!is_dir($magentoRoot . '/lib/internal/Magento/Framework/Filter')) {
    fwrite(STDERR, "MAGENTO_ROOT=$magentoRoot does not look like a Magento tree:
"
        . "  lib/internal/Magento/Framework/Filter is missing
");
    exit(1);
}

/** The Magento tree being recorded against. */
define('MROOT', $magentoRoot);

/** This package's root, so tools need no knowledge of where they were invoked from. */
define('PKGROOT', dirname(__DIR__));

/**
 * Optional collaborators the filter has gained over time.
 *
 * Mage-OS added Template\DirectiveOutputNeutralizer as part of the StyleSmuggler
 * hardening, and Template::__construct resolves it through the global ObjectManager when
 * it is not injected - which this harness deliberately cannot serve. Requiring it when
 * the tree has it keeps the tools working against both an older checkout and a current
 * one; neutralizerFor() below returns null on a tree that predates it.
 */
$optional = MROOT . '/lib/internal/Magento/Framework/Filter/Template/DirectiveOutputNeutralizer.php';
if (is_file($optional)) {
    require $optional;
}

// The stubs carry the .php.stub extension so that Magento's DI scanners - which walk every
// *.php under an installed module - never see a class declaration in tools/ at all. A module
// installed from git ships this directory, and playing whack-a-mole with each scanner in turn
// is a losing game; giving them nothing to find is not.
require __DIR__ . '/stubs/Harness.php.stub';
require __DIR__ . '/stubs/LaminasFilter.php.stub';
require __DIR__ . '/stubs/MagentoFramework.php.stub';
require __DIR__ . '/stubs/MagentoFrameworkApp.php.stub';
require __DIR__ . '/stubs/MagentoFrameworkException.php.stub';
require __DIR__ . '/stubs/MagentoFrameworkFilterTemplateTokenizer.php.stub';
require __DIR__ . '/stubs/MagentoFrameworkObjectManager.php.stub';
require __DIR__ . '/stubs/MagentoFrameworkStdlib.php.stub';
