<?php declare(strict_types=1);

namespace Reqser\Plugin\Service;

use Symfony\Component\DependencyInjection\ContainerInterface;
use Twig\Environment as TwigEnvironment;

/**
 * Service for discovering CMS element Twig template files
 * 
 * Uses Shopware's Twig template inheritance system to find the actual active templates,
 * respecting plugin overrides and template inheritance.
 */
class ReqserCmsTwigFileService
{
    private ContainerInterface $container;
    private TwigEnvironment $twig;

    public function __construct(ContainerInterface $container, TwigEnvironment $twig)
    {
        $this->container = $container;
        $this->twig = $twig;
    }

    /**
     * Get all CMS element Twig template files with content
     * 
     * Discovers all cms-element-*.html.twig template names and resolves them through
     * Shopware's Twig loader to get the actual active template (respecting overrides).
     * 
     * @return array<array{fileName: string, path: string, source: string, content: string}>
     */
    public function getAllCmsElementTwigFiles(): array
    {
        $result = [];

        // Discover all unique CMS element template names
        $templateNames = $this->discoverAllCmsElementTemplateNames();

        // Resolve each template through Twig to get the actual active version
        foreach ($templateNames as $templateName) {
            $resolved = $this->resolveTemplate($templateName);
            if ($resolved !== null) {
                $result[] = $resolved;
            }
        }

        // Sort alphabetically by filename
        usort($result, function($a, $b) {
            return strcmp($a['fileName'], $b['fileName']);
        });

        return $result;
    }

    /**
     * Discover all CMS element template names from core and plugins
     * 
     * @return array<string> Array of template names (e.g., '@Storefront/storefront/element/cms-element-text.html.twig')
     */
    private function discoverAllCmsElementTemplateNames(): array
    {
        $templateNames = [];

        // Scan core templates
        $templateNames = array_merge($templateNames, $this->scanCoreTemplateNames());

        // Scan plugin templates
        $templateNames = array_merge($templateNames, $this->scanPluginTemplateNames());

        // Return unique template names
        return array_unique($templateNames);
    }

    /**
     * Scan Shopware core for CMS element template names
     * 
     * @return array<string>
     */
    private function scanCoreTemplateNames(): array
    {
        $templateNames = [];
        $projectDir = $this->container->getParameter('kernel.project_dir');
        $coreElementPath = $projectDir . '/vendor/shopware/storefront/Resources/views/storefront/element';

        if (!is_dir($coreElementPath)) {
            return $templateNames;
        }

        $files = glob($coreElementPath . '/cms-element-*.html.twig');
        if ($files === false) {
            return $templateNames;
        }

        foreach ($files as $file) {
            $filename = basename($file);
            // Shopware template name format
            $templateNames[] = '@Storefront/storefront/element/' . $filename;
        }

        return $templateNames;
    }

    /**
     * Scan custom plugins for CMS element template names
     * 
     * @return array<string>
     */
    private function scanPluginTemplateNames(): array
    {
        $templateNames = [];
        $projectDir = $this->container->getParameter('kernel.project_dir');
        $pluginsPath = $projectDir . '/custom/plugins';

        if (!is_dir($pluginsPath)) {
            return $templateNames;
        }

        $pluginDirs = scandir($pluginsPath);

        foreach ($pluginDirs as $pluginDir) {
            if ($pluginDir === '.' || $pluginDir === '..') {
                continue;
            }

            $elementPath = $pluginsPath . '/' . $pluginDir . '/src/Resources/views/storefront/element';

            if (!is_dir($elementPath)) {
                continue;
            }

            $files = glob($elementPath . '/cms-element-*.html.twig');
            if ($files === false) {
                continue;
            }

            foreach ($files as $file) {
                $filename = basename($file);
                // Plugin overrides use the same template name as core
                $templateNames[] = '@Storefront/storefront/element/' . $filename;
            }
        }

        return $templateNames;
    }

    /**
     * Resolve a template through Shopware's Twig loader to get the actual active file
     * 
     * This respects template inheritance - if a plugin overrides a core template,
     * this will return the plugin's version.
     * 
     * @param string $templateName Twig template name
     * @return array{fileName: string, path: string, source: string, content: string}|null
     */
    private function resolveTemplate(string $templateName): ?array
    {
        try {
            $loader = $this->twig->getLoader();

            // Check if template exists
            if (!$loader->exists($templateName)) {
                return null;
            }

            // Get the actual source (respects inheritance/overrides)
            $source = $loader->getSourceContext($templateName);
            $actualPath = $source->getPath();
            $content = $source->getCode();

            if (empty($actualPath)) {
                return null;
            }

            // Determine source (core or plugin name) from the actual file path
            $projectDir = $this->container->getParameter('kernel.project_dir');
            $relativePath = str_replace($projectDir . '/', '', $actualPath);
            $relativePath = str_replace('\\', '/', $relativePath);

            // Parse the path to determine source and directory
            $pathInfo = $this->parseTemplatePath($relativePath);

            return [
                'fileName' => basename($actualPath),
                'path' => $pathInfo['directory'],
                'source' => $pathInfo['source'],
                'content' => base64_encode($content)
            ];

        } catch (\Throwable $e) {
            // Template resolution failed - skip this one
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
        // Extract directory without filename
        $directory = dirname($relativePath);

        // Determine source from path
        if (str_starts_with($relativePath, 'vendor/shopware/')) {
            $source = 'core';
        } elseif (str_starts_with($relativePath, 'custom/plugins/')) {
            // Extract plugin name from path: custom/plugins/PluginName/...
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
