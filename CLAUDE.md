# SPOKO Enhanced WP REST API

WordPress plugin that extends the REST API for headless CMS architecture. Used to power [polo.blue](https://polo.blue) blog built with Astro framework.

## Purpose

This plugin transforms WordPress into a powerful headless CMS by enhancing the REST API with additional endpoints and data fields. It enables building fast static sites with modern frameworks like Astro, Next.js, or Nuxt.

## Architecture

- **Pattern**: Singleton + Feature Factory
- **PHP Version**: 8.3+
- **WordPress**: 5.0+
- **Namespace**: `Spoko\EnhancedRestAPI`

### Directory Structure

```
src/
├── Core/
│   ├── Plugin.php          # Main orchestrator (singleton)
│   └── Singleton.php       # Base singleton class
├── Features/               # Feature modules (14 features)
│   ├── PostFields.php      # Extended post data (author, images, read_time)
│   ├── TaxonomyFields.php  # Extended taxonomy data
│   ├── MenusEndpoint.php   # Navigation menus REST API
│   ├── RelatedPosts.php    # Related posts endpoint
│   ├── TableOfContents.php # TOC generation from headings
│   ├── GA4PopularPosts.php # Google Analytics popular posts
│   ├── PolylangSupport.php # Multilingual support (PL/EN)
│   ├── HeadlessMode.php    # Redirect frontend to Astro
│   └── ...
├── Services/
│   ├── ErrorLogger.php     # Debug logging
│   ├── TranslationCache.php # Object/Redis cache wrapper
│   └── GA4Client.php       # Google Analytics 4 API client
└── Helpers/
    └── LinkHelper.php      # URL utilities
```

## Key Features

### REST API Endpoints

| Endpoint | Description |
|----------|-------------|
| `GET /wp-json/menus/v1/navbar/{lang}` | Navigation menu (pl/en) |
| `GET /wp-json/wp/v2/posts/{id}/related` | Related posts by tags/categories |
| `GET /wp-json/wp/v2/posts/{id}/toc` | Table of contents |
| `GET /wp-json/wp/v2/posts/popular` | GA4 popular posts |

### Extended REST Fields

Posts and pages include additional fields:
- `author_data` - Full author object with avatar
- `featured_image_urls` - All image sizes
- `read_time` - Estimated reading time
- `translations_data` - Polylang translations
- `categories_data` / `tags_data` - Full taxonomy data
- `relative_link` - Relative URL for frontend routing

### Polylang Integration

The blog supports Polish (PL) and English (EN) languages via Polylang plugin. Menu endpoints and content filtering respect language context.

## Adding New Features

1. Create class in `src/Features/`
2. Implement one or more methods:
   - `register()` - Called on init (global features)
   - `registerRestRoutes()` - REST route registration
   - `registerRestFields()` - REST field registration
   - `registerAdmin()` - Admin UI (metaboxes, columns)
3. Add to `Plugin::initFeatures()` array

### Feature Example

```php
<?php
declare(strict_types=1);

namespace Spoko\EnhancedRestAPI\Features;

class MyFeature
{
    public function registerRestRoutes(): void
    {
        if (!get_option('spoko_rest_myfeature_enabled', true)) {
            return;
        }

        register_rest_route('wp/v2', '/myendpoint', [
            'methods' => 'GET',
            'callback' => [$this, 'handleRequest'],
            'permission_callback' => '__return_true'
        ]);
    }
}
```

## Configuration

All features can be toggled via WordPress admin panel: **SPOKO REST API** menu.

Options are stored with prefix `spoko_rest_*`:
- `spoko_rest_menus_enabled`
- `spoko_rest_menus_navbar_pl` / `spoko_rest_menus_navbar_en`
- `spoko_rest_related_posts_enabled`
- `spoko_rest_toc_enabled`
- `spoko_rest_headless_mode_enabled`
- `spoko_rest_headless_client_url`

## Frontend Integration

The Astro frontend at polo.blue fetches data from these endpoints:

```typescript
// Example: Fetching navigation menu
const response = await fetch(`${WP_API}/menus/v1/navbar/${lang}`);
const menu = await response.json();

// Example: Fetching posts with extended data
const posts = await fetch(`${WP_API}/wp/v2/posts?_embed`);
```

## Caching

- Uses WordPress Object Cache (Redis when available)
- Cache group: `spoko_rest_api`
- Configurable TTL per feature
- Manual cache clear via admin panel

## Development Notes

- All files use `declare(strict_types=1)`
- Error handling with try-catch and graceful fallbacks
- ErrorLogger writes to `debug.log` when `WP_DEBUG` is enabled
- No external composer dependencies
