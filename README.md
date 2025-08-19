# Multisite HTML Sitemap Plugin

WordPress Multisite plugin that provides sitemap and network search functionality with FootEducation.com integration.

## Features

### Shortcodes

1. **`[multisite_sitemap]`** - Displays hierarchical sitemap of all published pages across multisite network with client-side search
2. **`[network_search_form]`** - Header search form that submits to network search results page
3. **`[network_search]`** - Network search results page with FootEducation.com integration

## Installation

1. Upload the plugin files to `/wp-content/mu-plugins/` (recommended) or `/wp-content/plugins/`
2. If using `/wp-content/plugins/`, activate the plugin network-wide
3. The plugin will automatically load on all sites in the network

## Usage

### Header Search Form

Add `[network_search_form]` to any subsite header (works in Elementor Shortcode widget):

```
[network_search_form]
```

**Customization:**
- Override action URL: `[network_search_form action="/custom-search/"]`
- Filter hook: `add_filter('mhs_network_search_action', function($url) { return '/my-search/'; });`

### Network Search Results

Create a "Network Search" page on the main site with slug `/network-search/` and add:

```
[network_search]
```

**Options:**
- Disable FootEducation results: `[network_search include_fe="0"]`
- FootEducation base URL filter: `add_filter('mhs_fe_base_url', function() { return 'https://staging.footeducation.com'; });`

### Multisite Sitemap

Add to any page:

```
[multisite_sitemap]
```

## Technical Details

### Caching
- Search results cached for 10 minutes
- FootEducation API results cached for 5 minutes
- Sitemap cached for 6 hours (filterable via `mhs_sitemap_cache_ttl`)
- Cache automatically cleared when pages are modified

### FootEducation Integration
- Uses WordPress REST API: `/wp-json/wp/v2/pages`
- 5-second timeout with graceful error handling
- Results merged and deduplicated with multisite results
- Sorted by relevance score and modification date

### Error Handling
- Graceful fallback if FootEducation is unreachable
- Database fallback for sites with query filtering issues
- Proper sanitization and escaping of all output
- Compatible with PHP 7.4-8.3

## Quick Test Steps

1. **Setup:**
   ```bash
   # Create network search page on main site
   wp post create --post_type=page --post_status=publish --post_title="Network Search" --post_content="[network_search]" --post_name="network-search"
   ```

2. **Test Header Form:**
   - Add `[network_search_form]` to a subsite header (Elementor Shortcode widget)
   - Search should redirect to main site's `/network-search/` page

3. **Test Search Results:**
   - Search for a term that exists on FootEducation.com
   - Verify mixed results from multisite + FootEducation
   - Check that results are properly labeled by site

4. **Test FootEducation Toggle:**
   - Use `[network_search include_fe="0"]` to disable FootEducation results
   - Verify only multisite results appear

## File Structure

```
multisite-html-sitemap-shortcode.php    # Main plugin file
includes/
  └── class-mhs-header-search.php       # Header search functionality
README.md                               # This file
```

## Filters & Hooks

### Available Filters
- `mhs_network_search_action` - Override search form action URL
- `mhs_fe_base_url` - Override FootEducation base URL
- `mhs_sitemap_cache_ttl` - Override sitemap cache duration

### Cache Invalidation
Cache is automatically cleared when:
- Pages are saved, trashed, deleted, or untrashed
- Page status changes to/from publish
- Applies to both sitemap and search caches

## Requirements

- WordPress Multisite installation
- PHP 7.4 or higher
- WordPress 6.0 or higher

## Version History

- **1.3.0** - Added network search functionality with FootEducation integration
- **1.2.0** - Added client-side search to sitemap
- **1.1.0** - Enhanced sitemap with database fallback
- **1.0.0** - Initial multisite sitemap functionality
