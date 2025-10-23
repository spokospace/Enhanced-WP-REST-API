# SPOKO Enhanced WP REST API

Plugin enhances WordPress REST API with additional fields, optimizations, and relative URL support. Designed specifically for headless WordPress setups (Headless CMS) where WordPress serves as a backend API, while the frontend is built with modern frameworks like Astro, Next.js, Nuxt, or any other JavaScript framework.

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