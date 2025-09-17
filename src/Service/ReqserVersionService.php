<?php declare(strict_types=1);

namespace Reqser\Plugin\Service;

use Symfony\Component\HttpClient\HttpClient;
use Shopware\Core\Framework\Plugin\PluginEntity;

class ReqserVersionService
{
    private string $debugFile;
    private string $shopwareVersion;
    private string $projectDir;
    private bool $debugMode = false;
    private ?PluginEntity $plugin = null;

    public function __construct(string $shopwareVersion, string $projectDir)
    {
        $this->shopwareVersion = $shopwareVersion;
        $this->projectDir = $projectDir;
        $this->debugFile = $projectDir . '/custom/plugins/ReqserShopwarePlugin/debug_version_service.log';
    }

    /**
     * Get version data from reqser.com API
     */
    public function getVersionData(): ?array
    {
        try {
            $client = HttpClient::create();
            $url = 'https://reqser.com/app/shopware/versioncheck/' . $this->shopwareVersion;
            
            $this->writeLog("Requesting version data from: $url", __LINE__);
            
            $response = $client->request('GET', $url);
            
            if ($response->getStatusCode() === 200) {
                $data = json_decode($response->getContent(), true);
                $this->writeLog("Version data received: " . json_encode($data), __LINE__);
                return $data;
            } else {
                $statusCode = $response->getStatusCode();
                $this->writeLog("API request failed with status: " . $statusCode, __LINE__);
            }
        } catch (\Throwable $e) {
            $this->writeLog("Failed to get version data: " . $e->getMessage(), __LINE__);
        }
        
        return null;
    }



    /**
     * Check if an update is necessary by comparing versions
     */
    public function updateIsNecessary(string $latestVersion, string $currentVersion): bool
    {
        try {
            // Parse version numbers
            $latest = $this->parseVersion($latestVersion);
            $current = $this->parseVersion($currentVersion);
            
            $this->writeLog("Comparing versions: latest={$latestVersion} vs current={$currentVersion}", __LINE__);
            
            // Compare major version
            if ($latest['major'] > $current['major']) {
                $this->writeLog("Major version update available", __LINE__);
                return true;
            }
            
            // If major versions are equal, compare minor version
            if ($latest['major'] === $current['major'] && $latest['minor'] > $current['minor']) {
                $this->writeLog("Minor version update available", __LINE__);
                return true;
            }
            
            // If major and minor are equal, compare patch version
            if ($latest['major'] === $current['major'] && 
                $latest['minor'] === $current['minor'] && 
                $latest['patch'] > $current['patch']) {
                $this->writeLog("Patch version update available", __LINE__);
                return true;
            }
            
            $this->writeLog("No update necessary", __LINE__);
            return false;
        } catch (\Throwable $e) {
            $this->writeLog("Version comparison failed: " . $e->getMessage(), __LINE__);
            // If version comparison fails, assume no update available
            return false;
        }
    }

    /**
     * Get current plugin version from composer.json
     */
    public function getCurrentPluginVersion(): ?string
    {
        try {
            $composerFile = $this->getPluginDir() . '/composer.json';
            $this->writeLog("Looking for composer.json at: $composerFile", __LINE__);
            
            if (file_exists($composerFile)) {
                $composerData = json_decode(file_get_contents($composerFile), true);
                $version = $composerData['version'] ?? false;
                $this->writeLog("Current plugin version from composer.json: $version", __LINE__);
                return $version;
            } else {
                $this->writeLog("composer.json file not found at: $composerFile", __LINE__);
            }
        } catch (\Throwable $e) {
            $this->writeLog("Failed to get current plugin version: " . $e->getMessage(), __LINE__);
        }
        
        $this->writeLog("No version found in composer.json, returning null", __LINE__);
        return null;
    }

    /**
     * Set the plugin instance (used during update process)
     */
    public function setPlugin(PluginEntity $plugin): void
    {
        $this->plugin = $plugin;
        $this->writeLog("Plugin instance set - path: " . $plugin->getPath(), __LINE__);
    }

    /**
     * Get the plugin directory path (uses Shopware standard getPath() when available)
     */
    public function getPluginDir(): string
    {
        if ($this->plugin) {
            $pluginPath = $this->plugin->getPath();
            $this->writeLog("Raw plugin->getPath(): $pluginPath", __LINE__);
            
            // Check if path is relative and make it absolute
            if (strpos($pluginPath, '/') !== 0) {
                $absolutePath = $this->projectDir . '/' . rtrim($pluginPath, '/');
                $this->writeLog("Converted relative to absolute: $absolutePath", __LINE__);
                return $absolutePath;
            }
            
            $this->writeLog("Using absolute plugin path: $pluginPath", __LINE__);
            return rtrim($pluginPath, '/');
        }
        
        // Fallback to constructed path when plugin instance not available
        $fallbackPath = $this->projectDir . '/custom/plugins/ReqserShopwarePlugin';
        $this->writeLog("Using fallback path (no plugin instance): $fallbackPath", __LINE__);
        return $fallbackPath;
    }

    /**
     * Get the plugins directory path (parent of our plugin)
     */
    public function getPluginsDir(): string
    {
        if ($this->plugin) {
            return dirname($this->plugin->getPath());
        }
        
        // Fallback to constructed path when plugin instance not available
        return $this->projectDir . '/custom/plugins';
    }

    /**
     * Parse version string into major.minor.patch components
     */
    private function parseVersion(string $version): array
    {
        // Remove any 'v' prefix and normalize
        $version = ltrim($version, 'v');
        
        // Split by dots and ensure we have at least 3 parts
        $parts = explode('.', $version);
        
        return [
            'major' => (int) ($parts[0] ?? 0),
            'minor' => (int) ($parts[1] ?? 0), 
            'patch' => (int) ($parts[2] ?? 0)
        ];
    }

    /**
     * Write log message with timestamp
     */
    public function writeLog(string $message, $line = null): void
    {
        if (!$this->debugMode) {
            return;
        }
        
        $timestamp = date('Y-m-d H:i:s');
        $lineInfo = $line ? " [Line:$line]" : "";
        file_put_contents($this->debugFile, "[$timestamp]$lineInfo $message\n", FILE_APPEND | LOCK_EX);
    }

    /**
     * Get Shopware version
     */
    public function getShopwareVersion(): string
    {
        return $this->shopwareVersion;
    }


}
