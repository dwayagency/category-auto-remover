# Category Auto Remover

A WordPress plugin that automatically removes specific categories from posts when a trigger category is present.

## Description

Category Auto Remover allows you to set up rules that automatically remove unwanted categories from posts when a specific "trigger" category is assigned. This is particularly useful for content management workflows where certain category combinations should be mutually exclusive.

### Key Features

- **Global Rules**: Set up site-wide rules that apply to all posts
- **Per-Post Rules**: Override global rules with custom rules for individual posts
- **Metabox Integration**: Easy-to-use metabox in the post editor
- **Multiple Post Types**: Support for posts and custom post types
- **Automatic Cleanup**: Removes invalid rules when categories are deleted
- **Performance Optimized**: Cached category queries for better performance

## Installation

1. Upload the plugin files to the `/wp-content/plugins/category-auto-remover` directory, or install the plugin through the WordPress plugins screen directly.
2. Activate the plugin through the 'Plugins' screen in WordPress
3. Go to Settings > Category Auto Remover to configure your rules

## Requirements

- WordPress 5.0 or higher
- PHP 7.4 or higher

## Usage

### Setting Up Global Rules

1. Navigate to **Settings > Category Auto Remover**
2. In the "Global Rules" tab, configure your rules:
   - **Trigger Category**: Select the category that will activate the rule
   - **Categories to Remove**: Select which categories should be automatically removed when the trigger is present
3. Click "Add Rule" to create additional rules
4. Save your settings

### Using Per-Post Rules

1. Enable the metabox in **Settings > Category Auto Remover > Preferences**
2. When editing a post, you'll see a "Category Auto Remover" metabox in the sidebar
3. Check "Enable custom rule for this post"
4. Select your trigger category and categories to remove
5. Save the post

### How It Works

1. When a post is saved, the plugin checks if any trigger categories are assigned
2. If a trigger is found, it automatically removes the specified categories
3. The trigger category always remains assigned to the post
4. Rules are applied in order: global rules first, then per-post rules

## Screenshots

### Settings Page
The main settings page with tabs for Global Rules and Preferences.

### Metabox
The metabox appears in the post editor sidebar when enabled.

## Frequently Asked Questions

### Can I use this plugin with custom post types?

Yes! You can enable the metabox for any public post type in the Preferences tab.

### What happens if I delete a category that's used in a rule?

The plugin automatically cleans up rules that reference deleted categories.

### Can I have multiple rules?

Yes, you can create multiple global rules. Each rule operates independently.

### Do per-post rules override global rules?

Per-post rules are applied in addition to global rules, not instead of them.

### What if I assign a trigger category that's also in the "remove" list?

The plugin automatically prevents this - trigger categories cannot be removed by their own rules.

## Technical Details

### Hooks and Filters

The plugin uses standard WordPress hooks:
- `save_post` - Applies rules when posts are saved
- `delete_category` - Cleans up rules when categories are deleted
- `admin_menu` - Adds settings page
- `add_meta_boxes` - Registers metabox

### Database

The plugin stores data in:
- `wp_options` table for global rules and preferences
- `wp_postmeta` table for per-post rule settings

### Performance

- Category queries are cached to avoid repeated database calls
- Rules are only processed when posts are actually saved
- Minimal impact on site performance

## Changelog

### 1.2.0
- Added performance optimizations with category caching
- Improved security with better input validation
- Enhanced error handling and edge case management
- Added automatic cleanup when categories are deleted
- Improved UI with better styling and help text
- Added version display and requirements checking
- Enhanced logging for debugging
- Better support for custom post types

### 1.1.0
- Initial release with global rules and metabox functionality

## Support

For support, feature requests, or bug reports, please contact [DWAY SRL](https://dway.agency).

## License

This plugin is licensed under the GPL v2 or later.

## Credits

Developed by [DWAY SRL](https://dway.agency)

---

**Note**: This plugin requires WordPress 5.0+ and PHP 7.4+. Make sure your hosting environment meets these requirements before installation.
