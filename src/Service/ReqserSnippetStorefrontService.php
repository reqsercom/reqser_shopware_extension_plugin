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
     * Mirrors what Shopware's storefront serves: SnippetService::getStorefrontSnippets()
     * applies the system-default locale as fallback, includes the sales channel's theme
     * snippet files and overlays DB overrides. Unlike the admin getList route, file-only
     * snippets that exist only in a fallback-locale file (e.g. a de-DE plugin file rendered
     * under a de-CH snippet set) resolve to their fallback value instead of an empty blank.
     *
     * @param array<int, string> $snippetSetIds
     * @param array<int, string>|null $translationKeys When provided, restrict the result to these keys.
     * @return array<string, array<string, string>> snippetSetId => (translationKey => value)
     */
    public function getStorefrontSnippetsForSets(array $snippetSetIds, array|null $translationKeys = null): array
    {
        $fallback_locale = $this->getSystemDefaultLocale();
        $key_filter = ($translationKeys !== null) ? array_flip($translationKeys) : null;

        $result = [];
        foreach ($snippetSetIds as $snippet_set_id) {
            if (!\is_string($snippet_set_id) || $snippet_set_id === '' || !Uuid::isValid($snippet_set_id)) {
                continue;
            }

            $locale = $this->getSnippetSetIso($snippet_set_id);
            if ($locale === null) {
                continue;
            }

            $sales_channel_id = $this->getSalesChannelIdForSnippetSet($snippet_set_id);

            $snippets = $this->snippetService->getStorefrontSnippets(
                new MessageCatalogue($locale),
                $snippet_set_id,
                $fallback_locale,
                $sales_channel_id
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

    private function getSalesChannelIdForSnippetSet(string $snippet_set_id): string|null
    {
        $sales_channel_id = $this->connection->fetchOne(
            'SELECT LOWER(HEX(sales_channel_id)) FROM sales_channel_domain WHERE snippet_set_id = :id LIMIT 1',
            ['id' => Uuid::fromHexToBytes($snippet_set_id)]
        );

        return $sales_channel_id === false ? null : (string) $sales_channel_id;
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
