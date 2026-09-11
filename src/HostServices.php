<?php
declare(strict_types=1);

namespace Cresset\TemplateParser;

use Cresset\TemplateParser\Port\BlockRenderer;
use Cresset\TemplateParser\Port\CustomDirectiveRenderer;
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
        public readonly ?WidgetRenderer $widgets = null,
        public readonly ?TemplateUrlBuilder $templateUrls = null,
        public readonly ?CustomDirectiveRenderer $customDirectives = null
    ) {
    }
}
