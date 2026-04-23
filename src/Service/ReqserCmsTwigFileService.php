<?php declare(strict_types=1);

namespace Reqser\Plugin\Service;

use Shopware\Core\Framework\Adapter\Twig\TemplateFinder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Twig\Environment as TwigEnvironment;
use Twig\Error\LoaderError;

/**
 * Discovers every active storefront Twig template in the installation.
 *
 * Works in two stages:
 *   1. Recursively collect every *.html.twig under
 *      `vendor/shopware/storefront/Resources/views/storefront/` and under each
 *      `custom/plugins/*​/src/Resources/views/storefront/`. This yields the set
 *      of *possible* template references, namespaced as
 *      `@Storefront/storefront/<relative>` so TemplateFinder can resolve them.
 *   2. Resolve each ref through Shopware's TemplateFinder. TemplateFinder walks
 *      the kernel bundle priority chain (theme > plugin > core), so it returns
 *      the single *active* version for each path. Templates belonging to
 *      deactivated plugins are never returned because deactivated plugins are
 *      not in the kernel bundle list.
 *
 * The returned `source` field tells the consumer whether the resolved template
 * is `core` (shipped by shopware/storefront) or the name of the plugin it came
 * from. Duplicate resolutions are collapsed by (source, path, fileName).
 *
 * Name kept for DI-wiring compatibility despite now covering more than just
 * CMS elements.
 */
class ReqserCmsTwigFileService
{
    private ContainerInterface $container;
    private TwigEnvironment $twig;
    private TemplateFinder $templateFinder;

    /**
     * @param ContainerInterface $container
     * @param TwigEnvironment $twig
     * @param TemplateFinder $templateFinder
     */
    public function __construct(
        ContainerInterface $container,
        TwigEnvironment $twig,
        TemplateFinder $templateFinder
    ) {
        $this->container = $container;
        $this->twig = $twig;
        $this->templateFinder = $templateFinder;
    }

    /**
     * Return every active storefront .html.twig template in the installation.
     *
     * @return array<array{fileName: string, path: string, source: string, content: string}>
     */
    public function getAllActiveTwigFiles(): array
    {
        $this->templateFinder->reset();

        $refs = $this->discoverAllStorefrontTemplateRefs();

        $result = [];
        $seen = [];

        foreach ($refs as $ref) {
            $resolved = $this->resolveTemplateRef($ref);
            if ($resolved === null) {
                continue;
            }

            $key = ($resolved['source'] ?? '') . '|'
                . ($resolved['path'] ?? '') . '|'
                . ($resolved['fileName'] ?? '');
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $result[] = $resolved;
        }

        usort($result, static function (array $a, array $b): int {
            $ka = ($a['path'] ?? '') . '/' . ($a['fileName'] ?? '');
            $kb = ($b['path'] ?? '') . '/' . ($b['fileName'] ?? '');
            return strcmp($ka, $kb);
        });

        return $result;
    }

    /**
     * Discover every distinct storefront template reference across core and
     * every custom plugin. Recursive over the full storefront/ tree (so
     * layout/, component/, block/, section/, element/, page/, utilities/, and
     * root-level files like base.html.twig are all covered). Returns namespaced
     * Twig refs like `@Storefront/storefront/layout/footer/footer.html.twig`.
     *
     * @return array<string>
     */
    private function discoverAllStorefrontTemplateRefs(): array
    {
        $refs = [];
        $projectDir = (string) $this->container->getParameter('kernel.project_dir');

        $roots = [];
        $roots[] = $projectDir . '/vendor/shopware/storefront/Resources/views/storefront';

        $pluginsPath = $projectDir . '/custom/plugins';
        if (is_dir($pluginsPath)) {
            $pluginDirs = scandir($pluginsPath);
            if ($pluginDirs !== false) {
                foreach ($pluginDirs as $pluginDir) {
                    if ($pluginDir === '.' || $pluginDir === '..') {
                        continue;
                    }
                    $roots[] = $pluginsPath . '/' . $pluginDir . '/src/Resources/views/storefront';
                }
            }
        }

        foreach ($roots as $root) {
            if (!is_dir($root)) {
                continue;
            }

            try {
                $iterator = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator(
                        $root,
                        \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::UNIX_PATHS
                    )
                );
            } catch (\Throwable) {
                continue;
            }

            $rootNorm = str_replace('\\', '/', rtrim($root, '/\\'));

            foreach ($iterator as $file) {
                if (!$file instanceof \SplFileInfo || !$file->isFile()) {
                    continue;
                }
                if (!str_ends_with($file->getFilename(), '.html.twig')) {
                    continue;
                }

                $fullPath = str_replace('\\', '/', $file->getPathname());
                $relative = ltrim(substr($fullPath, strlen($rootNorm)), '/');
                if ($relative === '') {
                    continue;
                }

                $refs['@Storefront/storefront/' . $relative] = true;
            }
        }

        return array_keys($refs);
    }

    /**
     * Resolve a namespaced template ref through Shopware's TemplateFinder and
     * return the single active version for this installation.
     *
     * @param string $templateRef
     * @return ?array
     */
    private function resolveTemplateRef(string $templateRef): ?array
    {
        try {
            try {
                $resolvedName = $this->templateFinder->find($templateRef, true);
            } catch (LoaderError) {
                return null;
            }

            // TemplateFinder returns the bare path (no '@Namespace') when
            // ignoreMissing=true and the template cannot be resolved.
            if (!str_contains($resolvedName, '@')) {
                return null;
            }

            $loader = $this->twig->getLoader();
            if (!$loader->exists($resolvedName)) {
                return null;
            }

            $source = $loader->getSourceContext($resolvedName);
            $actualPath = $source->getPath();
            $content = $source->getCode();

            if (empty($actualPath)) {
                return null;
            }

            $projectDir = (string) $this->container->getParameter('kernel.project_dir');
            $relativePath = str_replace($projectDir . '/', '', $actualPath);
            $relativePath = str_replace('\\', '/', $relativePath);

            $pathInfo = $this->parseTemplatePath($relativePath);

            return [
                'fileName' => basename($actualPath),
                'path'     => $pathInfo['directory'],
                'source'   => $pathInfo['source'],
                'content'  => base64_encode($content),
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Parse template path to determine source (core / plugin name) and directory.
     *
     * @param string $relativePath
     * @return array
     */
    private function parseTemplatePath(string $relativePath): array
    {
        $directory = dirname($relativePath);

        if (str_starts_with($relativePath, 'vendor/shopware/')) {
            $source = 'core';
        } elseif (str_starts_with($relativePath, 'custom/plugins/')) {
            $parts = explode('/', $relativePath);
            $source = $parts[2] ?? 'unknown';
        } else {
            $source = 'unknown';
        }

        return [
            'source' => $source,
            'directory' => $directory,
        ];
    }
}
