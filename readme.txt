=== SPOKO Enhanced WP REST API ===
Contributors: spoko
Tags: rest-api, headless, cms, api, polylang, multilingual, astro, nextjs
Requires at least: 5.0
Tested up to: 6.7
Requires PHP: 8.3
Stable tag: 1.0.8
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Enhances WordPress REST API with additional fields, optimizations, and relative URL support. Perfect for headless WordPress setups.

== Description ==

Plugin enhances WordPress REST API with additional fields, optimizations, and relative URL support. Designed specifically for headless WordPress setups (Headless CMS) where WordPress serves as a backend API, while the frontend is built with modern frameworks like Astro, Next.js, Nuxt, or any other JavaScript framework.

= Why use this plugin? =

* Perfect for headless WordPress architectures
* Optimized for modern frontend frameworks (especially Astro)
* Simplified URL management across environments
* Enhanced data structure for better frontend integration
* Built-in support for multilingual sites (Polylang)

= Key Features =

**Enhanced Post Data**

* `author_data` - Extended author information including:
  * ID, name, nicename
  * Relative URL to author page
  * Avatar URL
  * Author description
* `featured_image_urls` - All registered image sizes with URLs
* `relative_link` - Post URL relative to site root
* `read_time` - Estimated reading time (e.g., "5 min read")
* `categories_data` - Enhanced category information:
  * ID, name, slug
  * Description and post count
  * Parent category ID
  * Relative URL to category archive
* `tags_data` - Enhanced tag information:
  * ID, name, slug
  * Description and post count
  * Relative URL to tag archive

**URL Management**

All URLs in the API response are converted to relative paths:

* Post permalinks
* Author page URLs
* Category archive URLs
* Tag archive URLs
* Featured image URLs
* Translation URLs (when Polylang is active)

This ensures consistent URL format across different environments (development, staging, production).

**Taxonomy Ordering**

* Custom term ordering via `term_order` parameter
* Works for both categories and tags
* Default ascending order for categories
* Example: `/wp-json/wp/v2/categories?orderby=term_order`

**Polylang Integration**

When Polylang is active, adds:

* `translations_data` - Available translations for:
  * Posts (with title, excerpt, status)
  * Categories (with name and slug)
  * Tags (with name and slug)
* `available_languages` - List of site languages
* Automatic language filtering in API requests

**Comments Support (Optional)**

Configurable via admin panel:

* Anonymous Comments - Allow posting comments via REST API without authentication
* Comment Notifications - Automatic email notifications to moderators when new comments are created via REST API

**Headless Mode (Optional)**

Complete headless WordPress functionality:

* Frontend Redirect - Automatically redirects all visitors to your headless frontend application
* Admin Access - Users with edit_posts capability can still access WordPress admin
* API Access - REST API, GraphQL, WP-CLI, and CRON continue to work normally
* Path Preservation - URL paths are preserved during redirect
* 301 Redirects - Permanent redirects for SEO
* No External Plugin Needed - Replaces standalone headless mode plugins

= Perfect for Headless WordPress =

This plugin is specifically designed for headless WordPress setups where:

* WordPress acts as a backend CMS/API
* Frontend is built with modern frameworks (Astro, Next.js, Nuxt, SvelteKit, etc.)
* You need consistent relative URLs across environments
* You want enhanced data structure without additional API calls

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/spoko-enhanced-rest-api` directory, or install the plugin through the WordPress plugins screen directly.
2. Activate the plugin through the 'Plugins' screen in WordPress
3. The plugin works automatically - no configuration needed
4. (Optional) Install Polylang plugin for multilingual support

== Frequently Asked Questions ==

= Do I need to configure anything after activation? =

No, the plugin works automatically after activation. All REST API endpoints are enhanced immediately.

= Does this work with Polylang? =

Yes! The plugin automatically detects Polylang and adds translation data to posts, categories, and tags.

= Will this work with other page builders? =

Yes, the plugin enhances the core WordPress REST API, so it works with any setup including page builders like Elementor, Gutenberg, etc.

= Can I use this with non-headless WordPress? =

Yes, but it's specifically designed for headless setups. Traditional WordPress sites can use it too, but the benefits are optimized for headless architectures.

= What endpoints does this plugin enhance? =

* `/wp-json/wp/v2/posts`
* `/wp-json/wp/v2/pages`
* `/wp-json/wp/v2/categories`
* `/wp-json/wp/v2/tags`
* Custom post types (automatically)

= Does this plugin slow down my site? =

No, the plugin is optimized for performance and only adds minimal processing to API requests. It doesn't affect frontend performance at all.

== Screenshots ==

1. Enhanced post data with author information and relative URLs
2. Category data with custom ordering support
3. Multilingual support with Polylang integration
4. All image sizes available in REST API response

== Changelog ==

= 1.0.8 =
* Added: Headless Mode - Complete headless WordPress functionality
* Added: Frontend redirect with URL path preservation
* Added: Admin panel settings for headless frontend URL
* Feature: Replaces standalone headless mode plugins
* Feature: 301 permanent redirects for SEO
* Feature: Admin/editors can still access WordPress admin
* Feature: REST API, GraphQL, WP-CLI, and CRON continue to work

= 1.0.7 =
* Fixed: REST route registration error for related posts endpoint
* Added: Read time calculation for all posts (read_time field)
* Added: Optional anonymous comments support via REST API
* Added: Optional email notifications for comments created via REST API
* Improved: Plugin architecture and code organization

= 1.0.6 =
* Current stable version
* Enhanced post data with author, categories, tags
* Relative URL support
* Polylang integration
* Custom taxonomy ordering

= 1.0.0 =
* Initial release

== Upgrade Notice ==

= 1.0.8 =
Major new feature: Headless Mode - Replaces standalone headless mode plugins. Redirect visitors to your headless frontend while keeping WordPress admin and API accessible.

= 1.0.7 =
Important bug fix for REST route registration. New features: read time calculation and optional anonymous comments support with email notifications.

= 1.0.6 =
Current stable version with full feature set for headless WordPress setups.

== Usage Examples ==

**Basic Post Request**

`
fetch('/wp-json/wp/v2/posts')
  .then(res => res.json())
  .then(posts => {
    const post = posts[0];
    console.log(post.relative_link);        // "/blog/post-title"
    console.log(post.author_data.url);      // "/author/john-doe"
  });
`

**Categories with Custom Order**

`
fetch('/wp-json/wp/v2/categories?orderby=term_order')
  .then(res => res.json())
  .then(categories => {
    // Returns categories in custom order
  });
`

**Multilingual Support (Polylang)**

`
fetch('/wp-json/wp/v2/posts/123')
  .then(res => res.json())
  .then(post => {
    console.log(post.translations_data); // All translations
    console.log(post.available_languages); // ["en", "pl"]
  });
`

== Support ==

For support, please visit: [https://github.com/spoko-space](https://github.com/spoko-space)

== Credits ==

Developed by [spoko.space](https://spoko.space)