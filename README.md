# SPOKO Enhanced WP REST API

Plugin enhances WordPress REST API with additional fields, optimizations, relative URL support, and GA4 Popular Posts integration. Designed specifically for headless WordPress setups (Headless CMS) where WordPress serves as a backend API, while the frontend is built with modern frameworks like Astro, Next.js, Nuxt, or any other JavaScript framework.

## Why use this plugin?
- Perfect for headless WordPress architectures
- Optimized for modern frontend frameworks (especially Astro)
- Simplified URL management across environments
- Enhanced data structure for better frontend integration
- Built-in support for multilingual sites (Polylang)

## Key Features

### Enhanced Post Data
- `author_data` - Extended author information including:
 - ID, name, nicename
 - Relative URL to author page
 - Avatar URL
 - Author description
- `featured_image_urls` - All registered image sizes with URLs
- `relative_link` - Post URL relative to site root
- `read_time` - Estimated reading time (e.g., "5 min read")
- `categories_data` - Enhanced category information:
 - ID, name, slug
 - Description and post count
 - Parent category ID
 - Relative URL to category archive
- `tags_data` - Enhanced tag information:
 - ID, name, slug
 - Description and post count
 - Relative URL to tag archive

### URL Management
All URLs in the API response are converted to relative paths:
- Post permalinks
- Author page URLs
- Category archive URLs
- Tag archive URLs
- Featured image URLs
- Translation URLs (when Polylang is active)

This ensures consistent URL format across different environments (development, staging, production).

### Taxonomy Ordering
- Custom term ordering via `term_order` parameter
- Works for both categories and tags
- Default ascending order for categories
- Example: `/wp-json/wp/v2/categories?orderby=term_order`

### Polylang Integration
When Polylang is active, adds:
- `translations_data` - Available translations for:
 - Posts (with title, excerpt, status)
 - Categories (with name and slug)
 - Tags (with name and slug)
- `available_languages` - List of site languages
- Automatic language filtering in API requests

### Comments Support (Optional)
Configurable via admin panel:
- **Anonymous Comments** - Allow posting comments via REST API without authentication
- **Comment Notifications** - Automatic email notifications to moderators when new comments are created via REST API

### Headless Mode (Optional)
Complete headless WordPress functionality:
- **Frontend Redirect** - Automatically redirects all visitors to your headless frontend application
- **Admin Access** - Users with `edit_posts` capability can still access WordPress admin
- **API Access** - REST API, GraphQL, WP-CLI, and CRON continue to work normally
- **Path Preservation** - URL paths are preserved during redirect (e.g., `/blog/post-slug` → `https://frontend.com/blog/post-slug`)
- **301 Redirects** - Permanent redirects for SEO
- **No External Plugin Needed** - Replaces standalone headless mode plugins

### GA4 Popular Posts (Optional)
Fetch popular posts based on real Google Analytics 4 pageview data:
- **Real Analytics Data** - Posts ranked by actual pageviews from GA4
- **Multilingual Support** - Filter by language (Polylang integration)
- **Configurable Period** - 7, 14, 30, or 90 days
- **Smart Caching** - Configurable cache duration (1-24 hours)
- **Service Account Auth** - Secure JWT-based authentication with Google
- **No Google SDK Required** - Lightweight implementation using WordPress HTTP API

## Usage Examples

### Basic Post Request
```javascript
// Fetch posts with enhanced data
fetch('/wp-json/wp/v2/posts')
 .then(res => res.json())
 .then(posts => {
   const post = posts[0];
   
   // Relative URLs
   console.log(post.relative_link);        // "/blog/post-title"
   console.log(post.author_data.url);      // "/author/john-doe"
   
   // Enhanced author data
   console.log(post.author_data);
   // {
   //   id: 1,
   //   name: "John Doe",
   //   nicename: "john-doe",
   //   slug: "john-doe",
   //   avatar: "/path/to/avatar.jpg",
   //   description: "Author bio...",
   //   url: "/author/john-doe"
   // }
   
   // Image sizes
   console.log(post.featured_image_urls);
   // {
   //   thumbnail: "/uploads/image-150x150.jpg",
   //   medium: "/uploads/image-300x200.jpg",
   //   large: "/uploads/image-1024x768.jpg",
   //   full: "/uploads/image.jpg"
   // }
 });
```

### Categories with Custom Order

```javascript
// Custom term order for categories
fetch('/wp-json/wp/v2/categories?orderby=term_order')
  .then(res => res.json())
  .then(categories => {
    // Response example:
    // [
    //   {
    //     "id": 5,
    //     "count": 12,
    //     "description": "Main category",
    //     "link": "/category/main",
    //     "name": "Main Category",
    //     "slug": "main",
    //     "taxonomy": "category",
    //     "parent": 0,
    //     "term_order": 1
    //   },
    //   ...
    // ]
  });

// Same works for tags
fetch('/wp-json/wp/v2/tags?orderby=term_order')
```

