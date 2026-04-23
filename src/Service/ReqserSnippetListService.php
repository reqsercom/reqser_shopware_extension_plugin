<?php declare(strict_types=1);

namespace Reqser\Plugin\Service;

use Shopware\Core\Framework\Context;
use Shopware\Core\System\Snippet\SnippetService;

class ReqserSnippetListService
{
    /**
     * @param SnippetService $snippetService
     */
    public function __construct(
        private readonly SnippetService $snippetService
    ) {
    }

    /**
     * @param array $snippetSetIds
     * @param int $page
     * @param int $limit
     * @param Context $context
     * @param array $filters
     * @param array $sort
     * @param array|null $translationKeys
     * @return array
     */
    public function getListForSnippetSets(
        array $snippetSetIds,
        int $page,
        int $limit,
        Context $context,
        array $filters,
        array $sort,
        array|null $translationKeys = null
    ): array {
        if ($translationKeys !== null) {
            $filters['translationKey'] = $translationKeys;
        }

        $result = $this->snippetService->getList($page, $limit, $context, $filters, $sort);

        $filteredData = [];
        foreach ($result['data'] as $translationKey => $snippets) {
            $matched = array_values(array_filter($snippets, static function (array $snippet) use ($snippetSetIds): bool {
                return isset($snippet['setId']) && \in_array($snippet['setId'], $snippetSetIds, true);
            }));

            if ($matched !== []) {
                $filteredData[$translationKey] = $matched;
            }
        }

        $response = [
            'total' => \count($filteredData),
            'data' => $filteredData,
        ];

        if ($translationKeys !== null) {
            $response['unfilteredTotal'] = (int) ($result['total'] ?? 0);
        }

        return $response;
    }
}
