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
 * Provides order and amount distribution statistics grouped by language
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
     * API endpoint to get order and amount distribution percentage breakdown by language.
     *
     * Request body (all fields optional):
     * - from: Start date in Y-m-d format
     * - until: End date in Y-m-d format
     * - salesChannelId: Hex ID of the sales channel to filter by
     *
     * @param Request $request
     * @param Context $context
     * @return JsonResponse
     */
    #[Route(
        path: '/api/_action/reqser/analytics/language-distribution',
        name: 'api.action.reqser.analytics.language_distribution',
        methods: ['POST']
    )]
    public function getLanguageDistribution(Request $request, Context $context): JsonResponse
    {
        try {
            // Validate authentication
            $authResponse = $this->authService->validateAuthentication($request, $context);
            if ($authResponse !== true) {
                return $authResponse;
            }

            $body = json_decode($request->getContent(), true) ?? [];

            $filters = [];

            // Validate and collect optional date filters
            $from = $body['from'] ?? null;
            $until = $body['until'] ?? null;

            if ($from !== null) {
                $fromDate = \DateTimeImmutable::createFromFormat('Y-m-d', $from);
                if (!$fromDate || $fromDate->format('Y-m-d') !== $from) {
                    return new JsonResponse([
                        'success' => false,
                        'error' => 'Invalid date format',
                        'message' => '"from" must be a valid date in Y-m-d format',
                    ], 400);
                }
                $filters['from'] = $from;
            }

            if ($until !== null) {
                $untilDate = \DateTimeImmutable::createFromFormat('Y-m-d', $until);
                if (!$untilDate || $untilDate->format('Y-m-d') !== $until) {
                    return new JsonResponse([
                        'success' => false,
                        'error' => 'Invalid date format',
                        'message' => '"until" must be a valid date in Y-m-d format',
                    ], 400);
                }
                $filters['until'] = $until;
            }

            if ($from !== null && $until !== null && $fromDate > $untilDate) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Invalid date range',
                    'message' => '"from" date must be before or equal to "until" date',
                ], 400);
            }

            // Collect optional entity filters
            if (!empty($body['salesChannelId'])) {
                $filters['salesChannelId'] = $body['salesChannelId'];
            }

            $languages = $this->analyticsService->getLanguageDistribution($filters);

            return new JsonResponse([
                'success' => true,
                'data' => [
                    'filters' => $filters,
                    'languages' => $languages,
                ],
                'timestamp' => date('Y-m-d H:i:s'),
            ]);

        } catch (\Throwable $e) {
            $this->logger->error('Reqser Analytics: Error fetching language distribution: ' . $e->getMessage(), [
                'file' => __FILE__,
                'line' => __LINE__,
            ]);

            return new JsonResponse([
                'success' => false,
                'error' => 'Error fetching language distribution',
                'message' => $e->getMessage(),
                'exceptionType' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ], 500);
        }
    }
}
