<?php declare(strict_types=1);

namespace Reqser\Plugin\Service;

use Shopware\Core\Framework\Adapter\Twig\TemplateFinder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Finder\Finder;
use Symfony\Component\HttpKernel\KernelInterface;
use Twig\Error\LoaderError;
use Twig\Loader\FilesystemLoader;

/**
 * Reads every active Twig template registered on the Twig loader plus the
 * sw_extends parent chain for each. Never writes or deletes shop files.
 */
class ReqserCmsTwigFileService
{
    private const MAX_INHERITANCE_DEPTH = 10;

    private const MAX_UNRESOLVED_REF_WARNINGS = 50;

    /**
     * Default discovery cap. A Twig loader root is bounded by construction,
     * but the response is uploaded file by file on the receiving side, so an
     * unusual installation must degrade into a truncation warning rather
     * than an unbounded payload. Callers that saw `twig_files_truncated`
     * can raise this via the `maxFiles` query parameter, up to
     * {@see self::ABSOLUTE_MAX_TEMPLATE_FILES}.
     */
    public const DEFAULT_MAX_TEMPLATE_FILES = 2000;

    /**
     * Hard ceiling for `maxFiles`. Prevents a single request from asking
     * the shop for an unbounded walk.
     */
    public const ABSOLUTE_MAX_TEMPLATE_FILES = 10000;

    public const SCOPE_STOREFRONT = 'storefront';

