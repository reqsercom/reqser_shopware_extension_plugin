<?php declare(strict_types=1);

namespace Reqser\Plugin\Service;

use Shopware\Core\Framework\Adapter\Twig\TemplateFinder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Twig\Environment as TwigEnvironment;
use Twig\Error\LoaderError;

/**
 * Discovers every active storefront Twig template plus the sw_extends parent chain for each.
 */
class ReqserCmsTwigFileService
{
    private const MAX_INHERITANCE_DEPTH = 10;

    private const EXTENDS_TAG_REGEX = '/\{%-?\s*(sw_extends|extends)\s+[\'"]([^\'"]+)[\'"]/';

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
     * Return every active storefront .html.twig template plus sw_extends ancestors.
     *
     * @return array{
     *     twigFiles: array<int, array{
     *         fileName: string,
     *         path: string,
     *         source: string,
     *         content: string,
     *         templateKey: string,
     *         role: string,
     *         extendsTemplate: string|null,
     *         extendsTemplateRef: string|null
     *     }>,
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

            $entries = [];
            $effective_keys = [];

            foreach ($refs as $ref) {
                $resolved = $this->resolveTemplateRef($ref, $bundlesByPath);
                if ($resolved === null) {
                    continue;
                }

                $key = $resolved['templateKey'];
                if (isset($entries[$key])) {
                    continue;
                }

                $resolved['role'] = 'effective';
                $resolved['extendsTemplate'] = null;
                $resolved['extendsTemplateRef'] = null;
                $entries[$key] = $resolved;
                $effective_keys[$key] = true;
            }

            foreach (array_keys($effective_keys) as $effective_key) {
                $this->walkInheritanceChain($effective_key, $entries, $bundlesByPath);
            }

            $result = array_values($entries);

            usort($result, static function (array $a, array $b): int {
                $ka = ($a['path'] ?? '') . '/' . ($a['fileName'] ?? '');
                $kb = ($b['path'] ?? '') . '/' . ($b['fileName'] ?? '');
                return strcmp($ka, $kb);
            });

            foreach ($result as &$entry) {
                unset($entry['_resolvedName']);
            }
            unset($entry);
        } catch (\Throwable $e) {
            $warnings[] = 'twig_files_discovery_failed: ' . $e->getMessage();
            $result = [];
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

            return $this->loadResolvedTemplate($resolvedName, $bundlesByPath);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param array<string, string> $bundlesByPath
     * @return ?array
     */
    private function loadResolvedTemplate(string $resolvedName, array $bundlesByPath): ?array
    {
        // TemplateFinder returns the bare path (no '@Namespace') when
        // ignoreMissing=true and the template cannot be resolved.
        if (!str_contains($resolvedName, '@')) {
            return null;
        }

        $loader = $this->twig->getLoader();
        if (!$loader->exists($resolvedName)) {
            return null;
        }

        try {
            $source = $loader->getSourceContext($resolvedName);
        } catch (LoaderError) {
            return null;
        }

        $actualPath = $source->getPath();
        $content = $source->getCode();

        if (empty($actualPath)) {
            return null;
        }

        $projectDir = (string) $this->container->getParameter('kernel.project_dir');
        $relativePath = str_replace($projectDir . '/', '', $actualPath);
        $relativePath = str_replace('\\', '/', $relativePath);

        $pathInfo = $this->parseTemplatePath($actualPath, $relativePath, $bundlesByPath);

        $fileName = basename($actualPath);
        $directory = $pathInfo['directory'];
        $bundleSource = $pathInfo['source'];

        return [
            'fileName' => $fileName,
            'path'     => $directory,
            'source'   => $bundleSource,
            'content'  => base64_encode($content),
            'templateKey' => $this->buildTemplateKey($bundleSource, $directory, $fileName),
            '_resolvedName' => $resolvedName,
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $entries
     * @param array<string, string> $bundlesByPath
     */
    private function walkInheritanceChain(string $startKey, array &$entries, array $bundlesByPath): void
    {
        if (!isset($entries[$startKey])) {
            return;
        }

        $current_key = $startKey;
        $visited = [$current_key => true];
        $depth = 0;

        while ($depth < self::MAX_INHERITANCE_DEPTH) {
            $depth++;

            $current = $entries[$current_key];
            $current_source = base64_decode((string) ($current['content'] ?? ''), true);
            if ($current_source === false || $current_source === '') {
                return;
            }

            $extendsInfo = $this->extractExtendsRef($current_source);
            if ($extendsInfo === null) {
                return;
            }

            [$extendsTag, $parentRef] = $extendsInfo;

            $currentResolvedName = (string) ($current['_resolvedName'] ?? '');
            $sourceForFinder = ($extendsTag === 'sw_extends' && $currentResolvedName !== '')
                ? $currentResolvedName
                : null;

            try {
                $parentResolvedName = $this->templateFinder->find($parentRef, true, $sourceForFinder);
            } catch (LoaderError) {
                $entries[$current_key]['extendsTemplateRef'] = $parentRef;
                return;
            }

            if (!str_contains($parentResolvedName, '@') || $parentResolvedName === $currentResolvedName) {
                $entries[$current_key]['extendsTemplateRef'] = $parentRef;
                return;
            }

            $parentEntry = $this->loadResolvedTemplate($parentResolvedName, $bundlesByPath);
            if ($parentEntry === null) {
                $entries[$current_key]['extendsTemplateRef'] = $parentRef;
                return;
            }

            $parentKey = $parentEntry['templateKey'];

            $entries[$current_key]['extendsTemplate'] = $parentKey;
            $entries[$current_key]['extendsTemplateRef'] = $parentRef;

            if (isset($visited[$parentKey])) {
                return;
            }
            $visited[$parentKey] = true;

            if (!isset($entries[$parentKey])) {
                $parentEntry['role'] = 'ancestor';
                $parentEntry['extendsTemplate'] = null;
                $parentEntry['extendsTemplateRef'] = null;
                $entries[$parentKey] = $parentEntry;
            }

            $current_key = $parentKey;
        }
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    private function extractExtendsRef(string $source): ?array
    {
        if (!preg_match(self::EXTENDS_TAG_REGEX, $source, $m)) {
            return null;
        }
        return [strtolower($m[1]), $m[2]];
    }

    private function buildTemplateKey(string $source, string $path, string $fileName): string
    {
        return $source . '|' . $path . '|' . $fileName;
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