### Multilingual Support (Polylang)

```javascript
// Get post with translations
fetch('/wp-json/wp/v2/posts/123')
  .then(res => res.json())
  .then(post => {
    // translations_data example:
    // {
    //   "id": 123,
    //   "translations_data": {
    //     "en": {
    //       "id": 123,
    //       "title": "English Title",
    //       "slug": "english-title",
    //       "link": "/blog/english-title",
    //       "excerpt": "Post excerpt...",
    //       "status": "publish",
    //       "featured_image": 456
    //     },
    //     "pl": {
    //       "id": 124,
    //       "title": "Polski Tytuł",
    //       "slug": "polski-tytul",
    //       "link": "/pl/blog/polski-tytul",
    //       "excerpt": "Fragment wpisu...",
    //       "status": "publish",
    //       "featured_image": 457
    //     }
    //   }
    // }
    
    // Get category with translations
    fetch('/wp-json/wp/v2/categories/45')
      .then(res => res.json())
      .then(category => {
        // translations_data example:
        // {
        //   "id": 45,
        //   "translations_data": {
        //     "en": {
        //       "id": 45,
        //       "name": "News",
        //       "slug": "news",
        //       "link": "/category/news"
        //     },
        //     "pl": {
        //       "id": 46,
        //       "name": "Aktualności",
        //       "slug": "aktualnosci",
        //       "link": "/pl/kategoria/aktualnosci"
        //     }
        //   }
        // }
      });
  });

// Get all available languages
fetch('/wp-json/wp/v2/posts/123')
  .then(res => res.json())
  .then(post => {
    console.log(post.available_languages); // ["en", "pl"]
  });
```

### GA4 Popular Posts

```javascript
// Get 12 most popular posts from last 30 days (default)
fetch('/wp-json/wp/v2/posts/popular')
  .then(res => res.json())
  .then(data => {
    // Response example:
    // {
    //   "posts": [
    //     {
    //       "id": 123,
    //       "title": { "rendered": "Most Popular Article" },
    //       "slug": "most-popular-article",
    //       "link": "/blog/most-popular-article",
    //       "date": "2025-01-15T10:30:00+00:00",
    //       "pageviews": 1542,
    //       "featured_image_urls": { "thumbnail": "...", "medium": "...", "full": "..." },
    //       "excerpt": { "rendered": "Article excerpt..." },
    //       "categories_data": [{ "id": 5, "name": "News", "slug": "news" }],
    //       "lang": "en"
    //     },
    //     ...
    //   ],
    //   "total": 12,
    //   "period": "30d",
    //   "cached": true,
    //   "cached_at": "2025-01-20T08:00:00+00:00"
    // }
  });

// Get popular posts with custom parameters
fetch('/wp-json/wp/v2/posts/popular?limit=6&period=7d&lang=pl')
  .then(res => res.json())
  .then(data => {
    // Returns 6 most popular Polish posts from last 7 days
    console.log(data.posts);
  });

// Available parameters:
// - limit: 1-50 (default: 12)
// - period: 7d, 14d, 30d, 90d (default: 30d)
// - lang: language slug, e.g., "en", "pl" (requires Polylang)
```

## GA4 Configuration

To use the GA4 Popular Posts feature, you need to configure Google Analytics 4 access:

### 1. Create a Service Account
1. Go to [Google Cloud Console → Service Accounts](https://console.cloud.google.com/iam-admin/serviceaccounts)
2. Select your project (or create one)
3. Click "Create Service Account"
4. Give it a name (e.g., "WordPress GA4 Reader")
5. Click "Create and Continue"
6. Skip the optional steps and click "Done"

### 2. Generate JSON Key
1. Click on the newly created service account
2. Go to "Keys" tab
3. Click "Add Key" → "Create new key"
4. Select "JSON" and click "Create"
5. Save the downloaded JSON file

### 3. Grant GA4 Access
1. Go to [Google Analytics](https://analytics.google.com/)
2. Navigate to Admin → Property Access Management
3. Click "+" to add a new user
4. Enter the service account email (from the JSON file: `client_email`)
5. Set role to "Viewer"
6. Click "Add"

### 4. Configure Plugin
1. Go to WordPress Admin → SPOKO REST API
2. Find "GA4 Popular Posts" section
3. Enable the feature
4. Enter your GA4 Property ID (numeric, e.g., `123456789`)
5. Paste the entire JSON credentials content
6. Set cache duration (recommended: 6 hours)
7. Save settings