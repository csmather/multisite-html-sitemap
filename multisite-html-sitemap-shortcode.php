<?php
/*
Plugin Name: Multisite HTML Sitemap (Shortcode)
Description: Provides the [multisite_sitemap] shortcode that lists published Pages across all public sites in a multisite network.
Version: 1.1.0
Author: Scott Mather
Requires at least: 6.0
Requires PHP: 7.4
Network: true
*/
// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

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
        
        $html .= '<li>';
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
    
    // Start building the sitemap
    $html = '<div class="mhs-sitemap">';
    
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
        $current_blog_id = get_current_blog_id();
        
        // Show all sites, even those with no pages
        $has_content = true;
        
        $html .= '<section class="mhs-site">';
        $html .= '<h2 class="mhs-site-title">';
        $html .= '<a href="' . esc_url($site_url) . '">' . esc_html($site_name) . '</a>';
        $html .= '</h2>';
        
        // Comprehensive debugging - check multiple post statuses and methods
        global $wpdb;
        
        // Direct database query to see what's really there
        $db_pages = $wpdb->get_results($wpdb->prepare(
            "SELECT ID, post_title, post_status, post_parent FROM {$wpdb->posts} 
             WHERE post_type = 'page' AND post_status IN ('publish', 'private', 'draft') 
             ORDER BY post_title ASC"
        ));
        
        // Try get_posts with different parameters
        $pages_publish = get_posts(array(
            'post_type' => 'page',
            'post_status' => 'publish',
            'numberposts' => -1,
            'suppress_filters' => false
        ));
        
        // Try get_pages function
        $pages_get_pages = get_pages(array(
            'post_status' => 'publish',
            'number' => 0
        ));
        
        // Try WP_Query
        $query = new WP_Query(array(
            'post_type' => 'page',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'suppress_filters' => false
        ));
        $pages_wp_query = $query->posts;
        
        // Debug output
        $html .= '<div style="background: #ffffcc; padding: 5px; margin: 5px 0; font-size: 12px;">';
        $html .= '<strong>DEBUG for Site ID: ' . $current_blog_id . '</strong><br>';
        $html .= 'Target Site ID: ' . $site->blog_id . ' | Current Blog ID: ' . $current_blog_id . '<br>';
        $html .= 'Database query found: ' . count($db_pages) . ' pages (all statuses)<br>';
        $html .= 'get_posts() found: ' . count($pages_publish) . ' published pages<br>';
        $html .= 'get_pages() found: ' . count($pages_get_pages) . ' published pages<br>';
        $html .= 'WP_Query found: ' . count($pages_wp_query) . ' published pages<br>';
        
        if (!empty($db_pages)) {
            $html .= 'Page statuses in DB: ';
            $statuses = array();
            foreach ($db_pages as $page) {
                $statuses[] = $page->post_status;
            }
            $html .= implode(', ', array_unique($statuses)) . '<br>';
        }
        $html .= '</div>';
        
        // Use the method that found the most pages
        $pages = array();
        if (!empty($pages_publish)) {
            $pages = $pages_publish;
        } elseif (!empty($pages_get_pages)) {
            $pages = $pages_get_pages;
        } elseif (!empty($pages_wp_query)) {
            $pages = $pages_wp_query;
        } elseif (!empty($db_pages)) {
            // If WordPress queries failed but database has published pages, use database results
            $published_db_pages = array_filter($db_pages, function($page) {
                return $page->post_status === 'publish';
            });
            
            if (!empty($published_db_pages)) {
                // Convert database results to WP_Post objects
                $pages = array();
                foreach ($published_db_pages as $db_page) {
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
            $html .= '<p><small><em>Successfully loaded ' . count($pages) . ' pages</em></small></p>';
        } else {
            $html .= '<p><em>No published pages found using any method.</em></p>';
        }
        
        $html .= '</section>';
        
        // Restore to main site
        restore_current_blog();
    }
    
    // If no content was found
    if (!$has_content) {
        $html .= '<p>No public sites found in network.</p>';
    }
    
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
