<?php declare(strict_types=1);

namespace Reqser\Plugin\Core\Api\Controller;

use Reqser\Plugin\Service\ReqserApiAuthService;
use Reqser\Plugin\Service\ReqserSnippetListService;
use Shopware\Core\Framework\Context;
use Shopware\Core\System\Snippet\SnippetException;
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
            throw SnippetException::invalidLimitQuery($limit);
        }

        $filters = $payload['filters'] ?? [];
        foreach (array_keys($filters) as $filterName) {
            if (!\is_string($filterName)) {
                throw SnippetException::invalidFilterName();
            }
        }

        $sort = $payload['sort'] ?? [];

        $result = $this->snippetListService->getListForSnippetSets(
            array_values($snippetSetIds),
            $page,
            $limit,
            $context,
            $filters,
            $sort
        );

        return new JsonResponse($result);
    }
}
