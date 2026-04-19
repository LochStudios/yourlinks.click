<?php
require_once '/var/www/config/brandfetch.php';

// Map known platform keys to their canonical domains
const PLATFORM_DOMAINS = [
    'twitch'    => 'twitch.tv',
    'youtube'   => 'youtube.com',
    'twitter'   => 'x.com',
    'instagram' => 'instagram.com',
    'discord'   => 'discord.com',
    'tiktok'    => 'tiktok.com',
    'facebook'  => 'facebook.com',
    'linkedin'  => 'linkedin.com',
    'spotify'   => 'spotify.com',
    'github'    => 'github.com',
];
function brandfetch_ensure_table(Database $db): void {
    $db->query("CREATE TABLE IF NOT EXISTS brandfetch_cache (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        domain     VARCHAR(255) NOT NULL,
        icon_url   VARCHAR(2048) DEFAULT NULL,
        fetched_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_domain (domain)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}
function brandfetch_domain_from_url(string $url): string {
    // Ensure there's a scheme so parse_url correctly identifies the host
    if (!preg_match('#^https?://#i', $url)) {
        $url = 'https://' . $url;
    }
    $host  = strtolower(parse_url($url, PHP_URL_HOST) ?: '');
    // Strip subdomains — keep only the root domain (e.g. user-shop.fourthwall.com → fourthwall.com)
    $parts = explode('.', $host);
    if (count($parts) > 2) {
        $parts = array_slice($parts, -2);
    }
    return implode('.', $parts);
}
function brandfetch_get_icon(string $platform, string $url, Database $db): ?string {
    global $brandfetch_api_key;
    if (empty($brandfetch_api_key)) {
        return null;
    }
    $domain = PLATFORM_DOMAINS[$platform] ?? brandfetch_domain_from_url($url);
    if (empty($domain)) {
        return null;
    }
    // Check cache
    $cached = $db->select("SELECT icon_url, fetched_at FROM brandfetch_cache WHERE domain = ?", [$domain]);
    if (!empty($cached)) {
        // If we have an icon URL, it's permanent — never call the API again
        if ($cached[0]['icon_url'] !== null) {
            return $cached[0]['icon_url'];
        }
        // No icon was found last time; retry after 30 days in case Brandfetch adds one
        $ageSeconds = time() - strtotime($cached[0]['fetched_at']);
        if ($ageSeconds < 30 * 24 * 3600) {
            return null;
        }
    }
    // Fetch from Brandfetch API using cURL
    $apiUrl = "https://api.brandfetch.io/v2/brands/domain/" . urlencode($domain);
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $apiUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING       => '',
        CURLOPT_MAXREDIRS      => 10,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST  => 'GET',
        CURLOPT_HTTPHEADER     => ["Authorization: Bearer {$brandfetch_api_key}"],
    ]);
    $response   = curl_exec($ch);
    $httpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError  = curl_error($ch);
    curl_close($ch);
    // On quota exceeded or network error, return without caching so we retry next time
    if ($curlError || $httpStatus === 429) {
        return null;
    }
    $iconUrl = null;
    if ($httpStatus === 200 && $response !== false) {
        $data = json_decode($response, true);
        if (!empty($data['logos']) && is_array($data['logos'])) {
            // Build lookup: [type][format] => src, preferring dark theme over light
            $byType = [];
            foreach ($data['logos'] as $logo) {
                $type  = $logo['type']  ?? '';
                $theme = $logo['theme'] ?? '';
                foreach (($logo['formats'] ?? []) as $fmt) {
                    $format = $fmt['format'] ?? '';
                    $src    = $fmt['src']    ?? '';
                    if (empty($type) || empty($format) || empty($src)) continue;
                    // Only overwrite an existing entry if this one is dark-themed
                    if (!isset($byType[$type][$format]) || $theme === 'dark') {
                        $byType[$type][$format] = $src;
                    }
                }
            }
            // Priority: symbol (svg > png > jpeg) → icon (svg > png > jpeg) → logo (svg > png > jpeg)
            $priority = [
                ['symbol', 'svg'],
                ['symbol', 'png'],
                ['symbol', 'jpeg'],
                ['icon',   'svg'],
                ['icon',   'png'],
                ['icon',   'jpeg'],
                ['logo',   'svg'],
                ['logo',   'png'],
                ['logo',   'jpeg'],
            ];
            foreach ($priority as [$type, $fmt]) {
                if (!empty($byType[$type][$fmt])) {
                    $iconUrl = $byType[$type][$fmt];
                    break;
                }
            }
        }
    }
    // For 400/401/404 we cache null so we don't waste future requests on bad domains
    // Upsert into cache
    if (!empty($cached)) {
        $db->execute(
            "UPDATE brandfetch_cache SET icon_url = ?, fetched_at = NOW() WHERE domain = ?",
            [$iconUrl, $domain]
        );
    } else {
        $db->execute(
            "INSERT INTO brandfetch_cache (domain, icon_url) VALUES (?, ?)",
            [$domain, $iconUrl]
        );
    }
    return $iconUrl;
}
