<?php declare(strict_types=1);

namespace Reqser\SnippetCrawler\Service;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Uuid\Uuid;

class SnippetCrawlerService
{
    private Connection $connection;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    public function crawlAndUpdateSnippets(): void
    {
        $snippetDir = __DIR__ . '/../../Resources/snippets/';
        $files = glob($snippetDir . '*.json');

        foreach ($files as $file) {
            $locale = basename($file, '.json');
            $snippets = json_decode(file_get_contents($file), true);

            foreach ($snippets as $key => $value) {
                $this->insertOrUpdateSnippet($locale, $key, $value);
            }
        }
    }

    private function insertOrUpdateSnippet(string $locale, string $key, string $value): void
    {
        $existingSnippet = $this->connection->fetchOne(
            'SELECT id FROM snippet WHERE translation_key = :key AND locale = :locale',
            ['key' => $key, 'locale' => $locale]
        );

        if ($existingSnippet) {
            // Update existing snippet
            $this->connection->update('snippet', ['value' => $value], ['id' => $existingSnippet]);
        } else {
            // Insert new snippet
            $this->connection->insert('snippet', [
                'id' => Uuid::randomBytes(),
                'translation_key' => $key,
                'locale' => $locale,
                'value' => $value,
                'set_id' => Uuid::randomBytes(),
                'author' => 'ReqserSnippetCrawler'
            ]);
        }
    }
}
