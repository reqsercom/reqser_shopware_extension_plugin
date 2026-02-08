<?php declare(strict_types=1);

namespace Reqser\Plugin\Core\Api\Controller;

use Psr\Log\LoggerInterface;
use Reqser\Plugin\Service\ReqserAnalyticsService;
use Reqser\Plugin\Service\ReqserApiAuthService;
use Shopware\Core\Framework\Context;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Admin API Controller for Reqser Analytics
 * Provides revenue and order statistics grouped by language
 */
#[Route(defaults: ['_routeScope' => ['api']])]
class ReqserAnalyticsApiController extends AbstractController
{
    private ReqserAnalyticsService $analyticsService;
    private ReqserApiAuthService $authService;
    private LoggerInterface $logger;

    public function __construct(
        ReqserAnalyticsService $analyticsService,
        ReqserApiAuthService $authService,
        LoggerInterface $logger
    ) {
        $this->analyticsService = $analyticsService;
        $this->authService = $authService;
        $this->logger = $logger;
    }

    /**
     * API endpoint to get revenue and order percentage breakdown by language.
     *
     * Query parameters:
     * - from (required): Start date in Y-m-d format
     * - until (required): End date in Y-m-d format
     *
     * @param Request $request
     * @param Context $context
     * @return JsonResponse
     */
    #[Route(
        path: '/api/_action/reqser/analytics/revenue-by-language',
        name: 'api.action.reqser.analytics.revenue_by_language',
        methods: ['GET']
    )]
    public function getRevenueByLanguage(Request $request, Context $context): JsonResponse
    {
        try {
            // Validate authentication
            $authResponse = $this->authService->validateAuthentication($request, $context);
            if ($authResponse !== true) {
                return $authResponse;
            }

            $from = $request->query->get('from');
            $until = $request->query->get('until');

            // Validate required parameters
            if (empty($from) || empty($until)) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Missing required parameters',
                    'message' => 'Both "from" and "until" query parameters are required (format: Y-m-d)',
                ], 400);
            }

            // Validate date formats
            $fromDate = \DateTimeImmutable::createFromFormat('Y-m-d', $from);
            $untilDate = \DateTimeImmutable::createFromFormat('Y-m-d', $until);

            if (!$fromDate || $fromDate->format('Y-m-d') !== $from) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Invalid date format',
                    'message' => '"from" must be a valid date in Y-m-d format',
                ], 400);
            }

            if (!$untilDate || $untilDate->format('Y-m-d') !== $until) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Invalid date format',
                    'message' => '"until" must be a valid date in Y-m-d format',
                ], 400);
            }

            if ($fromDate > $untilDate) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Invalid date range',
                    'message' => '"from" date must be before or equal to "until" date',
                ], 400);
            }

            $languages = $this->analyticsService->getRevenueByLanguage($from, $until);

            return new JsonResponse([
                'success' => true,
                'data' => [
                    'from' => $from,
                    'until' => $until,
                    'languages' => $languages,
                ],
                'timestamp' => date('Y-m-d H:i:s'),
            ]);

        } catch (\Throwable $e) {
            $this->logger->error('Reqser Analytics: Error fetching revenue by language: ' . $e->getMessage(), [
                'file' => __FILE__,
                'line' => __LINE__,
            ]);

            return new JsonResponse([
                'success' => false,
                'error' => 'Error fetching revenue by language',
                'message' => $e->getMessage(),
                'exceptionType' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ], 500);
        }
    }
}
