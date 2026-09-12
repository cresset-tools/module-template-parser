<?php
declare(strict_types=1);

namespace Cresset\TemplateParser\Magento;

use Cresset\TemplateParser\Port\StylesheetLoader;

/**
 * {{css}} the way Email\Model\Template\Filter resolves it.
 *
 * Three details decide whether this returns the same bytes the store's filter returns:
 *
 *  - The asset is created WITH design params. Without them it resolves against whatever
 *    theme happens to be current rather than the one the email is being rendered for.
 *  - A published file under pub/static is read directly, and only an unpublished asset is
 *    compiled through getContent(). On a deployed store those are the same bytes; on one
 *    that has not run setup:static-content:deploy they are not.
 *  - A failure is output, not silence. A stylesheet that cannot be compiled renders as a
 *    CSS comment carrying the reason, and that comment goes out in the email - so an engine
 *    that returned nothing there would differ from the store on every template with a
 *    stylesheet, and would do it silently.
 */
class AssetStylesheetLoader implements StylesheetLoader
{
    public function __construct(
        private readonly \Magento\Email\Model\Template\Css\Processor $cssProcessor,
        private readonly \Magento\Framework\View\Asset\Repository $assetRepository,
        private readonly ?\Magento\Framework\View\DesignInterface $design = null,
        private readonly ?\Magento\Framework\Filesystem $filesystem = null,
    ) {
    }

    /** @param array<string,mixed> $designParams */
    public function load(string $file, array $designParams = []): ?string
    {
        try {
            $css = $this->cssProcessor->process($this->contentOf($file, $designParams));
        } catch (\Magento\Framework\View\Asset\ContentProcessorException $e) {
            return '/*' . PHP_EOL . $e->getMessage() . PHP_EOL . '*/';
        } catch (\Throwable) {
            return null;
        }

        return $css === ''
            ? '/* ' . __('Contents of the specified CSS file could not be loaded or is empty') . ' */'
            : $css;
    }

    /** @param array<string,mixed> $designParams */
    private function contentOf(string $file, array $designParams): string
    {
        // What the caller was given beats what this can see, for the reason
        // Port\StylesheetLoader::load() gives: a live read here resolves a different theme
        // from the one the filter used. The live read stays as the fallback for a caller with
        // no design to offer, which is the CMS surface.
        $asset = $this->assetRepository->createAsset($file, $designParams ?: $this->designParams());

        if ($this->filesystem !== null) {
            try {
                $pub = $this->filesystem->getDirectoryRead($asset->getContext()->getBaseDirType());
                if ($pub->isExist($asset->getPath())) {
                    return (string)$pub->readFile($asset->getPath());
                }
            } catch (\Magento\Framework\Exception\NotFoundException) {
                return '';
            } catch (\Throwable) {
                // Fall through to compiling the asset, which is what an undeployed store does.
            }
        }

        try {
            return (string)$asset->getContent();
        } catch (\Magento\Framework\Exception\NotFoundException) {
            return '';
        }
    }

    /** @return array<string,mixed> */
    private function designParams(): array
    {
        if ($this->design === null) {
            return [];
        }

        // AbstractTemplate::getDesignParams(). The area comes from the design in force -
        // under store emulation that is the emulated one, which is the point.
        $theme = $this->design->getDesignTheme();

        // An empty `theme` is PASSED, not dropped, because getDesignParams() passes it and
        // Asset\Repository reads it with isset() - so `theme => ''` gives a path with no theme
        // segment, `frontend/en_US/css/email.less`, and that is the path the filter resolves.
        // Filtering the empties out looks like a fix and is a divergence: it falls back to
        // getThemePath(), which yields `_view`, and every stylesheet in the store then differs.
        return [
            'area' => $this->design->getArea(),
            'theme' => $theme->getCode(),
            'themeModel' => $theme,
            'locale' => $this->design->getLocale(),
        ];
    }
}
