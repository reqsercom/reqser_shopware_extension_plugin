<?php declare(strict_types=1);

namespace Reqser\Plugin\Service;

use Psr\Log\LoggerInterface;
use Reqser\Plugin\Exception\CmsElementRenderException;
use Shopware\Core\Content\Cms\Aggregate\CmsSlot\CmsSlotCollection;
use Shopware\Core\Content\Cms\Aggregate\CmsSlot\CmsSlotEntity;
use Shopware\Core\Content\Cms\DataResolver\CmsSlotsDataResolver;
use Shopware\Core\Content\Cms\DataResolver\ResolverContext\ResolverContext;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Adapter\Twig\TemplateFinder;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\Framework\Struct\ArrayEntity;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\Context\AbstractSalesChannelContextFactory;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\Request;
use Twig\Environment as TwigEnvironment;
use Twig\Error\LoaderError;

/**
 * Renders a single CMS slot to HTML using Shopware's element resolvers and storefront Twig templates.
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
        private readonly LoggerInterface $logger,
        private readonly CmsSlotsDataResolver $cmsSlotsDataResolver
    ) {
    }

    /**
     * Render CMS element data to HTML
     *
     * @param string $type
     * @param array<string, mixed> $config
     * @return string
     * @throws \RuntimeException If the template is missing or no storefront sales channel exists
     * @throws CmsElementRenderException If the element cannot be rendered in isolation (degradable)
     */
    public function renderCmsElement(string $type, array $config, Context $frameworkContext): string
    {
        $html = $this->renderElementTemplate($type, $config, $frameworkContext);

        return base64_encode($html);
    }

    /**
     * Render the element using its Twig template.
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
            // Template absent — hard error (non-2xx).
            throw new \RuntimeException(
                "Template not found for CMS element type: {$type}. "
                . "TemplateFinder searched all registered bundle namespaces. "
                . "Original error: " . $e->getMessage()
            );
        }

        $salesChannelContext = $this->getSalesChannelContext($frameworkContext);

        // A per-element render failure is degradable.
        try {
            $slot = $this->buildResolvedSlot($type, $config, $salesChannelContext);

            return $this->twig->render($resolvedTemplate, [
                'context' => $salesChannelContext,
                'element' => $slot,
            ]);
        } catch (\Throwable $e) {
            throw CmsElementRenderException::forType($type, $e);
        }
    }

    /**
     * Build a CMS slot and resolve its data through Shopware's element resolvers,
     * falling back to a flattened-config ArrayEntity for types without a resolver.
     *
     * @param array<string, mixed> $config
     */
    private function buildResolvedSlot(string $type, array $config, SalesChannelContext $salesChannelContext): CmsSlotEntity
    {
        $slot = new CmsSlotEntity();
        $slot->setId(Uuid::randomHex());
        $slot->setType($type);
        $slot->setSlot($type);
        $slot->setBlockId(Uuid::randomHex());
        $slot->setLocked(false);
        $slot->setCmsBlockVersionId(null);
        $slot->setConfig($config);
        $slot->addTranslated('config', $config);
        $slot->setData(new ArrayEntity($this->flattenConfigValues($config)));

        $this->cmsSlotsDataResolver->resolve(
            new CmsSlotCollection([$slot]),
            new ResolverContext($salesChannelContext, new Request())
        );

        return $slot;
    }

    /**
     * Flatten {value, source} config entries to their raw value, for element types
     * whose template reads element.data.* but which have no resolver.
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function flattenConfigValues(array $config): array
    {
        $data = [];

        foreach ($config as $key => $value) {
            $data[$key] = (\is_array($value) && \array_key_exists('value', $value)) ? $value['value'] : $value;
        }

        return $data;
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
}
