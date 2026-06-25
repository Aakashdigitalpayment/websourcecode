<?php
/**
 * =====================================================
 * SIMPLE FILE-BASED CACHING SYSTEM
 * 
 * Usage:
 * $data = getCachedData('key', 3600, function() {
 *     // fetch data from database
 *     return $db->query("SELECT * FROM table")->fetchAll();
 * });
 * =====================================================
 */

/**
 * Get cached data or generate and cache it
 * @param string $key - Cache key
 * @param int $ttl - Time to live in seconds (default: 1 hour)
 * @param callable $callback - Function to generate data if not cached
 * @return mixed - Cached or generated data
 */
function getCachedData($key, $ttl_or_callback = 3600, $callback = null) {
    // Normalize parameters to support both calling orders
    if (is_callable($ttl_or_callback) && $callback === null) {
        $callback = $ttl_or_callback;
        $ttl = 3600;
    } else {
        $ttl = (int)$ttl_or_callback;
    }

    if (!is_callable($callback)) {
        error_log("getCachedData called without a valid callback for key: $key");
        return null;
    }

    $cacheDir = __DIR__ . '/../cache';
    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0755, true);
    }
    
    $cacheFile = $cacheDir . '/cache_' . md5($key) . '.json';
    $expireTime = time() - $ttl;
    
    // Return cached data if valid
    if (file_exists($cacheFile) && filemtime($cacheFile) > $expireTime) {
        $cached = @file_get_contents($cacheFile);
        if ($cached !== false) {
            $data = @json_decode($cached, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $data;
            }
        }
    }
    
    // Generate new data
    try {
        $data = $callback();
        
        // Cache the data
        $jsonData = json_encode($data);
        if ($jsonData !== false) {
            @file_put_contents($cacheFile, $jsonData, LOCK_EX);
        }
        
        return $data;
    } catch (Exception $e) {
        error_log("Cache generation error for key '$key': " . $e->getMessage());
        return null;
    }
}

/**
 * Clear specific cache
 * @param string $key - Cache key to clear
 */
function clearCache($key) {
    $cacheFile = __DIR__ . '/../cache/cache_' . md5($key) . '.json';
    if (file_exists($cacheFile)) {
        @unlink($cacheFile);
    }
}

/**
 * Clear all cache files
 */
function clearAllCache() {
    $cacheDir = __DIR__ . '/../cache';
    if (is_dir($cacheDir)) {
        $files = glob($cacheDir . '/cache_*.json');
        foreach ($files as $file) {
            @unlink($file);
        }
    }
}

/**
 * Get cache size in bytes
 */
function getCacheSize() {
    $cacheDir = __DIR__ . '/../cache';
    $size = 0;
    if (is_dir($cacheDir)) {
        $files = glob($cacheDir . '/cache_*.json');
        foreach ($files as $file) {
            $size += filesize($file);
        }
    }
    return $size;
}
?>
