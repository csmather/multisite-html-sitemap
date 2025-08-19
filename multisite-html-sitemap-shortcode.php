<?php
/*
Plugin Name: Multisite HTML Sitemap (Shortcode)
Description: Provides the [multisite_sitemap] shortcode that lists published Pages across all public sites in a multisite network. Also includes [network_search_form] and [network_search] shortcodes with FootEducation.com integration.
Version: 1.3.0
Author: Scott Mather
Requires at least: 6.0
Requires PHP: 7.4
Network: true
*/
// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Load header search functionality
require_once plugin_dir_path(__FILE__) . 'includes/class-mhs-header-search.php';

/**
 * Build a hierarchical tree structure from flat pages array
 * 
 * @param array $pages Array of WP_Post objects
 * @return array Hierarchical tree structure
 */
function mhs_build_page_tree($pages) {
    if (empty($pages)) {
        return array();
    }
    
    $tree = array();
    $children = array();
    
    // Sort pages alphabetically by title
    usort($pages, function($a, $b) {
        return strcmp($a->post_title, $b->post_title);
    });
    
    // Group pages by parent ID
    foreach ($pages as $page) {
        $parent_id = (int) $page->post_parent;
        if ($parent_id === 0) {
            $tree[] = $page;
        } else {
            if (!isset($children[$parent_id])) {
                $children[$parent_id] = array();
            }
            $children[$parent_id][] = $page;
        }
    }
    
    // Recursively attach children to their parents
    $tree = mhs_attach_children($tree, $children);
    
    return $tree;
}

/**
 * Recursively attach children to parent pages
 * 
 * @param array $pages Array of page objects
 * @param array $children Array of children grouped by parent ID
 * @return array Pages with children attached
 */
function mhs_attach_children($pages, $children) {
    foreach ($pages as $page) {
        if (isset($children[$page->ID])) {
            // Sort children alphabetically
            usort($children[$page->ID], function($a, $b) {
                return strcmp($a->post_title, $b->post_title);
            });
            
            $page->children = mhs_attach_children($children[$page->ID], $children);
        } else {
            $page->children = array();
        }
    }
    
    return $pages;
}

/**
 * Render hierarchical page tree as nested HTML lists
 * 
 * @param array $tree Array of page objects with children
 * @param int $parent_id Parent ID (for recursion)
 * @return string HTML string of nested <ul><li> elements
 */
function mhs_render_page_tree($tree, $parent_id = 0) {
    if (empty($tree)) {
        return '';
    }
    
    $html = '<ul class="mhs-page-list">';
    
    foreach ($tree as $page) {
        $page_url = get_permalink($page->ID);
        $page_title = esc_html($page->post_title);
        
        // Add required class for search functionality
        $html .= '<li class="mhs-page-item">';
        $html .= '<a href="' . esc_url($page_url) . '">' . $page_title . '</a>';
        
        // Render children if they exist
        if (!empty($page->children)) {
            $html .= mhs_render_page_tree($page->children, $page->ID);
        }
        
        $html .= '</li>';
    }
    
    $html .= '</ul>';
    
    return $html;
}

/**
 * Main function to render the complete multisite sitemap
 * 
 * @return string Complete HTML sitemap
 */
