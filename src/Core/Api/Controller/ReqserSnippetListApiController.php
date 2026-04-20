<?php declare(strict_types=1);

namespace Reqser\Plugin\Core\Api\Controller;

use Reqser\Plugin\Service\ReqserApiAuthService;
use Reqser\Plugin\Service\ReqserSnippetListService;
use Shopware\Core\Framework\Context;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

#[Route(defaults: ['_routeScope' => ['api']])]
class ReqserSnippetListApiController extends AbstractController
{
    public function __construct(
        private readonly ReqserSnippetListService $snippetListService,
        private readonly ReqserApiAuthService $authService
    ) {
    }

    /**
     * Return snippet list like the Shopware admin snippet list.
     *
     * Request body:
     * - snippetSetIds (array|string, required)
     * - page (int, optional, default 1)
     * - limit (int, optional, default 25)
     * - filters (array, optional)
     * - sort (array, optional)
     * - translationKeys (array, optional) — when provided, only snippets whose
     *   translationKey is in this list are returned. Uses Shopware's native
     *   TranslationKeyFilter so filtering happens before pagination inside
     *   SnippetService::getList(). The response total reflects the filtered count.
     *   unfilteredTotal contains the total snippet count without the filter.
     */
    #[Route(
        path: '/api/_action/reqser/snippets/list',
        name: 'api.action.reqser.snippets.list',
        methods: ['POST']
    )]
    public function getSnippetList(Request $request, Context $context): JsonResponse
    {
        $authResponse = $this->authService->validateAuthentication($request, $context);
        if ($authResponse !== true) {
            return $authResponse;
        }

        $payload = json_decode($request->getContent(), true) ?? [];

        $snippetSetIds = $payload['snippetSetIds'] ?? $payload['snippetSetId'] ?? null;
        if (\is_string($snippetSetIds)) {
            $snippetSetIds = [$snippetSetIds];
        }

        if (!\is_array($snippetSetIds) || $snippetSetIds === []) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Missing required parameter',
                'message' => 'The parameter "snippetSetIds" is required'
            ], 400);
        }

        $page = (int) ($payload['page'] ?? 1);
        $limit = (int) ($payload['limit'] ?? 25);

        if ($limit < 1) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Invalid parameter',
                'message' => 'The "limit" parameter must be a positive integer'
            ], 400);
        }

        $filters = $payload['filters'] ?? [];
        foreach (array_keys($filters) as $filterName) {
            if (!\is_string($filterName)) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Invalid parameter',
                    'message' => 'Filter names must be strings'
                ], 400);
            }
        }

        $sort = $payload['sort'] ?? [];

        $translationKeys = $payload['translationKeys'] ?? null;
        if ($translationKeys !== null && !\is_array($translationKeys)) {
            $translationKeys = null;
        }

        $result = $this->snippetListService->getListForSnippetSets(
            array_values($snippetSetIds),
            $page,
            $limit,
            $context,
            $filters,
            $sort,
            $translationKeys
        );

        return new JsonResponse($result);
    }
}
