<?php declare(strict_types=1);

namespace Reqser\Plugin\Service;

use Shopware\Core\Framework\Adapter\Twig\TemplateFinder;
use Twig\Environment as TwigEnvironment;
use Twig\Error\LoaderError;

/**
 * Service for rendering CMS slot elements
 * 
 * Renders CMS element data using Shopware's Twig templates.
 * Uses Shopware's TemplateFinder to resolve templates through the full bundle
 * hierarchy, the same way Shopware's DocumentTemplateRenderer works. This
 * ensures third-party plugin elements (e.g., ck-accordion from FietzRevplusChild)
 * are found even in admin API context where the storefront theme inheritance
 * chain is not active.
 */
class ReqserCmsRenderService
{
    private TwigEnvironment $twig;
    private TemplateFinder $templateFinder;

    /**
     * @param TwigEnvironment $twig
     * @param TemplateFinder $templateFinder
     */
    public function __construct(TwigEnvironment $twig, TemplateFinder $templateFinder)
    {
        $this->twig = $twig;
        $this->templateFinder = $templateFinder;
    }

    /**
     * Render CMS element data to HTML
     *
     * @param string $type
     * @param array $config
     * @return string
     * @throws \RuntimeException If rendering fails
     */
    public function renderCmsElement(string $type, array $config): string
    {
        $html = $this->renderElementTemplate($type, $config);

        return base64_encode($html);
    }

    /**
     * Render the element using its Twig template
     * 
     * Uses TemplateFinder::find() to resolve the template through Shopware's
     * bundle namespace hierarchy (same approach as DocumentTemplateRenderer).
     *
     * @param string $type
     * @param array $config
     * @return string
     */
    private function renderElementTemplate(string $type, array $config): string
    {
        $templatePath = '@Storefront/storefront/element/cms-element-' . $type . '.html.twig';

        $this->templateFinder->reset();

        try {
            $resolvedTemplate = $this->templateFinder->find($templatePath);
        } catch (LoaderError $e) {
            throw new \RuntimeException(
                "Template not found for CMS element type: {$type}. "
                . "TemplateFinder searched all registered bundle namespaces. "
                . "Original error: " . $e->getMessage()
            );
        }

        $elementData = $this->prepareElementData($config);

        $html = $this->twig->render($resolvedTemplate, [
            'element' => (object)[
                'type' => $type,
                'config' => $config,
                'data' => $elementData,
                'id' => null,
                'fieldConfig' => (object)['elements' => (object)$config],
            ]
        ]);

        return $html;
    }

    /**
     * Prepare element data from configuration
     * Extracts 'value' from {value: ..., source: "static"} structures
     *
     * @param array $config
     * @return object
     */
    private function prepareElementData(array $config): object
    {
        $data = [];

        foreach ($config as $key => $value) {
            if (is_array($value) && isset($value['value'])) {
                $data[$key] = $value['value'];
            } else {
                $data[$key] = $value;
            }
        }

        return (object)$data;
    }
}

