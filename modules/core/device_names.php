<?php
/**
 * Device name resolution from the Evolution-X OTA repository.
 *
 * Resolved names are cached in data/stats_cache/device_names.json so that
 * subsequent lookups are instant.  The cache maps codename -> full name string,
 * or codename -> null when the OTA repository has no entry for that codename.
 *
 * Cache format example:
 *   {
 *     "raphael": "Xiaomi Mi 9T Pro",
 *     "lynx": "Google Pixel 7a",
 *     "unknown_device": null
 *   }
 */

if (!defined('DEVICE_NAMES_CACHE_FILE')) {
    define('DEVICE_NAMES_CACHE_FILE', __DIR__ . '/../../data/stats_cache/device_names.json');
}

/**
 * Load the device-name cache from disk.
 *
 * @return array<string, string|null>
 */
function loadDeviceNamesCache(): array
{
    if (!file_exists(DEVICE_NAMES_CACHE_FILE)) {
        return [];
    }

    $raw = @file_get_contents(DEVICE_NAMES_CACHE_FILE);
    if ($raw === false) {
        return [];
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

/**
 * Persist the device-name cache to disk atomically.
 *
 * @param array<string, string|null> $cache
 */
function saveDeviceNamesCache(array $cache): void
{
    $dir = dirname(DEVICE_NAMES_CACHE_FILE);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }

    @file_put_contents(
        DEVICE_NAMES_CACHE_FILE,
        json_encode($cache, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
        LOCK_EX
    );
}

/**
 * Fetch the human-readable device name for $codename from the Evolution-X OTA
 * GitHub repository.  Tries branches bka, vic, udc in order.
 *
 * Returns a string like "Xiaomi Mi 9T Pro" on success, or null on failure.
 */
function fetchDeviceNameFromOTA(string $codename): ?string
{
    $branches = ['bka', 'vic', 'udc'];
    $context  = stream_context_create([
        'http' => ['timeout' => 5, 'user_agent' => 'PHP-FileBrowser/1.0'],
        'https' => ['timeout' => 5, 'user_agent' => 'PHP-FileBrowser/1.0'],
    ]);

    foreach ($branches as $branch) {
        $url = "https://raw.githubusercontent.com/Evolution-X/OTA/refs/heads/{$branch}/builds/{$codename}.json";
        $raw = @file_get_contents($url, false, $context);

        if ($raw === false) {
            continue;
        }

        $data = json_decode($raw, true);

        if (!is_array($data) || empty($data['response'])) {
            continue;
        }

        $entry  = $data['response'][0];
        $oem    = trim((string)($entry['oem']    ?? ''));
        $device = trim((string)($entry['device'] ?? ''));

        if ($oem !== '' && $device !== '') {
            return "$oem $device";
        }

        if ($device !== '') {
            return $device;
        }
    }

    return null;
}

/**
 * Resolve display names for an array of device codenames.
 *
 * Cache is checked first; uncached codenames are fetched from OTA and then
 * written back to the cache (including null entries so we do not retry).
 *
 * @param  string[] $codenames
 * @return array<string, string|null>  codename => display name, or null if not found
 */
function resolveDeviceNames(array $codenames): array
{
    if (empty($codenames)) {
        return [];
    }

    $cache   = loadDeviceNamesCache();
    $result  = [];
    $updated = false;

    foreach ($codenames as $codename) {
        // Special values that will never appear in the OTA repository.
        if ($codename === '' || $codename === 'root') {
            $result[$codename] = null;
            continue;
        }

        if (array_key_exists($codename, $cache)) {
            $result[$codename] = $cache[$codename];
            continue;
        }

        // Not cached — fetch from OTA and store the result (even if null).
        $name                 = fetchDeviceNameFromOTA($codename);
        $cache[$codename]     = $name;
        $result[$codename]    = $name;
        $updated              = true;
    }

    if ($updated) {
        saveDeviceNamesCache($cache);
    }

    return $result;
}

/**
 * Format a device entry for display.
 *
 * When a resolved name is available: "Xiaomi Mi 9T Pro (raphael)"
 * Otherwise just the codename: "raphael"
 */
function formatDeviceDisplayName(string $codename, ?string $resolvedName): string
{
    if ($resolvedName !== null && $resolvedName !== '') {
        return "$resolvedName ($codename)";
    }

    return $codename;
}
