<?php declare(strict_types=1);

namespace Reqser\Plugin\Service;

use Doctrine\DBAL\Connection;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\Snippet\SnippetService;
use Symfony\Component\Translation\MessageCatalogue;

/**
 * Resolves the storefront-effective snippet values (locale fallback, theme files and DB overrides applied) for snippet sets.
 */
class ReqserSnippetStorefrontService
{
    public function __construct(
        private readonly Connection $connection,
        private readonly SnippetService $snippetService
    ) {
    }

    /**
     * Return the storefront-effective snippet map per snippet set.
     *
     * @param array<int, string> $snippetSetIds
     * @param array<int, string>|null $translationKeys When provided, restrict the result to these keys.
     * @param string|null $sales_channel_id Optional storefront sales channel for theme scoping.
     * @return array<string, array<string, string>> snippetSetId => (translationKey => value)
     */
    public function getStorefrontSnippetsForSets(
        array $snippetSetIds,
        array|null $translationKeys = null,
        string|null $sales_channel_id = null
    ): array {
        $fallback_locale = $this->getSystemDefaultLocale();
        $key_filter = ($translationKeys !== null) ? array_flip($translationKeys) : null;

        $result = [];
        foreach ($snippetSetIds as $snippet_set_id) {
            if (!\is_string($snippet_set_id) || $snippet_set_id === '' || !Uuid::isValid($snippet_set_id)) {
                continue;
            }

            if ($sales_channel_id !== null && !$this->salesChannelUsesSnippetSet($sales_channel_id, $snippet_set_id)) {
                throw new \InvalidArgumentException(
                    'salesChannelId is not linked to snippet set ' . $snippet_set_id
                );
            }

            $locale = $this->getSnippetSetIso($snippet_set_id);
            if ($locale === null) {
                continue;
            }

            $effective_sales_channel_id = $sales_channel_id ?? $this->resolveSalesChannelIdForSnippetSet($snippet_set_id);

            $snippets = $this->snippetService->getStorefrontSnippets(
                new MessageCatalogue($locale),
                $snippet_set_id,
                $fallback_locale,
                $effective_sales_channel_id
            );

            if ($key_filter !== null) {
                $snippets = array_intersect_key($snippets, $key_filter);
            }

            $result[$snippet_set_id] = $snippets;
        }

        return $result;
    }

    private function getSnippetSetIso(string $snippet_set_id): string|null
    {
        $iso = $this->connection->fetchOne(
            'SELECT iso FROM snippet_set WHERE id = :id',
            ['id' => Uuid::fromHexToBytes($snippet_set_id)]
        );

        return $iso === false ? null : (string) $iso;
    }

    private function resolveSalesChannelIdForSnippetSet(string $snippet_set_id): string|null
    {
        $sales_channel_id = $this->connection->fetchOne(
            'SELECT LOWER(HEX(scd.sales_channel_id))
             FROM sales_channel_domain scd
             INNER JOIN sales_channel sc ON sc.id = scd.sales_channel_id
             WHERE scd.snippet_set_id = :snippetSetId
               AND sc.type_id = :storefrontTypeId
               AND sc.active = 1
               AND scd.url NOT LIKE :headlessUrlPattern
             ORDER BY
               CASE WHEN scd.url LIKE :httpsPattern THEN 0 ELSE 1 END,
               LENGTH(scd.url) ASC
             LIMIT 1',
            [
                'snippetSetId' => Uuid::fromHexToBytes($snippet_set_id),
                'storefrontTypeId' => Uuid::fromHexToBytes(Defaults::SALES_CHANNEL_TYPE_STOREFRONT),
                'headlessUrlPattern' => 'default.headless%',
                'httpsPattern' => 'https://%',
            ]
        );

        return $sales_channel_id === false ? null : (string) $sales_channel_id;
    }

    private function salesChannelUsesSnippetSet(string $sales_channel_id, string $snippet_set_id): bool
    {
        $linked = $this->connection->fetchOne(
            'SELECT 1 FROM sales_channel_domain
             WHERE sales_channel_id = :salesChannelId
               AND snippet_set_id = :snippetSetId
             LIMIT 1',
            [
                'salesChannelId' => Uuid::fromHexToBytes($sales_channel_id),
                'snippetSetId' => Uuid::fromHexToBytes($snippet_set_id),
            ]
        );

        return $linked !== false;
    }

    private function getSystemDefaultLocale(): string
    {
        $locale = $this->connection->fetchOne(
            'SELECT locale.code
             FROM language
             INNER JOIN locale ON language.locale_id = locale.id
             WHERE language.id = :languageId',
            ['languageId' => Uuid::fromHexToBytes(Defaults::LANGUAGE_SYSTEM)]
        );

        return $locale === false ? 'en-GB' : (string) $locale;
    }
}
