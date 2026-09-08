<?php
declare(strict_types=1);

namespace MageOS\TemplateParser;

use MageOS\TemplateParser\Port\BlockRenderer;
use MageOS\TemplateParser\Port\ConfigReader;
use MageOS\TemplateParser\Port\CustomVariableReader;
use MageOS\TemplateParser\Port\LayoutRenderer;
use MageOS\TemplateParser\Port\StylesheetLoader;
use MageOS\TemplateParser\Port\TemplateLoader;
use MageOS\TemplateParser\Port\Translator;
use MageOS\TemplateParser\Port\UrlBuilder;
use MageOS\TemplateParser\Port\WidgetRenderer;

/**
 * The host capabilities a template may draw on.
 *
 * Every one is optional. A directive whose port is absent stays unregistered, which means it
 * is reported in strict mode and rendered verbatim otherwise - a host grants capabilities
 * deliberately rather than inheriting the whole surface by default.
 */
final class HostServices
{
    public function __construct(
        public readonly ?BlockRenderer $blocks = null,
        public readonly ?Translator $translator = null,
        public readonly ?TemplateLoader $templates = null,
        public readonly ?ConfigReader $config = null,
        public readonly ?CustomVariableReader $customVariables = null,
        public readonly ?UrlBuilder $urls = null,
        public readonly ?StylesheetLoader $stylesheets = null,
        public readonly ?LayoutRenderer $layouts = null,
        public readonly ?WidgetRenderer $widgets = null
    ) {
    }
}
