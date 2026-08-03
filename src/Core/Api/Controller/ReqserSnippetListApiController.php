<?php declare(strict_types=1);

namespace Reqser\Plugin\Core\Api\Controller;

use Reqser\Plugin\Core\Api\Attribute\ReqserApiAuth;
use Reqser\Plugin\Service\ReqserSnippetListService;
use Reqser\Plugin\Service\ReqserSnippetStorefrontService;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\Snippet\SnippetException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

#[Route(defaults: ['_routeScope' => ['api']])]
#[ReqserApiAuth]
class ReqserSnippetListApiController extends AbstractController
{
    /**
     * @param ReqserSnippetListService $snippetListService
     * @param ReqserSnippetStorefrontService $snippetStorefrontService
     */
    public function __construct(
        private readonly ReqserSnippetListService $snippetListService,
        private readonly ReqserSnippetStorefrontService $snippetStorefrontService
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
     * - translationKeys (array, optional) — since 1.7.17: when provided, only
     *   snippets whose translationKey is in this list are returned. Uses
     *   Shopware's native TranslationKeyFilter so filtering happens before
     *   pagination. The response total reflects the filtered count.
     *   unfilteredTotal contains the total snippet count without the filter.
     *
     * @param Request $request
     * @param Context $context
     * @return JsonResponse
     */
    #[Route(
        path: '/api/_action/reqser/snippets/list',
        name: 'api.action.reqser.snippets.list',
        methods: ['POST']
    )]
    public function getSnippetList(Request $request, Context $context): JsonResponse
    {
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

    /**
     * Return storefront-effective snippet values for the given snippet sets.
     *
     * Request body:
     * - snippetSetIds (array|string, required)
     * - translationKeys (array, optional)
     * - salesChannelId (string, optional) — since 2.0.34: storefront sales channel for theme scoping
     *
     * @param Request $request
     * @return JsonResponse
     */
    #[Route(
        path: '/api/_action/reqser/snippets/storefront',
        name: 'api.action.reqser.snippets.storefront',
        methods: ['POST']
    )]
    public function getStorefrontSnippets(Request $request): JsonResponse
    {
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

        $translationKeys = $payload['translationKeys'] ?? null;
        if ($translationKeys !== null && !\is_array($translationKeys)) {
            $translationKeys = null;
        }

        $sales_channel_id = $payload['salesChannelId'] ?? null;
        if ($sales_channel_id !== null) {
            if (!\is_string($sales_channel_id) || !Uuid::isValid($sales_channel_id)) {
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Invalid parameter',
                    'message' => 'The parameter "salesChannelId" must be a valid UUID',
                ], 400);
            }
        }

        try {
            $data = $this->snippetStorefrontService->getStorefrontSnippetsForSets(
                array_values($snippetSetIds),
                $translationKeys,
                $sales_channel_id
            );
        } catch (\InvalidArgumentException $exception) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Invalid parameter',
                'message' => $exception->getMessage(),
            ], 400);
        }

        return new JsonResponse(['data' => $data]);
    }
}
