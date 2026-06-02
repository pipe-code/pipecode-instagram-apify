<?php
/**
 * Plugin Name: Pipecode Instagram Apify
 * Plugin URI:  https://pipe-code.github.io/
 * Description: Syncs the latest Instagram posts via Apify and stores them in a custom table. Includes REST API and admin panel.
 * Version: 1.0.2
 * Author: Pipecode
 * Author URI:  https://pipe-code.github.io/
 * License: GPL v2 or later
 * Text Domain: pipecode-instagram-apify
 */

defined('ABSPATH') || exit;

define('PC_INSTAGRAM_VERSION', '1.0.2');
define('PC_INSTAGRAM_DIR', plugin_dir_path(__FILE__));
define('PC_INSTAGRAM_URL', plugin_dir_url(__FILE__));
define('PC_INSTAGRAM_TABLE', 'instagram_posts');

require_once PC_INSTAGRAM_DIR . 'includes/class-instagram-db.php';
require_once PC_INSTAGRAM_DIR . 'includes/class-instagram-sync.php';
require_once PC_INSTAGRAM_DIR . 'includes/class-instagram-admin.php';
require_once PC_INSTAGRAM_DIR . 'includes/class-instagram-api.php';

// ── Activation ──────────────────────────────────────────────────────────────

register_activation_hook(__FILE__, ['PC_Instagram_DB', 'create_table']);

register_activation_hook(__FILE__, function () {
    if (! wp_next_scheduled('pc_instagram_daily_sync')) {
        $next_6am = strtotime('today 06:00 UTC');
        if ($next_6am <= time()) {
            $next_6am = strtotime('tomorrow 06:00 UTC');
        }
        wp_schedule_event($next_6am, 'daily', 'pc_instagram_daily_sync');
    }
});

// ── Deactivation — wipe everything ──────────────────────────────────────────

register_deactivation_hook(__FILE__, function () {
    // Cancel cron
    $timestamp = wp_next_scheduled('pc_instagram_daily_sync');
    if ($timestamp) {
        wp_unschedule_event($timestamp, 'pc_instagram_daily_sync');
    }

    // Drop custom table
    PC_Instagram_DB::drop_table();

    // Delete all plugin options
    delete_option('pc_instagram_settings');
    delete_option('pc_instagram_last_sync');
    delete_option('pc_instagram_db_version');
});

// ── Cron ────────────────────────────────────────────────────────────────────

add_action('pc_instagram_daily_sync', function () {
    @set_time_limit(300);
    $sync = new PC_Instagram_Sync();
    $sync->run();
});

// ── Boot ────────────────────────────────────────────────────────────────────

add_action('plugins_loaded', function () {
    PC_Instagram_Admin::init();
    PC_Instagram_API::init();
});
