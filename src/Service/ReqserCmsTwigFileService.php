<?php declare(strict_types=1);

namespace Reqser\Plugin\Service;

use Shopware\Core\Framework\Adapter\Twig\TemplateFinder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Twig\Environment as TwigEnvironment;
use Twig\Error\LoaderError;

/**
 * Service for discovering CMS element Twig template files
 * 
 * Discovers all cms-element-*.html.twig files by scanning the filesystem (core +
 * plugin directories), then resolves each through Shopware's TemplateFinder to
 * get the actual active template respecting plugin overrides and the bundle
 * namespace hierarchy.
 *
 * This mirrors how Shopware's DocumentTemplateRenderer resolves templates
 * outside of the storefront request context.
 */
class ReqserCmsTwigFileService
{
    private ContainerInterface $container;
    private TwigEnvironment $twig;
    private TemplateFinder $templateFinder;

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
     * Get all CMS element Twig template files with content
     * 
     * Discovers all cms-element-*.html.twig template filenames from the filesystem,
     * then resolves each through TemplateFinder to get the active version (respecting
     * overrides across the full bundle hierarchy).
     * 
     * @return array<array{fileName: string, path: string, source: string, content: string}>
     */
    public function getAllCmsElementTwigFiles(): array
    {
        $result = [];

        $elementFileNames = $this->discoverAllCmsElementFileNames();

        $this->templateFinder->reset();

        foreach ($elementFileNames as $fileName) {
            $resolved = $this->resolveTemplate($fileName);
            if ($resolved !== null) {
                $result[] = $resolved;
            }
        }

        usort($result, function($a, $b) {
            return strcmp($a['fileName'], $b['fileName']);
        });

        return $result;
    }

    /**
     * Discover all unique CMS element template filenames from core and plugins
     *
     * Scans the filesystem to find what element templates exist. Returns just
     * the filenames (e.g., "cms-element-text.html.twig") without namespace
     * prefixes, since TemplateFinder handles namespace resolution.
     * 
     * @return array<string> Array of filenames
     */
    private function discoverAllCmsElementFileNames(): array
    {
        $fileNames = [];
        $projectDir = $this->container->getParameter('kernel.project_dir');

        // Scan core Storefront templates
        $coreElementPath = $projectDir . '/vendor/shopware/storefront/Resources/views/storefront/element';
        $fileNames = array_merge($fileNames, $this->scanDirectoryForCmsElements($coreElementPath));

        // Scan custom plugin templates
        $pluginsPath = $projectDir . '/custom/plugins';
        if (is_dir($pluginsPath)) {
            $pluginDirs = scandir($pluginsPath);
            foreach ($pluginDirs as $pluginDir) {
                if ($pluginDir === '.' || $pluginDir === '..') {
                    continue;
                }
                $elementPath = $pluginsPath . '/' . $pluginDir . '/src/Resources/views/storefront/element';
                $fileNames = array_merge($fileNames, $this->scanDirectoryForCmsElements($elementPath));
            }
        }

        return array_unique($fileNames);
    }

    /**
     * Scan a directory for cms-element-*.html.twig filenames
     * 
     * @return array<string>
     */
    private function scanDirectoryForCmsElements(string $directoryPath): array
    {
        if (!is_dir($directoryPath)) {
            return [];
        }

        $files = glob($directoryPath . '/cms-element-*.html.twig');
        if ($files === false) {
            return [];
        }

        return array_map('basename', $files);
    }

    /**
     * Resolve a CMS element template filename through Shopware's TemplateFinder
     *
     * Uses TemplateFinder::find() which searches the full bundle namespace
     * hierarchy (core, plugins, apps) to find the active template version.
     * 
     * @param string $fileName e.g. "cms-element-text.html.twig"
     * @return array{fileName: string, path: string, source: string, content: string}|null
     */
    private function resolveTemplate(string $fileName): ?array
    {
        try {
            $templateRef = '@Storefront/storefront/element/' . $fileName;

            try {
                $resolvedName = $this->templateFinder->find($templateRef, true);
            } catch (LoaderError) {
                return null;
            }

            // TemplateFinder returns the bare path when ignoreMissing=true and
            // the template doesn't exist in any namespace
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

            $projectDir = $this->container->getParameter('kernel.project_dir');
            $relativePath = str_replace($projectDir . '/', '', $actualPath);
            $relativePath = str_replace('\\', '/', $relativePath);

            $pathInfo = $this->parseTemplatePath($relativePath);

            return [
                'fileName' => basename($actualPath),
                'path' => $pathInfo['directory'],
                'source' => $pathInfo['source'],
                'content' => base64_encode($content)
            ];

        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Parse template path to determine source and directory
     * 
     * @param string $relativePath Relative path from project root
     * @return array{source: string, directory: string}
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
            'directory' => $directory
        ];
    }
}
