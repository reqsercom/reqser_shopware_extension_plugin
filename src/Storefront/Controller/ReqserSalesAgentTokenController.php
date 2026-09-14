<?php declare(strict_types=1);

namespace Reqser\Plugin\Storefront\Controller;

use Psr\Log\LoggerInterface;
use Reqser\Plugin\Service\ReqserSalesAgentService;
use Reqser\Plugin\Service\ReqserSalesAgentTokenService;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route(defaults: ['_routeScope' => ['storefront']])]
class ReqserSalesAgentTokenController extends StorefrontController
{
    private $salesAgentService;
    private $tokenService;
    private LoggerInterface $logger;

    /**
     * @param ReqserSalesAgentService $salesAgentService
     * @param ReqserSalesAgentTokenService $tokenService
     * @param LoggerInterface $logger
     */
    public function __construct(
        ReqserSalesAgentService $salesAgentService,
        ReqserSalesAgentTokenService $tokenService,
        LoggerInterface $logger
    ) {
        $this->salesAgentService = $salesAgentService;
        $this->tokenService = $tokenService;
        $this->logger = $logger;
    }

    /**
     * Hand the visitor a short-lived token proving their session belongs to this shop
     *
     * @param Request $request
     * @param SalesChannelContext $salesChannelContext
     * @return JsonResponse
     */
    #[Route(path: '/reqser/sales-agent/token', name: 'frontend.reqser.sales_agent.token', defaults: ['XmlHttpRequest' => true, '_noStore' => true], methods: ['GET'])]
    public function token(Request $request, SalesChannelContext $salesChannelContext): JsonResponse
    {
        try {
            $config = $this->salesAgentService->getWidgetConfig($request);
            if ($config === null) {
                return new JsonResponse(['success' => false, 'reason' => 'inactive'], 403);
            }

            $customer = $salesChannelContext->getCustomer();

            $issued = $this->tokenService->issue([
                'salesChannelId' => $salesChannelContext->getSalesChannelId(),
                'domainId' => $config['domainId'],
                'languageId' => $salesChannelContext->getLanguageId(),
                'currencyId' => $salesChannelContext->getCurrencyId(),
                'customerGroupId' => $salesChannelContext->getCurrentCustomerGroup()->getId(),
                'loggedIn' => $customer !== null,
            ]);

            if ($issued === null) {
                return new JsonResponse(['success' => false, 'reason' => 'not_registered'], 409);
            }

            return new JsonResponse([
                'success' => true,
                'token' => $issued['token'],
                'expiresAt' => $issued['expiresAt'],
                'widgetOrigin' => $config['widgetOrigin'],
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Reqser sales agent: token issuing failed.', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return new JsonResponse(['success' => false, 'reason' => 'internal_error'], 500);
        }
    }
}
