<?php declare(strict_types=1);

namespace Reqser\Plugin\Service;

use Shopware\Core\Framework\Adapter\Twig\TemplateFinder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Twig\Environment as TwigEnvironment;
use Twig\Error\LoaderError;

/**
 * Discovers every active storefront Twig template in the installation.
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
     * Walks every bundle returned by `kernel->getBundles()` and scans
     * `<bundle>/Resources/views/storefront/` recursively. The outer walk
     * is wrapped in try/catch so fatal failures yield a partial list with
     * `_warnings` rather than HTTP 500.
     *
     * @return array{
     *     twigFiles: array<int, array{fileName: string, path: string, source: string, content: string}>,
     *     warnings: list<string>
     * }
     */
    public function getAllActiveTwigFiles(): array
    {
        $warnings = [];
        $result = [];

        try {
            $this->templateFinder->reset();

            $bundlesByPath = $this->buildBundlePathMap();
            $refs = $this->discoverAllStorefrontTemplateRefs($bundlesByPath);

            $seen = [];

            foreach ($refs as $ref) {
                $resolved = $this->resolveTemplateRef($ref, $bundlesByPath);
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
        } catch (\Throwable $e) {
            $warnings[] = 'twig_files_discovery_failed: ' . $e->getMessage();
        }

        return ['twigFiles' => $result, 'warnings' => $warnings];
    }

    /**
     * Build the `[absoluteBundlePath => bundleName]` map for source
     * attribution. Each bundle contributes both its raw `Bundle::getPath()`
     * and its `realpath()` to handle composer-symlinked bundles
     * (`custom/static-plugins/*`).
     *
     * @return array<string, string> sorted by path length DESC
     */
    private function buildBundlePathMap(): array
    {
        $kernel = $this->container->get('kernel');
        if (!$kernel instanceof KernelInterface) {
            return [];
        }

        $map = [];
        foreach ($kernel->getBundles() as $bundle) {
            $rawPath = $bundle->getPath();
            $name = $bundle->getName();

            $candidates = [$this->normalizePath($rawPath)];

            $real = realpath($rawPath);
            if ($real !== false) {
                $candidates[] = $this->normalizePath($real);
            }

            foreach (array_unique($candidates) as $p) {
                if ($p === '') {
                    continue;
                }
                // First bundle to claim a path wins (with longer paths
                // sorted first below). In practice each path is unique
                // per bundle; this guards against future overlaps.
                if (!isset($map[$p])) {
                    $map[$p] = $name;
                }
            }
        }

        uksort($map, static fn (string $a, string $b): int => strlen($b) - strlen($a));

        return $map;
    }

    /**
     * Normalize a filesystem path for prefix comparison: backslash → slash,
     * collapse repeated separators, drop trailing slash. (Composer path
     * repositories sometimes inject `//` into bundle paths.)
     */
    private function normalizePath(string $path): string
    {
        $normalized = str_replace('\\', '/', $path);
        $normalized = preg_replace('#/+#', '/', $normalized) ?? $normalized;
        return rtrim($normalized, '/');
    }

    /**
     * Discover every distinct storefront template ref across every active
     * bundle. Returns namespaced Twig refs like
     * `@Storefront/storefront/layout/footer/footer.html.twig` ready for
     * `TemplateFinder` resolution.
     *
     * @param array<string, string> $bundlesByPath produced by buildBundlePathMap()
     * @return array<string>
     */
    private function discoverAllStorefrontTemplateRefs(array $bundlesByPath): array
    {
        $refs = [];

        foreach (array_keys($bundlesByPath) as $bundlePath) {
            $root = $bundlePath . '/Resources/views/storefront';
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
     * @param array<string, string> $bundlesByPath
     * @return ?array
     */
    private function resolveTemplateRef(string $templateRef, array $bundlesByPath): ?array
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

            $pathInfo = $this->parseTemplatePath($actualPath, $relativePath, $bundlesByPath);

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
     * Map a resolved template path back to its owning bundle name + directory.
     *
     * @param array<string, string> $bundlesByPath
     * @return array{source: string, directory: string}
     */
    private function parseTemplatePath(string $absolutePath, string $relativePath, array $bundlesByPath): array
    {
        $directory = dirname($relativePath);

        $candidatePaths = [$this->normalizePath($absolutePath)];
        $real = realpath($absolutePath);
        if ($real !== false) {
            $candidatePaths[] = $this->normalizePath($real);
        }
        $candidatePaths = array_values(array_unique($candidatePaths));

        $projectDir = $this->normalizePath(
            (string) $this->container->getParameter('kernel.project_dir')
        );
        $shopwareCorePrefix = $projectDir . '/vendor/shopware/';

        foreach ($bundlesByPath as $bundlePath => $bundleName) {
            $matched = false;
            foreach ($candidatePaths as $candidate) {
                if (str_starts_with($candidate, $bundlePath . '/')) {
                    $matched = true;
                    break;
                }
            }
            if (!$matched) {
                continue;
            }

            if (str_starts_with($bundlePath, $shopwareCorePrefix)) {
                return ['source' => 'core', 'directory' => $directory];
            }

            return ['source' => $bundleName, 'directory' => $directory];
        }

        // Fallback for paths outside the bundle map.
        if (str_starts_with($relativePath, 'vendor/shopware/')) {
            return ['source' => 'core', 'directory' => $directory];
        }
        if (str_starts_with($relativePath, 'custom/plugins/')) {
            $parts = explode('/', $relativePath);
            return [
                'source'    => $parts[2] ?? 'unknown',
                'directory' => $directory,
            ];
        }

        return ['source' => 'unknown', 'directory' => $directory];
    }
}
