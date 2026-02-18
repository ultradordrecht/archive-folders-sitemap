<?php
/**
 * Plugin Name: Archive Folders Sitemap
 * Description: Adds recursive static HTML files from configured folders to the WordPress core sitemap.
 * Version: 1.3.0
 * Author: UltraDordrecht
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'WP_Sitemaps_Provider' ) ) {
    // Core sitemap classes not available.
    return;
}

const AFS_OPTION_KEY = 'archive_folders_list';
const AFS_CACHE_KEY  = 'afs_html_entries_v1';
const AFS_PER_PAGE   = 2000;

/**
 * Activation/deactivation: refresh rewrites.
 */
register_activation_hook( __FILE__, function () {
    flush_rewrite_rules();
} );

register_deactivation_hook( __FILE__, function () {
    flush_rewrite_rules();
} );

/**
 * Normalize configured path.
 * Accepts either "/folder/" or full URL; stores as path only with trailing slash.
 */
function afs_normalize_folder_path( $path ) {
    $path = trim( (string) $path );
    if ( $path === '' ) {
        return '';
    }

    $parsed = wp_parse_url( $path );
    if ( ! empty( $parsed['path'] ) ) {
        $path = $parsed['path'];
    }

    $path = '/' . ltrim( $path, '/' );

    if ( substr( $path, -1 ) !== '/' ) {
        $path .= '/';
    }

    return $path;
}

/**
 * Return configured folders (sanitized + unique).
 */
function afs_get_configured_folders() {
    $folders = get_option( AFS_OPTION_KEY, array() );
    if ( ! is_array( $folders ) ) {
        return array();
    }

    $clean = array();
    foreach ( $folders as $line ) {
        $line = afs_normalize_folder_path( sanitize_text_field( (string) $line ) );
        if ( $line !== '' ) {
            $clean[] = $line;
        }
    }

    return array_values( array_unique( $clean ) );
}

/**
 * Build recursive HTML entry list for sitemap.
 */
function afs_get_html_entries() {
    $cached = get_transient( AFS_CACHE_KEY );
    if ( is_array( $cached ) ) {
        return $cached;
    }

    $folders   = afs_get_configured_folders();
    $root_path = wp_normalize_path( trailingslashit( ABSPATH ) );
    $entries   = array();

    foreach ( $folders as $folder ) {
        $dir_abs = wp_normalize_path( untrailingslashit( ABSPATH . ltrim( $folder, '/' ) ) );

        if ( ! is_dir( $dir_abs ) || ! is_readable( $dir_abs ) ) {
            continue;
        }

        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator(
                    $dir_abs,
                    FilesystemIterator::SKIP_DOTS
                )
            );
        } catch ( Exception $e ) {
            continue;
        }

        foreach ( $iterator as $file ) {
            if ( ! $file instanceof SplFileInfo || ! $file->isFile() ) {
                continue;
            }

            $ext = strtolower( (string) pathinfo( $file->getFilename(), PATHINFO_EXTENSION ) );
            if ( $ext !== 'html' && $ext !== 'htm' ) {
                continue;
            }

            $full = wp_normalize_path( $file->getPathname() );

            // Must be under ABSPATH.
            if ( strpos( $full, $root_path ) !== 0 ) {
                continue;
            }

            $rel = ltrim( substr( $full, strlen( $root_path ) ), '/' );
            $url = home_url( '/' . $rel );

            $entries[ $url ] = array(
                'loc'     => $url,
                'lastmod' => gmdate( 'c', (int) $file->getMTime() ),
            );
        }
    }

    // Stable order.
    ksort( $entries, SORT_NATURAL | SORT_FLAG_CASE );
    $entries = array_values( $entries );

    // Cache for 10 minutes.
    set_transient( AFS_CACHE_KEY, $entries, 10 * MINUTE_IN_SECONDS );

    return $entries;
}

/**
 * Admin menu.
 */
add_action( 'admin_menu', function () {
    add_options_page(
        'Archive Folders Sitemap',
        'Archive Folders',
        'manage_options',
        'archive-folders-sitemap',
        'afs_render_settings_page'
    );
} );

/**
 * Settings page renderer + save.
 */
function afs_render_settings_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    if ( isset( $_POST['afs_save'] ) ) {
        check_admin_referer( 'afs_save_settings' );

        $raw_lines = isset( $_POST['afs_folders'] )
            ? explode( "\n", (string) wp_unslash( $_POST['afs_folders'] ) )
            : array();

        $clean = array();
        foreach ( $raw_lines as $line ) {
            $line = afs_normalize_folder_path( sanitize_text_field( $line ) );
            if ( $line !== '' ) {
                $clean[] = $line;
            }
        }

        $clean = array_values( array_unique( $clean ) );
        update_option( AFS_OPTION_KEY, $clean );
        delete_transient( AFS_CACHE_KEY );

        echo '<div class="notice notice-success"><p>Settings saved.</p></div>';
    }

    $folders = afs_get_configured_folders();
    $text    = implode( "\n", $folders );
    ?>
    <div class="wrap">
        <h1>Archive Folders Sitemap</h1>
        <form method="post">
            <?php wp_nonce_field( 'afs_save_settings' ); ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">
                        <label for="afs_folders">Folder paths (one per line)</label>
                    </th>
                    <td>
                        <textarea id="afs_folders" name="afs_folders" rows="8" cols="70" class="large-text code"><?php echo esc_textarea( $text ); ?></textarea>
                        <p class="description">
                            Examples: <code>/archive_folder1/</code>, <code>/archive_folder2/</code>, <code>/archive_folder3/</code>
                        </p>
                        <p class="description">
                            All <code>.html</code> and <code>.htm</code> files in these folders and subfolders are added to sitemap.
                        </p>
                    </td>
                </tr>
            </table>
            <?php submit_button( 'Save Changes', 'primary', 'afs_save' ); ?>
        </form>
    </div>
    <?php
}

/**
 * Sitemap provider.
 */
class AFS_Sitemap_Provider extends WP_Sitemaps_Provider {

    public function __construct() {
        $this->name        = 'archivefolders';
        $this->object_type = 'archivefolders';
    }

    public function get_object_subtypes() {
        return array( 'html' => 'html' );
    }

    public function get_max_num_pages( $object_subtype = '' ) {
        $total = count( afs_get_html_entries() );
        return $total > 0 ? (int) ceil( $total / AFS_PER_PAGE ) : 0;
    }

    public function get_url_list( $page_num, $object_subtype = '' ) {
        $entries  = afs_get_html_entries();
        $page_num = max( 1, (int) $page_num );
        $offset   = ( $page_num - 1 ) * AFS_PER_PAGE;

        return array_slice( $entries, $offset, AFS_PER_PAGE );
    }
}

/**
 * Register provider with core sitemap registry.
 */
add_action( 'wp_sitemaps_init', function ( $wp_sitemaps ) {
    if ( ! ( $wp_sitemaps instanceof WP_Sitemaps ) ) {
        return;
    }

    $wp_sitemaps->registry->add_provider(
        'archivefolders',
        new AFS_Sitemap_Provider()
    );
}, 20 );