<?php declare(strict_types=1);

namespace Reqser\Plugin\Service;

use Symfony\Component\HttpClient\HttpClient;
use Shopware\Core\Framework\Plugin\PluginEntity;

class ReqserVersionService
{
    private string $debugFile;
    private string $shopwareVersion;
    private string $projectDir;
    private ?PluginEntity $plugin = null;

    public function __construct(string $shopwareVersion, string $projectDir)
    {
        $this->shopwareVersion = $shopwareVersion;
        $this->projectDir = $projectDir;
        $this->debugFile = $this->getPluginDir() . '/debug_version_service.log';
    }

    /**
     * Get version data from reqser.com API
     */
    public function getVersionData(): ?array
    {
        try {
            $client = HttpClient::create();
            $url = 'https://reqser.com/app/shopware/versioncheck/' . $this->shopwareVersion;
            
            $this->writeLog("Requesting version data from: $url");
            
            $response = $client->request('GET', $url);
            
            if ($response->getStatusCode() === 200) {
                $data = json_decode($response->getContent(), true);
                $this->writeLog("Version data received: " . json_encode($data));
                return $data;
            } else {
                $this->writeLog("API request failed with status: " . $response->getStatusCode());
            }
        } catch (\Throwable $e) {
            $this->writeLog("Failed to get version data: " . $e->getMessage());
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
            
            $this->writeLog("Comparing versions: latest={$latestVersion} vs current={$currentVersion}");
            
            // Compare major version
            if ($latest['major'] > $current['major']) {
                $this->writeLog("Major version update available");
                return true;
            }
            
            // If major versions are equal, compare minor version
            if ($latest['major'] === $current['major'] && $latest['minor'] > $current['minor']) {
                $this->writeLog("Minor version update available");
                return true;
            }
            
            // If major and minor are equal, compare patch version
            if ($latest['major'] === $current['major'] && 
                $latest['minor'] === $current['minor'] && 
                $latest['patch'] > $current['patch']) {
                $this->writeLog("Patch version update available");
                return true;
            }
            
            $this->writeLog("No update necessary");
            return false;
        } catch (\Throwable $e) {
            $this->writeLog("Version comparison failed: " . $e->getMessage());
            // If version comparison fails, assume no update available
            return false;
        }
    }

    /**
     * Get current plugin version from composer.json
     */
    public function getCurrentPluginVersion(): string|null
    {
        try {
            $composerFile = $this->getPluginDir() . '/composer.json';
            if (file_exists($composerFile)) {
                $composerData = json_decode(file_get_contents($composerFile), true);
                $version = $composerData['version'] ?? false;
                $this->writeLog("Current plugin version from composer.json: $version");
                return $version;
            }
        } catch (\Throwable $e) {
            $this->writeLog("Failed to get current plugin version: " . $e->getMessage());
        }
        
        $this->writeLog("Fallback to version 1.0.0");
        return null;
    }

    /**
     * Set the plugin instance (used during update process)
     */
    public function setPlugin(PluginEntity $plugin): void
    {
        $this->plugin = $plugin;
    }

    /**
     * Get the plugin directory path (uses Shopware standard getPath() when available)
     */
    public function getPluginDir(): string
    {
        if ($this->plugin) {
            return $this->plugin->getPath();
        }
        
        // Fallback to constructed path when plugin instance not available
        return $this->projectDir . '/custom/plugins/ReqserShopwarePlugin';
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
    public function writeLog(string $message): void
    {
        $timestamp = date('Y-m-d H:i:s');
        file_put_contents($this->debugFile, "[$timestamp] $message\n", FILE_APPEND | LOCK_EX);
    }

    /**
     * Get Shopware version
     */
    public function getShopwareVersion(): string
    {
        return $this->shopwareVersion;
    }


}