    public const SCOPE_VIEWS = 'views';

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
     * Return every active .html.twig template plus sw_extends ancestors.
     *
     * Default `$scope` is storefront-only. `views` walks each bundle's
     * whole Resources/views tree (plugin-private sw_include targets,
     * documents, mail). Administration Vue under Resources/app is never
     * a Twig loader path.
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
     *
     * @param int $max_files
     * @param string $scope
     * @return array
     */
    public function getAllActiveTwigFiles(
        int $max_files = self::DEFAULT_MAX_TEMPLATE_FILES,
        string $scope = self::SCOPE_STOREFRONT
    ): array {
        $max_files = $this->resolveMaxFiles($max_files);
        $scope = $this->resolveScope($scope);
        $warnings = [];
        $result = [];

        try {
            // In-memory only. TemplateFinder::reset() drops the cached
            // namespace hierarchy; it does not touch files. The next find()
            // may rebind Twig's compile cache under var/cache — Shopware's
            // own cache, never vendor/shopware source.
            $this->templateFinder->reset();

            $bundlesByPath = $this->buildBundlePathMap();
            $refMap = $this->discoverAllTemplateRefs($warnings, $max_files, $scope);
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
     * Discover template refs from paths registered on
     * twig.loader.native_filesystem (TwigLoaderConfigCompilerPass).
     *
     * Emits @Storefront/storefront/... candidates (for theme-aware override
     * resolution) and @BundleName/... loader-native refs (for everything else:
     * compiled dist-only templates, document and mail templates, and the
     * plugin-private trees a storefront template sw_includes).
     *
     * Each ref is mapped to the absolute physical file path(s) that produced
     * it, so {@see loadTemplateFromDiscoveredFile()} can read the source
     * directly when TemplateFinder fails to resolve the ref.
     *
     * @param list<string> $warnings
     * @param int $max_files
     * @param string $scope
     * @return array<string, list<string>>
     */
    private function discoverAllTemplateRefs(array &$warnings, int $max_files, string $scope): array
    {
        $ref_to_paths = [];
        $seen_files = [];
        $truncated = false;

        foreach ($this->loader->getNamespaces() as $namespace) {
            foreach ($this->loader->getPaths($namespace) as $loader_path) {
                foreach ($this->collectTemplateRefsFromLoaderPath($namespace, $this->canonicalizeLoaderRoot($loader_path), $scope) as $absolute_path => $candidate_refs) {
                    $file_key = realpath($absolute_path);
                    if ($file_key === false) {
                        $file_key = $absolute_path;
                    } else {
                        $file_key = $this->normalizePath($file_key);
                    }

                    if (!isset($seen_files[$file_key])) {
                        if (count($seen_files) >= $max_files) {
                            $truncated = true;
                            continue;
                        }
                        $seen_files[$file_key] = true;
                    }

                    foreach ($candidate_refs as $ref) {
                        if ($ref !== '') {
                            $ref_to_paths[$ref][$file_key] = true;
                        }
                    }
                }
            }
        }

        if ($truncated) {
            $warnings[] = 'twig_files_truncated: discovery stopped at ' . $max_files . ' files';
        }

        $result = [];
        foreach ($ref_to_paths as $ref => $path_set) {
            $result[$ref] = array_keys($path_set);
        }

        return $result;
    }

    /**
     * Clamp a requested discovery cap to [1, ABSOLUTE_MAX], defaulting to
     * DEFAULT_MAX when the value is missing or not a positive integer.
     *
     * @param mixed $requested
     * @return int
     */
    public function resolveMaxFiles(mixed $requested): int
    {
        if ($requested === null || $requested === '') {
            return self::DEFAULT_MAX_TEMPLATE_FILES;
        }

        if (!is_numeric($requested)) {
            return self::DEFAULT_MAX_TEMPLATE_FILES;
        }

        $max_files = (int) $requested;
        if ($max_files < 1) {
            return self::DEFAULT_MAX_TEMPLATE_FILES;
        }

        return min($max_files, self::ABSOLUTE_MAX_TEMPLATE_FILES);
    }

    /**
     * Resolve the discovery scope. Default is storefront; `views`, `full`
     * and `all` widen the walk to each bundle's whole Resources/views tree.
     *
     * @param mixed $requested
     * @return string
     */
    public function resolveScope(mixed $requested): string
    {
        if (!is_string($requested) || $requested === '') {
            return self::SCOPE_STOREFRONT;
        }

        $scope = strtolower(trim($requested));
        if ($scope === self::SCOPE_VIEWS || $scope === 'full' || $scope === 'all') {
            return self::SCOPE_VIEWS;
        }

        return self::SCOPE_STOREFRONT;
    }

    /**
     * @param string $namespace
     * @param string $loader_root
     * @param string $scope
     * @return array<string, list<string>> absolutePath => template refs
     */
    private function collectTemplateRefsFromLoaderPath(string $namespace, string $loader_root, string $scope): array
    {
        $templates = [];
        $loader_root_norm = $this->canonicalizeLoaderRoot($loader_root);

        foreach ($this->resolveScanRoots($loader_root_norm, $scope) as $scan_root) {
            try {
                $finder = new Finder();
                $finder->files()->name('*.html.twig')->in($scan_root);
                // List only. Do not enable followLinks — this walk is read-only.
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
                    $storefront_relative = $this->storefrontRelativeRef(
                        $full_path,
                        $relative_from_scan,
                        $relative_from_loader
                    );

                    if ($storefront_relative !== null) {
                        // Canonical storefront ref, so TemplateFinder returns the
                        // winning theme/plugin override rather than whichever
                        // physical copy the walk reached first. Also covers
                        // storefront-shaped trees that do not live under
                        // Resources/views (the 2.0.32 physical-file fallback).
                        $refs[] = '@Storefront/' . $storefront_relative;
                    } elseif ($namespace !== FilesystemLoader::MAIN_NAMESPACE) {
                        // Everything outside storefront/ is addressable only
                        // through its own bundle namespace: documents/, email/,
                        // and the plugin-private trees a storefront template
                        // pulls in with sw_include.
                        $refs[] = '@' . $namespace . '/' . $relative_from_loader;
                    }
                }

                if ($refs === []) {
                    // Unaddressable: outside storefront/ and with no bundle
                    // namespace to reach it through. Skip rather than let it
                    // consume the file budget.
                    continue;
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
     * Map a TwigLoaderConfigCompilerPass loader root to the scan root(s).
     *
     * Default `storefront` stays inside each bundle's storefront tree.
     * `views` walks the whole Resources/views tree so sw_include targets
     * and document/mail templates are discoverable. The administration is
     * unaffected: its Vue templates sit under Resources/app, which is never
     * registered as a Twig path.
     *
     * @param string $loader_root
     * @param string $scope
     * @return list<string>
     */
    private function resolveScanRoots(string $loader_root, string $scope): array
    {
        if ($loader_root === '' || !is_dir($loader_root)) {
            return [];
        }

        if (preg_match('#/Resources/app/storefront/dist$#', $loader_root) === 1) {
            return [$loader_root];
        }

        if (preg_match('#/Resources/views$#', $loader_root) === 1) {
            return $this->restrictToStorefrontIfRequested($loader_root, $scope);
        }

        if (preg_match('#/Resources$#', $loader_root) === 1) {
            $views = $loader_root . '/views';
            return is_dir($views) ? $this->restrictToStorefrontIfRequested($views, $scope) : [];
        }

        $views = $loader_root . '/views';
        $candidate = is_dir($views) ? $views : $loader_root;

        return $this->restrictToStorefrontIfRequested($candidate, $scope);
    }

    /**
     * Narrow a views-shaped root to its storefront/ child when the caller
     * asked for the storefront-only walk.
     *
     * @param string $candidate_root
     * @param string $scope
     * @return list<string>
     */
    private function restrictToStorefrontIfRequested(string $candidate_root, string $scope): array
    {
        if ($scope !== self::SCOPE_STOREFRONT) {
            return [$candidate_root];
        }

        $storefront = $candidate_root . '/storefront';
        if (is_dir($storefront)) {
            return [$storefront];
        }

        if (str_ends_with($this->normalizePath($candidate_root), '/storefront')) {
            return [$candidate_root];
        }

        return [];
    }

    /**
     * Return the path of a template relative to its bundle's Resources/views
     * root, or null when it does not live under one (compiled dist copies).
     *
     * @return string|null
     */
    private function viewsRelativePath(string $normalized_path): string|null
    {
        $marker = '/Resources/views/';
        $position = strrpos($normalized_path, $marker);

        if ($position === false) {
            return null;
        }

        $relative = substr($normalized_path, $position + strlen($marker));

        return $relative === '' ? null : $relative;
    }

    /**
     * Return the storefront-relative path used to build an @Storefront/ ref,
     * or null when the file is not storefront-shaped.
     *
     * Prefer the Resources/views-relative path when it starts with
     * storefront/. Fall back to the scan-root or loader-root relative path
     * so a storefront-shaped tree that does not sit under Resources/views
     * still gets the canonical @Storefront/ ref — that is the 2.0.32
     * physical-file fallback contract.
     *
     * @return string|null
     */
    private function storefrontRelativeRef(
        string $normalized_path,
        string $relative_from_scan,
        string $relative_from_loader
    ): string|null {
        $views_relative = $this->viewsRelativePath($normalized_path);
        if ($views_relative !== null && str_starts_with($views_relative, 'storefront/')) {
            return $views_relative;
        }

        if (str_starts_with($relative_from_scan, 'storefront/')) {
            return $relative_from_scan;
        }

        if (str_starts_with($relative_from_loader, 'storefront/')) {
            return $relative_from_loader;
        }

        return null;
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

        $content = $this->readFileContents($path);
        if ($content === null) {
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
     * Read a discovered template from disk when TemplateFinder cannot
     * resolve the ref. Never writes or deletes.
     *
     * @param string $path
     * @return string|null
     */
    private function readFileContents(string $path): string|null
    {
        if ($path === '' || !is_file($path) || !is_readable($path)) {
            return null;
        }

        $content = @file_get_contents($path);

        return $content === false ? null : $content;
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
