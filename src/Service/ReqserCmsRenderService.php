<?php declare(strict_types=1);

namespace Reqser\Plugin\Service;

use Psr\Log\LoggerInterface;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Adapter\Twig\TemplateFinder;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\Context\AbstractSalesChannelContextFactory;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
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
 *
 * Storefront templates expect a Twig variable `context` (SalesChannelContext).
 * Extensions such as MediaExtension::searchMedia require the inner Framework
 * Context via `context.context` in Twig.
 */
class ReqserCmsRenderService
{
    private SalesChannelContext|null $cachedSalesChannelContext = null;

    /**
     * @param EntityRepository<\Shopware\Core\System\SalesChannel\SalesChannelCollection> $salesChannelRepository
     */
    public function __construct(
        private readonly TwigEnvironment $twig,
        private readonly TemplateFinder $templateFinder,
        private readonly EntityRepository $salesChannelRepository,
        private readonly AbstractSalesChannelContextFactory $salesChannelContextFactory,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Render CMS element data to HTML
     *
     * @param string $type
     * @param array<string, mixed> $config
     * @return string
     * @throws \RuntimeException If rendering fails
     */
    public function renderCmsElement(string $type, array $config, Context $frameworkContext): string
    {
        $html = $this->renderElementTemplate($type, $config, $frameworkContext);

        return base64_encode($html);
    }

    /**
     * Render the element using its Twig template
     *
     * Uses TemplateFinder::find() to resolve the template through Shopware's
     * bundle namespace hierarchy (same approach as DocumentTemplateRenderer).
     *
     * @param string $type
     * @param array<string, mixed> $config
     * @return string
     */
    private function renderElementTemplate(string $type, array $config, Context $frameworkContext): string
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
        $salesChannelContext = $this->getSalesChannelContext($frameworkContext);

        return $this->twig->render($resolvedTemplate, [
            'context' => $salesChannelContext,
            'element' => (object)[
                'type' => $type,
                'config' => $config,
                'data' => $elementData,
                'id' => null,
                'fieldConfig' => (object)['elements' => (object)$config],
            ],
        ]);
    }

    private function getSalesChannelContext(Context $frameworkContext): SalesChannelContext
    {
        if ($this->cachedSalesChannelContext !== null) {
            return $this->cachedSalesChannelContext;
        }

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('typeId', Defaults::SALES_CHANNEL_TYPE_STOREFRONT));
        $criteria->addFilter(new EqualsFilter('active', true));
        $criteria->addSorting(new FieldSorting('createdAt', FieldSorting::ASCENDING));
        $criteria->setLimit(1);

        $salesChannelId = $this->salesChannelRepository->searchIds($criteria, $frameworkContext)->firstId();

        if ($salesChannelId === null) {
            $this->logger->warning('ReqserCmsRenderService: no active storefront sales channel found', [
                'file' => __FILE__,
                'line' => __LINE__,
            ]);
            throw new \RuntimeException(
                'Cannot render CMS element: no active Storefront sales channel exists in this Shopware instance.'
            );
        }

        $this->cachedSalesChannelContext = $this->salesChannelContextFactory->create(
            Uuid::randomHex(),
            $salesChannelId,
            []
        );

        return $this->cachedSalesChannelContext;
    }

    /**
     * Prepare element data from configuration
     * Extracts 'value' from {value: ..., source: "static"} structures
     *
     * @param array<string, mixed> $config
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
