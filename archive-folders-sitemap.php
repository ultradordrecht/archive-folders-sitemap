<?php

/**
 * Plugin Name: Archive Folders Sitemap
 * Description: Adds configurable static archive folders to WordPress sitemap
 * Version: 1.0
 * Author: Your Name
 */

if (! defined('ABSPATH')) {
    exit;
}

// Add admin menu
add_action('admin_menu', function () {
    add_options_page(
        'Archive Folders Sitemap',
        'Archive Folders',
        'manage_options',
        'archive-folders-sitemap',
        'archive_folders_settings_page'
    );
});

// Settings page
function archive_folders_settings_page()
{
    if (! current_user_can('manage_options')) {
        return;
    }

    if (isset($_POST['submit']) && check_admin_referer('archive_folders_nonce')) {
        $folders = array_filter(array_map('sanitize_text_field', explode("\n", $_POST['archive_folders'])));
        update_option('archive_folders_list', $folders);
        echo '<div class="notice notice-success"><p>Settings saved!</p></div>';
    }

    $folders = get_option('archive_folders_list', array());
    $folders_text = implode("\n", $folders);
?>
    <div class="wrap">
        <h1>Archive Folders Sitemap</h1>
        <form method="post">
            <?php wp_nonce_field('archive_folders_nonce'); ?>
            <table class="form-table">
                <tr>
                    <th><label for="archive_folders">Folder Paths (one per line)</label></th>
                    <td>
                        <textarea name="archive_folders" id="archive_folders" rows="5" cols="50"><?php echo esc_textarea($folders_text); ?></textarea>
                        <p class="description">Enter folder paths relative to your site (e.g., /static-website-1/, /static-website-2/)</p>
                    </td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>
    </div>
<?php
}

// Register custom sitemap provider
add_filter('wp_sitemaps_providers', function ($providers) {
    $providers['archive_folders'] = new Archive_Folders_Sitemap_Provider();
    return $providers;
});

class Archive_Folders_Sitemap_Provider extends WP_Sitemaps_Provider
{

    public function __construct()
    {
        $this->name = 'archive_folders';
    }

    public function get_max_num_pages($object_subtype = '')
    {
        return 1;
    }

    public function get_sitemap_entries($page_num = 1, $object_subtype = '')
    {
        $folders = get_option('archive_folders_list', array());
        $entries = array();

        foreach ($folders as $folder) {
            $entries[] = array(
                'loc' => home_url($folder),
                'lastmod' => current_time('c'),
            );
        }

        return $entries;
    }
}
?>