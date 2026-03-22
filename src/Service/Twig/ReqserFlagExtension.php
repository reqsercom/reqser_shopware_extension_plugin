<?php declare(strict_types=1);

namespace Reqser\Plugin\Service\Twig;

use Reqser\Plugin\Service\ReqserFlagService;
use Shopware\Core\PlatformRequest;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Twig extension to provide flag helper functions in templates
 */
class ReqserFlagExtension extends AbstractExtension
{
    private ReqserFlagService $flagService;
    private RequestStack $requestStack;

    public function __construct(ReqserFlagService $flagService, RequestStack $requestStack)
    {
        $this->flagService = $flagService;
        $this->requestStack = $requestStack;
    }

    /**
     * @return TwigFunction[]
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('reqser_flag_path', [$this->flagService, 'getFlagPath']),
            new TwigFunction('reqser_flag_class', [$this->flagService, 'getFlagClass']),
            new TwigFunction('reqser_swag_language_pack_installed', [$this->flagService, 'isSwagLanguagePackInstalled']),
            new TwigFunction('reqser_flag_overrides', [$this, 'getFlagOverrides']),
        ];
    }

    /**
     * Returns a map of languageId => flagCountryCode for the given sales channel.
     * Used in the language-widget template to override the flag CSS class.
     *
     * @param string $salesChannelId
     * @return array<string, string>
     */
    public function getFlagOverrides(string $salesChannelId): array
    {
        $request = $this->requestStack->getCurrentRequest();
        if ($request === null) {
            return [];
        }

        $context = $request->attributes->get(PlatformRequest::ATTRIBUTE_SALES_CHANNEL_CONTEXT_OBJECT);
        if (!$context instanceof SalesChannelContext) {
            return [];
        }

        return $this->flagService->getFlagOverrides($salesChannelId, $context->getContext());
    }
}
