<?php
/**
 * Multisite Header Search Class
 * 
 * Provides header search form shortcode and network search results functionality
 * with FootEducation.com integration.
 * 
 * @package MultisiteHTMLSitemap
 * @since 1.2.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class MHS_Header_Search {
    
    /**
     * Initialize the class
     */
    public function __construct() {
        add_shortcode('network_search_form', array($this, 'render_search_form'));
        add_shortcode('network_search', array($this, 'render_search_results'));
        
        // Add cache invalidation hooks
        add_action('save_post_page', array($this, 'clear_search_cache'));
        add_action('trashed_post', array($this, 'clear_search_cache'));
        add_action('deleted_post', array($this, 'clear_search_cache'));
        add_action('untrashed_post', array($this, 'clear_search_cache'));
        add_action('transition_post_status', array($this, 'clear_search_cache_on_status_change'), 10, 3);
        
        // Register REST API endpoint for suggestions
        add_action('rest_api_init', array($this, 'register_suggest_endpoint'));
    }
    
    /**
     * Render the header search form with typeahead suggestions
     * 
     * Dev notes:
     * - To change destination: use filter 'mhs_network_search_action' or shortcode attr action=""
     * - Form submits to main site's /network-search/ page by default
     * 
     * @param array $atts Shortcode attributes
     * @return string HTML form output with inline CSS and JS
     */
    public function render_search_form($atts) {
        $atts = shortcode_atts(array(
            'action' => '',
        ), $atts, 'network_search_form');
        
        // Default action URL - main site's network search page
        if (empty($atts['action'])) {
            $action = get_home_url(get_main_site_id(), '/network-search/');
        } else {
            $action = $atts['action'];
        }
        
        // Apply filter for programmatic override
        $action = apply_filters('mhs_network_search_action', $action);
        
        // API endpoint URL
        $api_url = home_url('/wp-json/mhs/v1/suggest');
        
        // Build the form HTML with typeahead structure
        $html = '<form class="mhs-global-search" role="search" action="' . esc_url($action) . '" method="get" data-api="' . esc_url($api_url) . '">';
        $html .= '<label class="screen-reader-text" for="mhs-q">Search all sites</label>';
        $html .= '<input id="mhs-q" name="q" type="search" placeholder="Search all sites…" autocomplete="off" />';
        $html .= '<button type="submit" aria-label="Search">';
        $html .= '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">';
        $html .= '<path d="M21 21L16.514 16.506L21 21ZM19 10.5C19 15.194 15.194 19 10.5 19C5.806 19 2 15.194 2 10.5C2 5.806 5.806 2 10.5 2C15.194 2 19 5.806 19 10.5Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>';
        $html .= '</svg>';
        $html .= '</button>';
        $html .= '<div class="mhs-suggest" role="listbox" aria-label="Suggestions"></div>';
        $html .= '</form>';
        
        // Add inline CSS
        $html .= '<style>
        @import url("https://fonts.googleapis.com/css2?family=Poppins:wght@400;500&display=swap");
        
        .mhs-global-search {
            position: relative;
            width: 100%;
            max-width: 420px;
            display: flex;
            gap: 0;
            align-items: stretch;
            font-family: "Poppins", -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            box-sizing: border-box;
        }
        
        .mhs-global-search input[type="search"] {
            flex: 1;
            padding: 0.75rem;
            border: 1px solid #ccc;
            border-right: none;
            border-radius: 4px 0 0 4px;
            font-family: inherit;
            font-size: 1rem;
            line-height: 1.4;
            box-sizing: border-box;
            min-width: 0;
            height: 48px;
        }
        
        .mhs-global-search input[type="search"]:focus {
            outline: 2px solid #0073aa;
            outline-offset: -2px;
            border-color: #0073aa;
            z-index: 1;
            position: relative;
        }
        
        .mhs-global-search button {
            padding: 0;
            width: 48px;
            height: 48px;
            background: #333;
            color: white;
            border: 1px solid #333;
            border-radius: 0 4px 4px 0;
            cursor: pointer;
            font-family: inherit;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: background-color 0.2s ease, border-color 0.2s ease;
            box-sizing: border-box;
        }
        
        .mhs-global-search button svg {
            width: 16px;
            height: 16px;
            flex-shrink: 0;
        }
        
        .mhs-global-search button:hover {
            background: #999;
            border-color: #999;
        }
        
        .mhs-global-search button:focus {
            outline: 2px solid #0073aa;
            outline-offset: -2px;
            z-index: 1;
            position: relative;
        }
        
        .mhs-suggest {
            position: absolute;
            left: 0;
            right: 0;
            top: 100%;
            background: #fff;
            border: 1px solid #ddd;
            display: none;
            z-index: 9999;
            border-radius: 4px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            font-family: inherit;
        }
        
        .mhs-suggest a {
            display: block;
            padding: 0.5rem 0.75rem;
            text-decoration: none;
            color: #333;
            border-bottom: 1px solid #eee;
            font-family: inherit;
        }
        
        .mhs-suggest a:last-child {
            border-bottom: none;
        }
        
        .mhs-suggest a[aria-selected="true"] {
            background: #eef5ff;
        }
        
        .mhs-suggest a:hover {
            background: #f5f5f5;
        }
        
        .mhs-suggest-title {
            font-weight: 500;
            margin-bottom: 2px;
            font-family: inherit;
        }
        
        .mhs-suggest-site {
            font-size: 0.85em;
            color: #333;
            font-family: inherit;
        }
        
        .screen-reader-text {
            position: absolute !important;
            clip: rect(1px, 1px, 1px, 1px);
            width: 1px !important;
            height: 1px !important;
            overflow: hidden;
        }
        
        /* Mobile responsiveness */
        @media (max-width: 768px) {
            .mhs-global-search {
                max-width: 100%;
                flex-direction: row;
            }
            
            .mhs-global-search input[type="search"] {
                min-width: 0;
                flex: 1;
                border-right: none;
                border-radius: 4px 0 0 4px;
                height: 48px;
            }
            
            .mhs-global-search button {
                flex-shrink: 0;
                width: 48px;
                height: 48px;
                padding: 0;
                border-radius: 0 4px 4px 0;
                display: flex;
                align-items: center;
                justify-content: center;
            }
        }
        
        @media (max-width: 480px) {
            .mhs-global-search input[type="search"] {
                padding: 0.6rem;
                font-size: 0.9rem;
                border-right: none;
                border-radius: 4px 0 0 4px;
                height: 42px;
            }
            
            .mhs-global-search button {
                width: 42px;
                height: 42px;
                padding: 0;
                border-radius: 0 4px 4px 0;
                display: flex;
                align-items: center;
                justify-content: center;
            }
            
            .mhs-global-search button svg {
                width: 14px;
                height: 14px;
            }
        }
        </style>';
        
        // Add inline JavaScript
        $html .= '<script>
        (function() {
            "use strict";
            
            document.addEventListener("DOMContentLoaded", function() {
                const forms = document.querySelectorAll(".mhs-global-search");
                
                forms.forEach(function(form) {
                    const input = form.querySelector("input[type=\"search\"]");
                    const suggestBox = form.querySelector(".mhs-suggest");
                    const apiUrl = form.dataset.api;
                    
                    if (!input || !suggestBox || !apiUrl) return;
                    
                    let debounceTimer;
                    let selectedIndex = -1;
                    let suggestions = [];
                    
                    // Debounced fetch suggestions
                    input.addEventListener("input", function() {
                        const query = this.value.trim();
                        
                        clearTimeout(debounceTimer);
                        
                        if (query.length < 2) {
                            hideSuggestions();
                            return;
                        }
                        
                        debounceTimer = setTimeout(function() {
                            fetchSuggestions(query);
                        }, 150);
                    });
                    
                    // Keyboard navigation
                    input.addEventListener("keydown", function(e) {
                        if (!suggestBox.style.display || suggestBox.style.display === "none") {
                            return;
                        }
                        
                        const links = suggestBox.querySelectorAll("a");
                        
                        switch (e.key) {
                            case "ArrowDown":
                                e.preventDefault();
                                selectedIndex = Math.min(selectedIndex + 1, links.length - 1);
                                updateSelection(links);
                                break;
                                
                            case "ArrowUp":
                                e.preventDefault();
                                selectedIndex = Math.max(selectedIndex - 1, -1);
                                updateSelection(links);
                                break;
                                
                            case "Enter":
                                if (selectedIndex >= 0 && links[selectedIndex]) {
                                    e.preventDefault();
                                    window.location.href = links[selectedIndex].href;
                                }
                                break;
                                
                            case "Escape":
                                e.preventDefault();
                                hideSuggestions();
                                break;
                        }
                    });
                    
                    // Click outside to close
                    document.addEventListener("click", function(e) {
                        if (!form.contains(e.target)) {
                            hideSuggestions();
                        }
                    });
                    
                    function fetchSuggestions(query) {
                        const url = apiUrl + "?q=" + encodeURIComponent(query);
                        
                        fetch(url)
                            .then(function(response) {
                                if (!response.ok) throw new Error("Network error");
                                return response.json();
                            })
                            .then(function(data) {
                                suggestions = data || [];
                                renderSuggestions();
                            })
                            .catch(function(error) {
                                console.warn("Suggestion fetch failed:", error);
                                hideSuggestions();
                            });
                    }
                    
                    function renderSuggestions() {
                        if (suggestions.length === 0) {
                            hideSuggestions();
                            return;
                        }
                        
                        let html = "";
                        suggestions.forEach(function(suggestion) {
                            const title = escapeHtml(suggestion.t || "");
                            const url = suggestion.u || "";
                            const site = escapeHtml(suggestion.s || "");
                            
                            html += "<a href=\"" + escapeHtml(url) + "\" role=\"option\">";
                            html += "<div class=\"mhs-suggest-title\">" + title + "</div>";
                            html += "<div class=\"mhs-suggest-site\">" + site + "</div>";
                            html += "</a>";
                        });
                        
                        suggestBox.innerHTML = html;
                        suggestBox.style.display = "block";
                        selectedIndex = -1;
                    }
                    
                    function updateSelection(links) {
                        links.forEach(function(link, index) {
                            if (index === selectedIndex) {
                                link.setAttribute("aria-selected", "true");
                            } else {
                                link.removeAttribute("aria-selected");
                            }
                        });
                    }
                    
                    function hideSuggestions() {
                        suggestBox.style.display = "none";
                        suggestBox.innerHTML = "";
                        selectedIndex = -1;
                        suggestions = [];
                    }
                    
                    function escapeHtml(text) {
                        const div = document.createElement("div");
                        div.textContent = text;
                        return div.innerHTML;
                    }
                });
            });
        })();
        </script>';
        
        return $html;
    }
    
    /**
     * Render network search results
     * 
     * Dev notes:
     * - To disable FootEducation results per page: [network_search include_fe="0"]
     * - To adjust FootEducation base URL: add_filter('mhs_fe_base_url', fn() => 'https://staging.footeducation.com')
     * 
     * @param array $atts Shortcode attributes
     * @return string HTML search results
     */
    public function render_search_results($atts) {
        $atts = shortcode_atts(array(
            'include_fe' => '1',
        ), $atts, 'network_search');
        
        // Get and sanitize search query
        $query = '';
        if (isset($_GET['q'])) {
            $query = sanitize_text_field(wp_unslash($_GET['q']));
        }
        
        if (empty($query)) {
            return '<div class="mhs-search-results"><p>Please enter a search term.</p></div>';
        }
        
        // Check cache first
        $cache_key = 'mhs_search_' . md5(strtolower($query) . '_' . $atts['include_fe']);
        $cached_results = get_transient($cache_key);
        
        if ($cached_results !== false) {
            return $cached_results;
        }
        
        // Perform search
        $hits = $this->search_multisite_pages($query);
        
        // Include FootEducation results if enabled
        if ($atts['include_fe'] === '1') {
            $fe_hits = $this->fetch_remote_fe_pages($query);
            $hits = array_merge($hits, $fe_hits);
        }
        
        // Remove duplicates by URL and sort results
        $hits = $this->dedupe_and_sort_results($hits);
        
        // Generate HTML output
        $html = $this->render_results_html($query, $hits);
        
        // Cache results for 10 minutes
        set_transient($cache_key, $html, 10 * MINUTE_IN_SECONDS);
        
        return $html;
    }
    
    /**
     * Search pages across all multisite network sites
     * 
     * @param string $query Search query
     * @return array Array of search hits
     */
    private function search_multisite_pages($query) {
        if (!is_multisite()) {
            return array();
        }
        
        $hits = array();
        $sites = get_sites(array(
            'number' => 0,
            'network_id' => get_current_network_id(),
            'public' => 1,
            'archived' => 0,
            'spam' => 0,
            'deleted' => 0
        ));
        
        foreach ($sites as $site) {
            switch_to_blog($site->blog_id);
            
            // Search pages on this site
            $pages = get_posts(array(
                'post_type' => 'page',
                'post_status' => 'publish',
                's' => $query,
                'numberposts' => 20,
                'suppress_filters' => false
            ));
            
            // If no results with get_posts, try database fallback
            if (empty($pages)) {
                global $wpdb;
                $like_query = '%' . $wpdb->esc_like($query) . '%';
                $db_pages = $wpdb->get_results($wpdb->prepare(
                    "SELECT ID, post_title, post_modified FROM {$wpdb->posts} 
                     WHERE post_type = 'page' AND post_status = 'publish' 
                     AND (post_title LIKE %s OR post_content LIKE %s)
                     ORDER BY post_title ASC LIMIT 20",
                    $like_query,
                    $like_query
                ));
                
                if (!empty($db_pages)) {
                    $pages = array();
                    foreach ($db_pages as $db_page) {
                        $post = get_post($db_page->ID);
                        if ($post && $post->post_status === 'publish') {
                            $pages[] = $post;
                        }
                    }
                }
            }
            
            // Process results for this site
            $site_name = get_bloginfo('name');
            $site_url = get_home_url();
            
            foreach ($pages as $page) {
                $score = $this->calculate_relevance_score($page->post_title, $query);
                
                $hits[] = array(
                    'title' => $page->post_title,
                    'url' => get_permalink($page->ID),
                    'site_name' => $site_name,
                    'site_url' => $site_url,
                    'modified' => strtotime($page->post_modified),
                    'score' => $score
                );
            }
            
            restore_current_blog();
        }
        
        return $hits;
    }
    
    /**
     * Fetch pages from FootEducation.com via REST API
     * 
     * @param string $query Search query
     * @param int $limit Maximum number of results
     * @return array Array of search hits
     */
    private function fetch_remote_fe_pages($query, $limit = 20) {
        // Check transient cache first
        $cache_key = 'mhs_fe_' . md5(strtolower($query));
        $cached_results = get_transient($cache_key);
        
        if ($cached_results !== false) {
            return $cached_results;
        }
        
        $hits = array();
        
        // Get configurable base URL
        $base_url = apply_filters('mhs_fe_base_url', 'https://footeducation.com');
        
        // Build API URL
        $api_url = $base_url . '/wp-json/wp/v2/pages';
        $api_url = add_query_arg(array(
            'search' => urlencode($query),
            '_fields' => 'id,link,title,modified',
            'per_page' => $limit
        ), $api_url);
        
        // Make remote request
        $response = wp_remote_get($api_url, array(
            'timeout' => 5,
            'sslverify' => true,
            'user-agent' => 'WordPress/' . get_bloginfo('version') . '; ' . get_bloginfo('url')
        ));
        
        // Handle errors gracefully
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            // Cache empty result briefly to avoid repeated failed requests
            set_transient($cache_key, array(), 1 * MINUTE_IN_SECONDS);
            return array();
        }
        
        $body = wp_remote_retrieve_body($response);
        $pages = json_decode($body, true);
        
        if (!is_array($pages)) {
            set_transient($cache_key, array(), 1 * MINUTE_IN_SECONDS);
            return array();
        }
        
        // Process FootEducation results
        foreach ($pages as $page) {
            if (!isset($page['title']['rendered']) || !isset($page['link'])) {
                continue;
            }
            
            $title = $page['title']['rendered'];
            $score = $this->calculate_relevance_score($title, $query) + 60; // Base score for FE
            
            $hits[] = array(
                'title' => $title,
                'url' => $page['link'],
                'site_name' => 'FootEducation',
                'site_url' => $base_url,
                'modified' => isset($page['modified']) ? strtotime($page['modified']) : time(),
                'score' => $score
            );
        }
        
        // Cache results for 5 minutes
        set_transient($cache_key, $hits, 5 * MINUTE_IN_SECONDS);
        
        return $hits;
    }
    
    /**
     * Calculate relevance score for a page title
     * 
     * @param string $title Page title
     * @param string $query Search query
     * @return int Relevance score
     */
    private function calculate_relevance_score($title, $query) {
        $title_lower = strtolower($title);
        $query_lower = strtolower($query);
        
        $score = 0;
        
        // Exact match gets highest score
        if ($title_lower === $query_lower) {
            $score += 100;
        }
        // Title starts with query
        elseif (strpos($title_lower, $query_lower) === 0) {
            $score += 80;
        }
        // Query appears in title
        elseif (strpos($title_lower, $query_lower) !== false) {
            $score += 60;
        }
        // Partial word matches
        else {
            $query_words = explode(' ', $query_lower);
            foreach ($query_words as $word) {
                if (strpos($title_lower, $word) !== false) {
                    $score += 20;
                }
            }
        }
        
        return $score;
    }
    
    /**
     * Remove duplicate results by URL and sort by relevance
     * 
     * @param array $hits Array of search hits
     * @return array Deduplicated and sorted hits
     */
    private function dedupe_and_sort_results($hits) {
        // Remove duplicates by URL
        $unique_hits = array();
        $seen_urls = array();
        
        foreach ($hits as $hit) {
            if (!in_array($hit['url'], $seen_urls)) {
                $unique_hits[] = $hit;
                $seen_urls[] = $hit['url'];
            }
        }
        
        // Sort by score (desc), then by modified date (desc)
        usort($unique_hits, function($a, $b) {
            if ($a['score'] === $b['score']) {
                return $b['modified'] - $a['modified'];
            }
            return $b['score'] - $a['score'];
        });
        
        return $unique_hits;
    }
    
    /**
     * Render search results HTML
     * 
     * @param string $query Search query
     * @param array $hits Search results
     * @return string HTML output
     */
    private function render_results_html($query, $hits) {
        $html = '<div class="mhs-search-results">';
        
        // Search header
        $html .= '<div class="mhs-search-header">';
        $html .= '<h2>Search Results for: <em>' . esc_html($query) . '</em></h2>';
        $html .= '<p class="mhs-results-count">' . count($hits) . ' result' . (count($hits) !== 1 ? 's' : '') . ' found</p>';
        $html .= '</div>';
        
        if (empty($hits)) {
            $html .= '<p class="mhs-no-results">No pages found matching your search.</p>';
        } else {
            $html .= '<div class="mhs-results-list">';
            
            foreach ($hits as $hit) {
                $html .= '<div class="mhs-result-item">';
                $html .= '<h3 class="mhs-result-title">';
                $html .= '<a href="' . esc_url($hit['url']) . '">' . esc_html($hit['title']) . '</a>';
                $html .= '</h3>';
                $html .= '<div class="mhs-result-meta">';
                $html .= '<span class="mhs-result-site">';
                $html .= '<a href="' . esc_url($hit['site_url']) . '">' . esc_html($hit['site_name']) . '</a>';
                $html .= '</span>';
                if (isset($hit['modified'])) {
                    $html .= ' • <span class="mhs-result-date">' . date('M j, Y', $hit['modified']) . '</span>';
                }
                $html .= '</div>';
                $html .= '</div>';
            }
            
            $html .= '</div>';
        }
        
        // Add basic styling
        $html .= '<style>
        .mhs-search-results {
            max-width: 800px;
            margin: 0;
            text-align: left;
        }
        .mhs-search-header {
            margin-bottom: 2rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid #ddd;
            text-align: left;
        }
        .mhs-search-header h2 {
            margin: 0 0 0.5rem 0;
            font-size: 1.5rem;
            text-align: left;
        }
        .mhs-results-count {
            margin: 0;
            color: #333;
            font-size: 0.9rem;
            text-align: left;
        }
        .mhs-result-item {
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid #eee;
            text-align: left;
        }
        .mhs-result-item:last-child {
            border-bottom: none;
        }
        .mhs-result-title {
            margin: 0 0 0.5rem 0;
            font-size: 1.2rem;
            text-align: left;
        }
        .mhs-result-title a {
            color: #0073aa;
            text-decoration: none;
        }
        .mhs-result-title a:hover {
            text-decoration: underline;
        }
        .mhs-result-meta {
            font-size: 0.9rem;
            color: #333;
            text-align: left;
        }
        .mhs-result-site a {
            color: #333;
            text-decoration: none;
        }
        .mhs-result-site a:hover {
            text-decoration: underline;
        }
        .mhs-no-results {
            text-align: left;
            color: #333;
            font-style: italic;
            margin: 2rem 0;
        }
        .mhs-network-search-form {
            display: flex;
            gap: 0.5rem;
            align-items: center;
        }
        .mhs-network-search-form input[type="search"] {
            flex: 1;
            padding: 0.5rem;
            border: 1px solid #ccc;
            border-radius: 4px;
        }
        .mhs-network-search-form button {
            padding: 0.5rem 0.75rem;
            background: #333333;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 1rem;
            line-height: 1;
        }
        .mhs-network-search-form button:hover {
            background: #555555;
        }
        .screen-reader-text {
            position: absolute !important;
            clip: rect(1px, 1px, 1px, 1px);
            width: 1px !important;
            height: 1px !important;
            overflow: hidden;
        }
        </style>';
        
        $html .= '</div>';
        
        return $html;
    }
    
    /**
     * Clear search cache when pages are modified
     * 
     * @param int $post_id Post ID
     */
    public function clear_search_cache($post_id) {
        // Only clear cache for pages
        if (get_post_type($post_id) !== 'page') {
            return;
        }
        
        // Clear all search result caches by deleting transients with our prefix
        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_mhs_search_%' OR option_name LIKE '_transient_timeout_mhs_search_%'");
        
        // Also clear FootEducation cache
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_mhs_fe_%' OR option_name LIKE '_transient_timeout_mhs_fe_%'");
    }
    
    /**
     * Clear search cache when page status changes
     * 
     * @param string $new_status New post status
     * @param string $old_status Old post status
     * @param WP_Post $post Post object
     */
    public function clear_search_cache_on_status_change($new_status, $old_status, $post) {
        if ($post->post_type !== 'page') {
            return;
        }
        
        // Clear cache if status changed to/from publish
        if ($new_status === 'publish' || $old_status === 'publish') {
            $this->clear_search_cache($post->ID);
        }
    }
    
    /**
     * Register REST API endpoint for suggestions
     * 
     * Dev notes:
     * - To change FootEducation base URL: add_filter('mhs_fe_base_url', fn() => 'https://staging.footeducation.com')
     * - To change suggestion limit: add_filter('mhs_suggest_limit', fn() => 15)
     */
    public function register_suggest_endpoint() {
        register_rest_route('mhs/v1', '/suggest', array(
            'methods' => 'GET',
            'callback' => array($this, 'handle_suggest_request'),
            'permission_callback' => '__return_true',
            'args' => array(
                'q' => array(
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ),
            ),
        ));
    }
    
    /**
     * Handle suggestions REST API request
     * 
     * @param WP_REST_Request $request REST request object
     * @return WP_REST_Response|WP_Error Response object
     */
    public function handle_suggest_request($request) {
        $query = sanitize_text_field(wp_unslash($request['q']));
        
        // Ignore queries less than 2 characters
        if (strlen($query) < 2) {
            return rest_ensure_response(array());
        }
        
        // Check cache first
        $cache_key = 'mhs_suggest_' . md5(strtolower($query));
        $cached_suggestions = get_transient($cache_key);
        
        if ($cached_suggestions !== false) {
            return rest_ensure_response($cached_suggestions);
        }
        
        $suggestions = array();
        
        // Get suggestions from multisite network
        $multisite_suggestions = $this->get_multisite_suggestions($query);
        $suggestions = array_merge($suggestions, $multisite_suggestions);
        
        // Get FootEducation suggestions
        $fe_suggestions = $this->get_fe_suggestions($query);
        $suggestions = array_merge($suggestions, $fe_suggestions);
        
        // Remove duplicates by URL
        $unique_suggestions = array();
        $seen_urls = array();
        
        foreach ($suggestions as $suggestion) {
            if (!in_array($suggestion['u'], $seen_urls)) {
                $unique_suggestions[] = $suggestion;
                $seen_urls[] = $suggestion['u'];
            }
        }
        
        // Apply suggestion limit filter
        $limit = apply_filters('mhs_suggest_limit', 10);
        $unique_suggestions = array_slice($unique_suggestions, 0, $limit);
        
        // Cache for 60 seconds
        set_transient($cache_key, $unique_suggestions, 60);
        
        return rest_ensure_response($unique_suggestions);
    }
    
    /**
     * Get suggestions from multisite network
     * 
     * @param string $query Search query
     * @return array Array of suggestions
     */
    private function get_multisite_suggestions($query) {
        if (!is_multisite()) {
            return array();
        }
        
        $suggestions = array();
        
        // Get sites with filterable args
        $sites_args = apply_filters('mhs_suggest_sites_args', array(
            'number' => 0,
            'archived' => 0,
            'spam' => 0,
            'deleted' => 0,
            'mature' => 0
        ));
        
        $sites = get_sites($sites_args);
        
        foreach ($sites as $site) {
            switch_to_blog($site->blog_id);
            
            // Search for pages using WP_Query for better performance
            $wp_query = new WP_Query(array(
                's' => $query,
                'post_type' => 'page',
                'post_status' => 'publish',
                'fields' => 'ids',
                'posts_per_page' => 3,
                'no_found_rows' => true,
                'orderby' => 'relevance'
            ));
            
            $site_name = get_bloginfo('name');
            
            foreach ($wp_query->posts as $post_id) {
                $post = get_post($post_id);
                if ($post) {
                    $suggestions[] = array(
                        't' => $post->post_title,
                        'u' => get_permalink($post_id),
                        's' => $site_name
                    );
                }
            }
            
            restore_current_blog();
        }
        
        return $suggestions;
    }
    
    /**
     * Get suggestions from FootEducation.com
     * 
     * @param string $query Search query
     * @return array Array of suggestions
     */
    private function get_fe_suggestions($query) {
        $suggestions = array();
        
        // Get configurable base URL
        $base_url = apply_filters('mhs_fe_base_url', 'https://footeducation.com');
        
        // Build API URL
        $api_url = $base_url . '/wp-json/wp/v2/pages';
        $api_url = add_query_arg(array(
            'search' => urlencode($query),
            '_fields' => 'id,link,title',
            'per_page' => 5
        ), $api_url);
        
        // Make remote request
        $response = wp_remote_get($api_url, array(
            'timeout' => 3, // Shorter timeout for suggestions
            'sslverify' => true,
            'user-agent' => 'WordPress/' . get_bloginfo('version') . '; ' . get_bloginfo('url')
        ));
        
        // Handle errors gracefully
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return array();
        }
        
        $body = wp_remote_retrieve_body($response);
        $pages = json_decode($body, true);
        
        if (!is_array($pages)) {
            return array();
        }
        
        // Process FootEducation results
        foreach ($pages as $page) {
            if (isset($page['title']['rendered']) && isset($page['link'])) {
                $suggestions[] = array(
                    't' => $page['title']['rendered'],
                    'u' => $page['link'],
                    's' => 'FootEducation'
                );
            }
        }
        
        return $suggestions;
    }
}

// Initialize the class
new MHS_Header_Search();
