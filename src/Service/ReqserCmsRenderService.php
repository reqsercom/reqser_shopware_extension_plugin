<?php declare(strict_types=1);

namespace Reqser\Plugin\Service;

use Twig\Environment as TwigEnvironment;

/**
 * Service for rendering CMS slot elements
 * 
 * Renders CMS element data using Shopware's Twig templates
 * Accepts raw configuration data, allowing flexible rendering from any source
 */
class ReqserCmsRenderService
{
    private TwigEnvironment $twig;

    public function __construct(TwigEnvironment $twig)
    {
        $this->twig = $twig;
    }

    /**
     * Render CMS element data to HTML
     * 
     * @param string $type The CMS element type (e.g., 'text', 'image', 'html')
     * @param array $config The element configuration data
     * @return string Base64 encoded rendered HTML
     * @throws \RuntimeException If rendering fails
     */
    public function renderCmsElement(string $type, array $config): string
    {
        // Render the element using Shopware's template
        $html = $this->renderElementTemplate($type, $config);

        return base64_encode($html);
    }

    /**
     * Render the element using its Twig template
     * 
     * @param string $type The element type (e.g., 'text', 'image', 'html')
     * @param array $config The element configuration data
     * @return string Rendered HTML
     * @throws \RuntimeException If template not found or rendering fails
     */
    private function renderElementTemplate(string $type, array $config): string
    {
        // Shopware template naming convention
        $templateName = '@Storefront/storefront/element/cms-element-' . $type . '.html.twig';

        // Check if template exists
        if (!$this->twig->getLoader()->exists($templateName)) {
            throw new \RuntimeException("Template not found: {$templateName}");
        }

        // Prepare element data structure similar to how Shopware does it
        $elementData = $this->prepareElementData($config);

        // Render the template with element data - let exceptions bubble up
        $html = $this->twig->render($templateName, [
            'element' => (object)[
                'type' => $type,
                'config' => $config,
                'data' => $elementData,
                'id' => null  // No ID needed for preview rendering
            ]
        ]);

        return $html;
    }

    /**
     * Prepare element data from configuration
     * Extracts 'value' from {value: ..., source: "static"} structures
     * 
     * @param array $config Element configuration
     * @return object
     */
    private function prepareElementData(array $config): object
    {
        $data = [];

        foreach ($config as $key => $value) {
            if (is_array($value) && isset($value['value'])) {
                // Extract value from {value: ..., source: ...} structure
                $data[$key] = $value['value'];
            } else {
                $data[$key] = $value;
            }
        }

        return (object)$data;
    }
}

