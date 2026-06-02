<?php declare(strict_types=1);

namespace Reqser\Plugin\Service;

use Shopware\Core\Framework\Adapter\Twig\TemplateFinder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Finder\Finder;
use Symfony\Component\HttpKernel\KernelInterface;
use Twig\Error\LoaderError;
use Twig\Loader\FilesystemLoader;

/**
 * Discovers every active storefront Twig template plus the sw_extends parent chain for each.
 */
class ReqserCmsTwigFileService
{
    private const MAX_INHERITANCE_DEPTH = 10;

    private const MAX_UNRESOLVED_REF_WARNINGS = 50;

    private const EXTENDS_TAG_REGEX = '/\{%-?\s*(sw_extends|extends)\s+[\'"]([^\'"]+)[\'"]/';

    private ContainerInterface $container;
    private FilesystemLoader $loader;
    private TemplateFinder $templateFinder;

    /**
     * @param ContainerInterface $container
     * @param FilesystemLoader $loader twig.loader.native_filesystem — runtime mirror of TwigLoaderConfigCompilerPass paths
     * @param TemplateFinder $templateFinder
     */
    public function __construct(
        ContainerInterface $container,
        FilesystemLoader $loader,
        TemplateFinder $templateFinder
    ) {
        $this->container = $container;
        $this->loader = $loader;
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
            $refMap = $this->discoverAllStorefrontTemplateRefs();
            $refs = array_keys($refMap);

            $entries = [];
            $effective_keys = [];
            $unresolved_warning_count = 0;
            $recovered_note_count = 0;

            foreach ($refs as $ref) {
                $resolved = $this->resolveTemplateRef($ref, $bundlesByPath);

                if ($resolved === null) {
                    // TemplateFinder could not resolve the ref (typically a
                    // structural core template a theme/plugin references via a
                    // path the current Shopware version no longer exposes, e.g.
                    // component/buy-widget/buy-widget.html.twig). Fall back to
                    // the physical file that produced the ref so the template
                    // is still captured for diagnostics.
                    $fallback = $this->loadTemplateFromDiscoveredFile($refMap[$ref] ?? [], $bundlesByPath);

                    if ($fallback === null) {
                        if ($unresolved_warning_count < self::MAX_UNRESOLVED_REF_WARNINGS) {
                            $warnings[] = 'twig_ref_unresolved: ' . $ref;
                            $unresolved_warning_count++;
                        }
                        continue;
                    }

                    $key = $fallback['templateKey'];
                    if (isset($entries[$key])) {
                        continue;
                    }

                    $fallback['role'] = 'effective';
                    $fallback['extendsTemplate'] = null;
                    $fallback['extendsTemplateRef'] = $this->rawExtendsRef((string) $fallback['content']);
                    $entries[$key] = $fallback;
                    // Deliberately NOT added to $effective_keys: the inheritance
                    // walk relies on a TemplateFinder-resolved name, which a file
                    // fallback lacks. The raw extendsTemplateRef above is the
                    // best-effort parent reference we can record.

                    if ($recovered_note_count < self::MAX_UNRESOLVED_REF_WARNINGS) {
                        $warnings[] = 'twig_ref_recovered_from_file: ' . $ref;
                        $recovered_note_count++;
                    }
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

            if ($unresolved_warning_count >= self::MAX_UNRESOLVED_REF_WARNINGS) {
                $warnings[] = 'twig_ref_unresolved_truncated: additional unresolved refs omitted';
            }
            if ($recovered_note_count >= self::MAX_UNRESOLVED_REF_WARNINGS) {
                $warnings[] = 'twig_ref_recovered_truncated: additional recovered refs omitted';
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
     * collapse repeated separators, drop trailing slash.
     */
    private function normalizePath(string $path): string
    {
        $normalized = str_replace('\\', '/', $path);
        $normalized = preg_replace('#/+#', '/', $normalized) ?? $normalized;
        return rtrim($normalized, '/');
    }

    /**
     * Discover storefront template refs from paths registered on
     * twig.loader.native_filesystem (TwigLoaderConfigCompilerPass).
     *
     * Emits both @Storefront/storefront/... candidates (for overrides) and
     * @BundleName/... loader-native refs (for compiled dist-only templates).
     *
     * Each ref is mapped to the absolute physical file path(s) that produced
     * it, so {@see loadTemplateFromDiscoveredFile()} can read the source
     * directly when TemplateFinder fails to resolve the ref.
     *
     * @return array<string, list<string>> ref => absolute file paths
     */
    private function discoverAllStorefrontTemplateRefs(): array
    {
        $ref_to_paths = [];

        foreach ($this->loader->getNamespaces() as $namespace) {
            foreach ($this->loader->getPaths($namespace) as $loader_path) {
                foreach ($this->collectTemplateRefsFromLoaderPath($namespace, $this->canonicalizeLoaderRoot($loader_path)) as $absolute_path => $candidate_refs) {
                    $file_key = realpath($absolute_path);
                    if ($file_key === false) {
                        $file_key = $absolute_path;
                    } else {
                        $file_key = $this->normalizePath($file_key);
                    }

                    foreach ($candidate_refs as $ref) {
                        if ($ref !== '') {
                            $ref_to_paths[$ref][$file_key] = true;
                        }
                    }
                }
            }
        }

        $result = [];
        foreach ($ref_to_paths as $ref => $path_set) {
            $result[$ref] = array_keys($path_set);
        }

        return $result;
    }

    /**
     * @return array<string, list<string>> absolutePath => template refs
     */
    private function collectTemplateRefsFromLoaderPath(string $namespace, string $loader_root): array
    {
        $templates = [];
        $loader_root_norm = $this->canonicalizeLoaderRoot($loader_root);

        foreach ($this->resolveStorefrontScanRoots($loader_root_norm) as $scan_root) {
            try {
                $finder = new Finder();
                $finder->files()->name('*.html.twig')->in($scan_root);
            } catch (\Throwable) {
                continue;
            }

            $scan_root_norm = $this->normalizePath($scan_root);
            $is_dist_root = str_ends_with($loader_root_norm, '/app/storefront/dist');

            foreach ($finder as $file) {
                $full_path = $this->normalizePath($file->getPathname());
                $relative_from_scan = ltrim(substr($full_path, strlen($scan_root_norm)), '/');
                if ($relative_from_scan === '' || str_contains($relative_from_scan, '..')) {
                    continue;
                }

                $relative_from_loader = ltrim(substr($full_path, strlen($loader_root_norm)), '/');
                if ($relative_from_loader === '' || str_contains($relative_from_loader, '..')) {
                    continue;
                }

                $refs = [];

                if ($is_dist_root && $namespace !== FilesystemLoader::MAIN_NAMESPACE) {
                    $refs[] = '@' . $namespace . '/' . $relative_from_loader;
                    if (str_starts_with($relative_from_loader, 'storefront/')) {
                        $refs[] = '@Storefront/storefront/' . substr($relative_from_loader, strlen('storefront/'));
                    }
                } else {
                    $refs[] = '@Storefront/storefront/' . $relative_from_scan;
                }

                $templates[$full_path] = array_values(array_unique(array_merge($templates[$full_path] ?? [], $refs)));
            }
        }

        return $templates;
    }

    /**
     * Normalize a loader root and resolve symlinks / `..` segments so paths
     * registered as `Resources/views/../app/storefront/dist` match dist scans.
     */
    private function canonicalizeLoaderRoot(string $loader_root): string
    {
        $normalized = $this->normalizePath($loader_root);
        if ($normalized === '' || !is_dir($normalized)) {
            return $normalized;
        }

        $real = realpath($normalized);
        if ($real === false) {
            return $normalized;
        }

        return $this->normalizePath($real);
    }

    /**
     * Map a TwigLoaderConfigCompilerPass loader root to the storefront scan root(s).
     *
     * @return list<string>
     */
    private function resolveStorefrontScanRoots(string $loader_root): array
    {
        if ($loader_root === '' || !is_dir($loader_root)) {
            return [];
        }

        if (preg_match('#/Resources/app/storefront/dist$#', $loader_root) === 1) {
            return [$loader_root];
        }

        if (preg_match('#/Resources/views$#', $loader_root) === 1) {
            $storefront = $loader_root . '/storefront';
            return is_dir($storefront) ? [$storefront] : [];
        }

        if (preg_match('#/Resources$#', $loader_root) === 1) {
            $views_storefront = $loader_root . '/views/storefront';
            return is_dir($views_storefront) ? [$views_storefront] : [];
        }

        $roots = [];
        foreach (['/storefront', '/views/storefront'] as $suffix) {
            $candidate = $loader_root . $suffix;
            if (is_dir($candidate)) {
                $roots[] = $candidate;
            }
        }

        return $roots;
    }

    /**
     * Resolve a namespaced template ref through Shopware's TemplateFinder and
     * return the single active version for this installation.
     *
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
        if (!str_contains($resolvedName, '@')) {
            return null;
        }

        if (!$this->loader->exists($resolvedName)) {
            return null;
        }

        try {
            $source = $this->loader->getSourceContext($resolvedName);
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
     * Build a template entry by reading a physical file directly, bypassing
     * TemplateFinder. Used as a fallback when a discovered ref cannot be
     * resolved through the finder but its source file still exists on disk.
     *
     * @param list<string> $paths absolute file paths that produced the ref
     * @param array<string, string> $bundlesByPath
     * @return ?array
     */
    private function loadTemplateFromDiscoveredFile(array $paths, array $bundlesByPath): ?array
    {
        $path = $this->selectFallbackPath($paths);
        if ($path === null) {
            return null;
        }

        $content = @file_get_contents($path);
        if ($content === false) {
            return null;
        }

        $projectDir = (string) $this->container->getParameter('kernel.project_dir');
        $relativePath = str_replace($projectDir . '/', '', $path);
        $relativePath = str_replace('\\', '/', $relativePath);

        $pathInfo = $this->parseTemplatePath($path, $relativePath, $bundlesByPath);

        $fileName = basename($path);
        $directory = $pathInfo['directory'];
        $bundleSource = $pathInfo['source'];

        return [
            'fileName' => $fileName,
            'path'     => $directory,
            'source'   => $bundleSource,
            'content'  => base64_encode($content),
            'templateKey' => $this->buildTemplateKey($bundleSource, $directory, $fileName),
            '_resolvedName' => '',
        ];
    }

    /**
     * Pick the best readable file for a fallback read: prefer uncompiled
     * `Resources/views/storefront` sources over compiled `dist` copies.
     *
     * @param list<string> $paths
     */
    private function selectFallbackPath(array $paths): ?string
    {
        $candidates = [];
        foreach ($paths as $p) {
            $norm = $this->normalizePath((string) $p);
            if ($norm === '' || !is_file($norm) || !is_readable($norm)) {
                continue;
            }
            $candidates[] = $norm;
        }

        if ($candidates === []) {
            return null;
        }

        usort($candidates, function (string $a, string $b): int {
            $pa = $this->fallbackPathPriority($a);
            $pb = $this->fallbackPathPriority($b);
            if ($pa !== $pb) {
                return $pa <=> $pb;
            }
            return strlen($a) <=> strlen($b);
        });

        return $candidates[0];
    }

    private function fallbackPathPriority(string $path): int
    {
        if (str_contains($path, '/Resources/views/storefront/')) {
            return 0;
        }
        if (str_contains($path, '/Resources/views/')) {
            return 1;
        }
        return 2;
    }

    /**
     * Extract the raw parent reference of a base64-encoded template's first
     * sw_extends / extends directive, or null when it is a root template.
     */
    private function rawExtendsRef(string $base64Content): ?string
    {
        $decoded = base64_decode($base64Content, true);
        if ($decoded === false || $decoded === '') {
            return null;
        }

        $info = $this->extractExtendsRef($decoded);

        return $info === null ? null : $info[1];
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
