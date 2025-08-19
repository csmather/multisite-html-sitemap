<?php
/**
 * Multisite HTML Sitemap Configuration Class
 * 
 * Provides static configuration methods for domain-agnostic functionality.
 * All methods are filterable for customization.
 * 
 * Example usage in mu-plugins or theme functions.php:
 * 
 * // Configure aggregator URL (for remote sites pointing to main aggregator)
 * add_filter('mhs_aggregator_base_url', function() {
 *     return 'https://orthoeducation.com';
 * });
 * 
 * // Add remote search sources (on aggregator site)
 * add_filter('mhs_search_sources', function($sources) {
 *     $sources[] = ['type' => 'wp', 'base' => 'https://footeducation.com', 'post_types' => ['page']];
 *     return $sources;
 * });
 * 
 * // Allow CORS from remote sites
 * add_filter('mhs_allowed_origins', function($origins) {
 *     $origins[] = 'https://footeducation.com';
 *     return array_unique($origins);
 * });
 * 
 * @package MultisiteHTMLSitemap
 * @since 1.4.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class MHS_Config {
    
    /**
     * Get the aggregator base URL (where search results are processed)
     * 
     * @return string Base URL with trailing slash
     */
    public static function aggregator_base_url(): string {
        // Default to main site URL for multisite, or current site for single site
        if (is_multisite()) {
            $url = get_home_url(get_main_site_id());
        } else {
            $url = get_home_url();
        }
        
        return trailingslashit(apply_filters('mhs_aggregator_base_url', $url));
    }
    
    /**
     * Get the search results page path
     * 
     * @return string Path to results page (with leading slash)
     */
    public static function results_path(): string {
        return apply_filters('mhs_results_path', '/network-search/');
    }
    
    /**
     * Get the suggest API endpoint path
     * 
     * @return string Path to suggest endpoint (with leading slash)
     */
    public static function suggest_path(): string {
        return apply_filters('mhs_suggest_path', '/wp-json/mhs/v1/suggest');
    }
    
    /**
     * Get array of sources the AGGREGATOR should search
     * 
     * Each source format:
     * - ['type' => 'multisite', 'post_types' => ['page']] for local multisite
     * - ['type' => 'wp', 'base' => 'https://domain.com', 'post_types' => ['page']] for remote WP
     * 
     * @return array Array of search source configurations
     */
    public static function search_sources(): array {
        $sources = [
            ['type' => 'multisite', 'post_types' => ['page']], // Local multisite sites
        ];
        
        return apply_filters('mhs_search_sources', $sources);
    }
    
    /**
     * Get CORS allowlist for suggest API
     * 
     * Origins allowed to fetch typeahead suggestions cross-domain
     * 
     * @return array Array of allowed origin URLs
     */
    public static function allowed_origins(): array {
        $origins = [
            'https://orthoeducation.com',
            'https://footeducation.com'
        ];
        
        return apply_filters('mhs_allowed_origins', $origins);
    }
    
    /**
     * Get suggestion limit for typeahead
     * 
     * @return int Maximum number of suggestions to return
     */
    public static function suggest_limit(): int {
        return apply_filters('mhs_suggest_limit', 10);
    }
    
    /**
     * Get remote request timeout in seconds
     * 
     * @return int Timeout for remote API requests
     */
    public static function remote_timeout(): int {
        return apply_filters('mhs_remote_timeout', 5);
    }
    
    /**
     * Get suggestion cache TTL in seconds
     * 
     * @return int Cache time-to-live for suggestions
     */
    public static function suggest_cache_ttl(): int {
        return apply_filters('mhs_suggest_cache_ttl', 60);
    }
    
    /**
     * Get search results cache TTL in seconds
     * 
     * @return int Cache time-to-live for search results
     */
    public static function results_cache_ttl(): int {
        return apply_filters('mhs_results_cache_ttl', 10 * MINUTE_IN_SECONDS);
    }
}
