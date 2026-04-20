<?php declare(strict_types=1);

namespace Reqser\Plugin\Service;

use Shopware\Core\Framework\Context;
use Shopware\Core\System\Snippet\SnippetService;

class ReqserSnippetListService
{
    public function __construct(
        private readonly SnippetService $snippetService
    ) {
    }

    /**
     * @param list<string> $snippetSetIds
     * @param array<string, mixed> $filters
     * @param array<string, mixed> $sort
     * @param list<string>|null $translationKeys When non-null, passed as native Shopware
     *                                           'translationKey' filter so filtering happens
     *                                           before pagination inside SnippetService::getList()
     *
     * @return array{total:int, data: array<string, list<array<string, mixed>>>}
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

        $translationKeySet = $translationKeys !== null ? array_flip($translationKeys) : null;

        $filteredData = [];
        foreach ($result['data'] as $translationKey => $snippets) {
            if ($translationKeySet !== null && !isset($translationKeySet[$translationKey])) {
                continue;
            }

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