function mhs_render_multisite_sitemap() {
    // Check if this is a multisite installation
    if (!is_multisite()) {
        return '<div class="mhs-sitemap"><p>This shortcode requires WordPress Multisite.</p></div>';
    }
    
    // Generate cache key based on network and main site
    $main_site_id = get_main_site_id();
    $network_id = get_current_network_id();
    
    // Get all sites with more permissive parameters
    $sites = get_sites(array(
        'number' => 0,  // 0 means no limit - get all sites
        'network_id' => $network_id
    ));
    
    $site_count = count($sites);
    $cache_key = "mhs_sitemap_{$network_id}_{$main_site_id}_{$site_count}";
    
    // Try to get cached version
    $cached_html = get_transient($cache_key);
    if ($cached_html !== false) {
        return $cached_html;
    }
    
    // Start building the sitemap with search functionality
    $html = '<div class="mhs-sitemap">';
    
    // Add search form at the top
    $html .= '<form class="mhs-search" role="search" onsubmit="return false" aria-label="Search all sites">';
    $html .= '<label class="screen-reader-text" for="mhs-q">Search all pages</label>';
    $html .= '<input id="mhs-q" name="q" type="search" inputmode="search" autocomplete="off" placeholder="Search all sites…" aria-describedby="mhs-count" />';
    $html .= '<button type="button" id="mhs-clear" aria-label="Clear search" hidden>&times;</button>';
    $html .= '<div id="mhs-count" class="mhs-count" aria-live="polite"></div>';
    $html .= '</form>';
    
    if (empty($sites)) {
        $html .= '<p>No sites found in network.</p>';
        $html .= '</div>';
        return $html;
    }
    
    $has_content = false;
    
    foreach ($sites as $site) {
        // Filter out non-public, archived, spam, or deleted sites manually
        if ($site->public != 1 || $site->archived == 1 || $site->spam == 1 || $site->deleted == 1) {
            continue;
        }
        
        // Switch to the site
        switch_to_blog($site->blog_id);
        
        // Get site details first
        $site_name = get_bloginfo('name');
        $site_url = get_home_url();
        
        // Show all sites, even those with no pages
        $has_content = true;
        
        // Add data-site attribute for search functionality
        $html .= '<section class="mhs-site" data-site="' . esc_attr($site_name) . '">';
        $html .= '<h2 class="mhs-site-title">';
        $html .= '<a href="' . esc_url($site_url) . '">' . esc_html($site_name) . '</a>';
        $html .= '</h2>';
        
        // Get pages using multiple methods with database fallback
        global $wpdb;
        
        // Try get_posts first
        $pages = get_posts(array(
            'post_type' => 'page',
            'post_status' => 'publish',
            'numberposts' => -1,
            'suppress_filters' => false
        ));
        
        // If no pages found, try get_pages
        if (empty($pages)) {
            $pages = get_pages(array(
                'post_status' => 'publish',
                'number' => 0
            ));
        }
        
        // If still no pages, try WP_Query
        if (empty($pages)) {
            $query = new WP_Query(array(
                'post_type' => 'page',
                'post_status' => 'publish',
                'posts_per_page' => -1,
                'suppress_filters' => false
            ));
            $pages = $query->posts;
        }
        
        // Database fallback if WordPress queries fail
        if (empty($pages)) {
            $db_pages = $wpdb->get_results($wpdb->prepare(
                "SELECT ID, post_title, post_status, post_parent FROM {$wpdb->posts} 
                 WHERE post_type = 'page' AND post_status = 'publish' 
                 ORDER BY post_title ASC"
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
        
        if (!empty($pages)) {
            // Build and render page tree
            $page_tree = mhs_build_page_tree($pages);
            $html .= mhs_render_page_tree($page_tree);
        } else {
            $html .= '<p><em>No published pages found.</em></p>';
        }
        
        $html .= '</section>';
        
        // Restore to main site
        restore_current_blog();
    }
    
    // If no content was found
    if (!$has_content) {
        $html .= '<p>No public sites found in network.</p>';
    }
    
    // Add inline CSS for search functionality
    $html .= '<style>
    .mhs-search {
        margin-bottom: 2rem;
        padding: 1rem;
        background: #f9f9f9;
        border: 1px solid #ddd;
        border-radius: 4px;
        position: relative;
    }
    .mhs-search input[type="search"] {
        width: 100%;
        padding: 0.75rem 3rem 0.75rem 1rem;
        font-size: 1rem;
        border: 1px solid #ccc;
        border-radius: 4px;
        box-sizing: border-box;
    }
    .mhs-search input[type="search"]:focus {
        outline: 2px solid #0073aa;
        outline-offset: -2px;
        border-color: #0073aa;
    }
    #mhs-clear {
        position: absolute;
        right: 1.5rem;
        top: 50%;
        transform: translateY(-50%);
        background: none;
        border: none;
        font-size: 1.5rem;
        cursor: pointer;
        color: #666;
        padding: 0.25rem;
        line-height: 1;
    }
    #mhs-clear:hover {
        color: #000;
    }
    .mhs-count {
        margin-top: 0.5rem;
        font-size: 0.9rem;
        color: #666;
    }
    .screen-reader-text {
        position: absolute !important;
        clip: rect(1px, 1px, 1px, 1px);
        width: 1px !important;
        height: 1px !important;
        overflow: hidden;
    }
    .mhs-page-item[hidden] {
        display: none !important;
    }
    .mhs-site[hidden] {
        display: none !important;
    }
    .mhs-h {
        background: #ffff00;
        font-weight: bold;
    }
    .mhs-sitemap ul {
        list-style: disc;
        margin-left: 2rem;
    }
    .mhs-sitemap ul ul {
        margin-left: 1.5rem;
    }
    .mhs-site {
        margin-bottom: 2rem;
    }
    .mhs-site-title {
        margin-bottom: 1rem;
        font-size: 1.5rem;
    }
    .mhs-site-title a {
        text-decoration: none;
        color: #0073aa;
    }
    .mhs-site-title a:hover {
        text-decoration: underline;
    }
    </style>';
    
    // Add inline JavaScript for search functionality
    $html .= '<script>
    (function() {
        "use strict";
        
        let debounceTimer;
        let originalTitles = new Map();
        
        function initSearch() {
            const container = document.querySelector(".mhs-sitemap");
            if (!container) return;
            
            const searchInput = container.querySelector("#mhs-q");
            const clearBtn = container.querySelector("#mhs-clear");
            const countDiv = container.querySelector("#mhs-count");
            
            if (!searchInput || !clearBtn || !countDiv) return;
            
            // Store original titles for restoration
            container.querySelectorAll(".mhs-page-item a").forEach(link => {
                originalTitles.set(link, link.innerHTML);
            });
            
            // Check for URL parameter
            const urlParams = new URLSearchParams(window.location.search);
            const queryParam = urlParams.get("q");
            if (queryParam) {
                searchInput.value = queryParam;
                performSearch(queryParam);
            } else {
                updateCount();
            }
            
            // Search input handler
            searchInput.addEventListener("input", function() {
                clearTimeout(debounceTimer);
                debounceTimer = setTimeout(() => {
                    performSearch(this.value);
                }, 150);
            });
            
            // Clear button handler
            clearBtn.addEventListener("click", clearSearch);
            
            // Escape key handler
            searchInput.addEventListener("keydown", function(e) {
                if (e.key === "Escape") {
                    clearSearch();
                }
            });
            
            function performSearch(query) {
                const trimmedQuery = query.trim();
                clearBtn.hidden = !trimmedQuery;
                
                if (!trimmedQuery) {
                    showAllItems();
                    updateCount();
                    return;
                }
                
                const searchTerm = trimmedQuery.toLowerCase()
                    .normalize("NFD").replace(/[\u0300-\u036f]/g, ""); // Remove diacritics
                
                let visiblePages = 0;
                let visibleSites = 0;
                
                container.querySelectorAll(".mhs-site").forEach(site => {
                    const siteName = (site.dataset.site || "").toLowerCase()
                        .normalize("NFD").replace(/[\u0300-\u036f]/g, "");
                    let siteHasVisiblePages = false;
                    
                    site.querySelectorAll(".mhs-page-item").forEach(item => {
                        const link = item.querySelector("a");
                        if (!link) return;
                        
                        // Restore original title
                        link.innerHTML = originalTitles.get(link) || link.innerHTML;
                        
                        const pageTitle = link.textContent.toLowerCase()
                            .normalize("NFD").replace(/[\u0300-\u036f]/g, "");
                        
                        const matches = pageTitle.includes(searchTerm) || siteName.includes(searchTerm);
                        
                        if (matches) {
                            item.hidden = false;
                            siteHasVisiblePages = true;
                            visiblePages++;
                            
                            // Highlight first match in page title
                            const titleText = link.textContent;
                            const titleLower = titleText.toLowerCase()
                                .normalize("NFD").replace(/[\u0300-\u036f]/g, "");
                            const matchIndex = titleLower.indexOf(searchTerm);
                            
                            if (matchIndex !== -1) {
                                const beforeMatch = titleText.substring(0, matchIndex);
                                const match = titleText.substring(matchIndex, matchIndex + searchTerm.length);
                                const afterMatch = titleText.substring(matchIndex + searchTerm.length);
                                
                                link.innerHTML = escapeHtml(beforeMatch) + 
                                    \'<mark class="mhs-h">\' + escapeHtml(match) + \'</mark>\' + 
                                    escapeHtml(afterMatch);
                            }
                        } else {
                            item.hidden = true;
                        }
                    });
                    
                    if (siteHasVisiblePages) {
                        site.hidden = false;
                        visibleSites++;
                    } else {
                        site.hidden = true;
                    }
                });
                
                updateCount(visiblePages, visibleSites);
            }
            
            function clearSearch() {
                searchInput.value = "";
                clearBtn.hidden = true;
                showAllItems();
                updateCount();
                searchInput.focus();
            }
            
            function showAllItems() {
                container.querySelectorAll(".mhs-site, .mhs-page-item").forEach(item => {
                    item.hidden = false;
                });
                
                // Restore original titles
                container.querySelectorAll(".mhs-page-item a").forEach(link => {
                    link.innerHTML = originalTitles.get(link) || link.innerHTML;
                });
            }
            
            function updateCount(pages = null, sites = null) {
                if (pages === null) {
                    pages = container.querySelectorAll(".mhs-page-item").length;
                }
                if (sites === null) {
                    sites = container.querySelectorAll(".mhs-site").length;
                }
                
                countDiv.textContent = pages + " result" + (pages !== 1 ? "s" : "") + 
                    " in " + sites + " site" + (sites !== 1 ? "s" : "");
            }
            
            function escapeHtml(text) {
                const div = document.createElement("div");
                div.textContent = text;
                return div.innerHTML;
            }
        }
        
        if (document.readyState === "loading") {
            document.addEventListener("DOMContentLoaded", initSearch);
        } else {
            initSearch();
        }
    })();
    </script>';
    
    $html .= '</div>';
    
    // Cache the result
    $cache_ttl = apply_filters('mhs_sitemap_cache_ttl', 6 * HOUR_IN_SECONDS);
    set_transient($cache_key, $html, $cache_ttl);
    
    return $html;
}

/**
 * Shortcode callback function
 * 
 * @param array $atts Shortcode attributes
 * @return string HTML output
 */
function mhs_multisite_sitemap_shortcode($atts) {
    return mhs_render_multisite_sitemap();
}

/**
 * Register the shortcode
 */
add_shortcode('multisite_sitemap', 'mhs_multisite_sitemap_shortcode');

/**
 * Clear sitemap cache when pages are modified
 * 
 * @param int $post_id Post ID
 */
function mhs_clear_sitemap_cache($post_id) {
    // Only clear cache for pages
    if (get_post_type($post_id) !== 'page') {
        return;
    }
    
    // Only proceed if this is a multisite
    if (!is_multisite()) {
        return;
    }
    
    $current_blog_id = get_current_blog_id();
    $main_site_id = get_main_site_id();
    $network_id = get_current_network_id();
    
    // Switch to main site to delete the transient
    switch_to_blog($main_site_id);
    
    // Get site count for cache key
    $sites = get_sites(array(
        'public' => 1,
        'archived' => 0,
        'spam' => 0,
        'deleted' => 0,
        'number' => 0  // 0 means no limit - get all sites
    ));
    $site_count = count($sites);
    $cache_key = "mhs_sitemap_{$network_id}_{$main_site_id}_{$site_count}";
    
    // Delete the cached sitemap
    delete_transient($cache_key);
    
    // Restore to original blog
    switch_to_blog($current_blog_id);
}

/**
 * Hook into page save/delete events for cache invalidation
 */
add_action('save_post_page', 'mhs_clear_sitemap_cache');
add_action('trashed_post', 'mhs_clear_sitemap_cache');
add_action('deleted_post', 'mhs_clear_sitemap_cache');
add_action('untrashed_post', 'mhs_clear_sitemap_cache');

/**
 * Clear cache when page status changes
 */
function mhs_clear_sitemap_cache_on_status_change($new_status, $old_status, $post) {
    if ($post->post_type !== 'page') {
        return;
    }
    
    // Clear cache if status changed to/from publish
    if ($new_status === 'publish' || $old_status === 'publish') {
        mhs_clear_sitemap_cache($post->ID);
    }
}
add_action('transition_post_status', 'mhs_clear_sitemap_cache_on_status_change', 10, 3);
