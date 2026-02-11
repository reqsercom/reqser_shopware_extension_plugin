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
     *
     * @return array{total:int, data: array<string, list<array<string, mixed>>>}
     */
    public function getListForSnippetSets(
        array $snippetSetIds,
        int $page,
        int $limit,
        Context $context,
        array $filters,
        array $sort
    ): array {
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

        return [
            'total' => \count($filteredData),
            'data' => $filteredData,
        ];
    }
}
