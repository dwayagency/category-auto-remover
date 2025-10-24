# Category Auto Remover

A WordPress plugin that automatically removes specific categories from posts when a trigger category is present.

## Description

Category Auto Remover allows you to set up rules that automatically remove unwanted categories from posts when a specific "trigger" category is assigned. This is particularly useful for content management workflows where certain category combinations should be mutually exclusive.

### Key Features

- **Global Rules**: Set up site-wide rules that apply to all posts
- **Per-Post Rules**: Override global rules with custom rules for individual posts
- **Metabox Integration**: Easy-to-use metabox in the post editor
- **Multiple Post Types**: Support for posts and custom post types
- **Bulk Processing**: Apply rules to existing posts with progress tracking
- **Dedicated Menu**: Separate admin menu for easy access
- **Automatic Cleanup**: Removes invalid rules when categories are deleted
- **Performance Optimized**: Cached category queries and batch processing

## Installation

1. Upload the plugin files to the `/wp-content/plugins/category-auto-remover` directory, or install the plugin through the WordPress plugins screen directly.
2. Activate the plugin through the 'Plugins' screen in WordPress
3. Go to Category Auto Remover in the WordPress admin menu to configure your rules

## Requirements

- WordPress 5.0 or higher
- PHP 7.4 or higher

## Usage

### Setting Up Global Rules

1. Navigate to **Category Auto Remover > Impostazioni**
2. In the "Global Rules" tab, configure your rules:
   - **Trigger Category**: Select the category that will activate the rule
   - **Categories to Remove**: Select which categories should be automatically removed when the trigger is present
3. Click "Add Rule" to create additional rules
4. Save your settings

### Using Per-Post Rules

1. Enable the metabox in **Category Auto Remover > Impostazioni > Preferences**
2. When editing a post, you'll see a "Category Auto Remover" metabox in the sidebar
3. Check "Enable custom rule for this post"
4. Select your trigger category and categories to remove
5. Save the post

### Applying Rules to Existing Posts

1. Navigate to **Category Auto Remover > Applica a Post Esistenti**
2. Review the statistics and rules that will be applied
3. Click "Apply Rules to Existing Posts"
4. Monitor the progress bar
5. Check the final results

**Important Notes:**
- Only posts without custom rules (metabox) will be processed
- The process respects your site's post types settings
- Large sites may take several minutes to complete

## Common Use Cases

### E-commerce Sites
- **Product Categories**: Automatically remove "Sale" category when "New Arrival" is assigned
- **Seasonal Products**: Remove "Winter" category when "Summer" is selected
- **Product Types**: Ensure products can't be both "Digital" and "Physical"

### News/Blog Sites
- **Content Types**: Remove "Opinion" category when "News" is assigned
- **Topics**: Prevent articles from being both "Politics" and "Entertainment"
- **Priority Levels**: Remove "Low Priority" when "Breaking News" is selected

### Portfolio Sites
- **Project Types**: Remove "Personal" category when "Commercial" is assigned
- **Technologies**: Ensure projects aren't tagged with conflicting technologies
- **Status**: Remove "In Progress" when "Completed" is selected

### How It Works

1. When a post is saved, the plugin checks if any trigger categories are assigned
2. If a trigger is found, it automatically removes the specified categories
3. The trigger category always remains assigned to the post
4. Rules are applied in order: global rules first, then per-post rules

## Screenshots

### Main Menu
The dedicated "Category Auto Remover" menu in the WordPress admin sidebar.

### Settings Page
The main settings page with tabs for Global Rules and Preferences.

### Bulk Processing Page
The bulk processing page for applying rules to existing posts with progress tracking.

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

### How does the bulk processing work?

The bulk processing feature applies your global rules to all existing published posts. It processes posts in batches of 10 to avoid server timeouts and shows real-time progress.

### Will bulk processing affect posts with custom rules?

No, posts that have custom rules enabled (via the metabox) will be skipped during bulk processing to preserve their individual settings.

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
- Bulk processing uses batch operations to prevent server timeouts
- AJAX-based progress tracking for smooth user experience
- Minimal impact on site performance

## Changelog

### 1.2.0
- **NEW**: Dedicated admin menu (moved from Settings)
- **NEW**: Bulk processing feature for existing posts
- **NEW**: AJAX-based progress tracking
- **NEW**: Real-time statistics and results
- Added performance optimizations with category caching
- Improved security with better input validation
- Enhanced error handling and edge case management
- Added automatic cleanup when categories are deleted
- Improved UI with better styling and help text
- Added version display and requirements checking
- Enhanced logging for debugging
- Better support for custom post types

## New Features in 1.2.0

### Dedicated Admin Menu
The plugin now has its own menu item in the WordPress admin sidebar, making it easier to access and more prominent. No more hunting through the Settings menu!

### Bulk Processing for Existing Posts
Apply your global rules to all existing posts with a single click:
- **Progress Tracking**: Real-time progress bar shows completion status
- **Batch Processing**: Handles large sites by processing posts in batches of 10
- **Smart Skipping**: Automatically skips posts with custom rules to preserve individual settings
- **Detailed Results**: Shows exactly how many posts were processed, updated, or skipped

### Enhanced User Experience
- **Statistics Dashboard**: See exactly how many posts will be affected before running
- **Rule Preview**: Review all rules that will be applied
- **AJAX Interface**: Smooth, responsive interface without page reloads
- **Better Styling**: Improved visual design with WordPress admin standards

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
