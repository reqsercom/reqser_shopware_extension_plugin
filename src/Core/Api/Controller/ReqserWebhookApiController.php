<?php declare(strict_types=1);

namespace Reqser\Plugin\Core\Api\Controller;

use Psr\Log\LoggerInterface;
use Reqser\Plugin\Service\ReqserApiAuthService;
use Reqser\Plugin\Service\ReqserWebhookManagementService;
use Shopware\Core\Framework\Context;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Admin API Controller for Reqser Webhook Management
 * Allows the Reqser server to read, activate, or deactivate ReqserApp webhooks
 */
#[Route(defaults: ['_routeScope' => ['api']])]
class ReqserWebhookApiController extends AbstractController
{
    private ReqserWebhookManagementService $webhookManagementService;
    private ReqserApiAuthService $authService;
    private LoggerInterface $logger;

    public function __construct(
        ReqserWebhookManagementService $webhookManagementService,
        ReqserApiAuthService $authService,
        LoggerInterface $logger
    ) {
        $this->webhookManagementService = $webhookManagementService;
        $this->authService = $authService;
        $this->logger = $logger;
    }

    /**
     * API endpoint to read the current status of ReqserApp webhooks.
     *
     * Optional query parameter:
     * - eventName: Shopware event name (e.g. "product.written") to get a single webhook.
     *   Omit to return all ReqserApp webhooks.
     *
     * @param Request $request
     * @param Context $context
     * @return JsonResponse
     */
    #[Route(
        path: '/api/_action/reqser/webhooks/status',
        name: 'api.action.reqser.webhooks.status.get',
        methods: ['GET']
    )]
    public function getWebhookStatus(Request $request, Context $context): JsonResponse
    {
        try {
            $authResponse = $this->authService->validateAuthentication($request, $context);
            if ($authResponse !== true) {
                return $authResponse;
            }

            $eventName = $request->query->get('eventName');

            if (!empty($eventName)) {
                $webhooks = [$this->webhookManagementService->getWebhookStatus($eventName)];
            } else {
                $webhooks = $this->webhookManagementService->getAllWebhookStatuses();
            }

            return new JsonResponse([
                'success' => true,
                'data' => [
                    'webhooks' => $webhooks,
                ],
                'timestamp' => date('Y-m-d H:i:s'),
            ]);

        } catch (\InvalidArgumentException $e) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Webhook not found',
                'message' => $e->getMessage(),
            ], 404);

        } catch (\Throwable $e) {
            $this->logger->error('Reqser Webhook Management: Error reading webhook status: ' . $e->getMessage(), [
                'file' => __FILE__,
                'line' => __LINE__,
            ]);

            return new JsonResponse([
                'success' => false,
                'error' => 'Error reading webhook status',
                'message' => $e->getMessage(),
                'exceptionType' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ], 500);
        }
    }

    /**
     * API endpoint to activate or deactivate a ReqserApp webhook.
     *
     * Request body:
     * - eventName: Shopware event name (e.g. "product.written", "category.written")
     * - active: boolean — true to activate, false to deactivate
     *
     * @param Request $request
     * @param Context $context
     * @return JsonResponse
     */
    #[Route(
        path: '/api/_action/reqser/webhooks/status',
        name: 'api.action.reqser.webhooks.status.update',
        methods: ['POST']
    )]
    public function updateWebhookStatus(Request $request, Context $context): JsonResponse
    {
        try {
            // Validate authentication
            $authResponse = $this->authService->validateAuthentication($request, $context);
            if ($authResponse !== true) {
                return $authResponse;
            }

            $body = json_decode($request->getContent(), true) ?? [];

            $eventName = $body['eventName'] ?? null;
            if (empty($eventName) || !\is_string($eventName)) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Missing required parameter',
                    'message' => 'The parameter "eventName" is required and must be a non-empty string',
                ], 400);
            }

            if (!isset($body['active']) || !\is_bool($body['active'])) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Missing required parameter',
                    'message' => 'The parameter "active" is required and must be a boolean',
                ], 400);
            }

            $active = $body['active'];

            $result = $this->webhookManagementService->setWebhookStatus($eventName, $active, $context);

            return new JsonResponse([
                'success' => true,
                'data' => $result,
                'timestamp' => date('Y-m-d H:i:s'),
            ]);

        } catch (\InvalidArgumentException $e) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Webhook not found',
                'message' => $e->getMessage(),
            ], 404);

        } catch (\Throwable $e) {
            $this->logger->error('Reqser Webhook Management: Error updating webhook status: ' . $e->getMessage(), [
                'file' => __FILE__,
                'line' => __LINE__,
            ]);

            return new JsonResponse([
                'success' => false,
                'error' => 'Error updating webhook status',
                'message' => $e->getMessage(),
                'exceptionType' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ], 500);
        }
    }
}
