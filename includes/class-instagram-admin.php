<?php
defined('ABSPATH') || exit;

class PC_Instagram_Admin {

    const MENU_SLUG  = 'pc-instagram';
    const POSTS_SLUG = 'pc-instagram-posts';
    const PER_PAGE   = 20;

    public static function init(): void {
        add_action('admin_menu',            [__CLASS__, 'register_menu']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_assets']);
        add_action('wp_ajax_pc_instagram_manual_sync',   [__CLASS__, 'ajax_manual_sync']);
        add_action('wp_ajax_pc_instagram_save_settings', [__CLASS__, 'ajax_save_settings']);
    }

    public static function register_menu(): void {
        add_menu_page(
            __('Instagram Apify', 'pipecode-instagram-apify'),
            __('Instagram Apify', 'pipecode-instagram-apify'),
            'manage_options',
            self::MENU_SLUG,
            [__CLASS__, 'render_dashboard'],
            'dashicons-instagram',
            30
        );

        // Dashboard submenu (replaces the duplicate top-level entry)
        add_submenu_page(
            self::MENU_SLUG,
            __('Dashboard', 'pipecode-instagram-apify'),
            __('Dashboard', 'pipecode-instagram-apify'),
            'manage_options',
            self::MENU_SLUG,
            [__CLASS__, 'render_dashboard']
        );

        // Posts submenu
        add_submenu_page(
            self::MENU_SLUG,
            __('Posts', 'pipecode-instagram-apify'),
            __('Posts', 'pipecode-instagram-apify'),
            'manage_options',
            self::POSTS_SLUG,
            [__CLASS__, 'render_posts']
        );
    }

    public static function enqueue_assets(string $hook): void {
        $pages = [
            'toplevel_page_' . self::MENU_SLUG,
            'instagram-apify_page_' . self::POSTS_SLUG,
        ];
        if (! in_array($hook, $pages, true)) {
            return;
        }

        wp_enqueue_style(
            'pc-instagram-admin',
            PC_INSTAGRAM_URL . 'assets/css/admin.css',
            [],
            PC_INSTAGRAM_VERSION
        );
        wp_enqueue_script(
            'pc-instagram-admin',
            PC_INSTAGRAM_URL . 'assets/js/admin.js',
            ['jquery'],
            PC_INSTAGRAM_VERSION,
            true
        );
        $options = get_option('pc_instagram_settings', []);
        wp_localize_script('pc-instagram-admin', 'pcInstagram', [
            'ajaxUrl'       => admin_url('admin-ajax.php'),
            'nonce'         => wp_create_nonce('pc_instagram_sync'),
            'settingsNonce' => wp_create_nonce('pc_instagram_settings'),
            'restBase'      => rest_url('pipecode/v1/instagram'),
            'restNonce'     => wp_create_nonce('wp_rest'),
            'endpointValid' => PC_Instagram_Sync::is_valid_apify_url($options['apify_dataset_url'] ?? ''),
        ]);
    }

    // ── AJAX ────────────────────────────────────────────────────────────────

    public static function ajax_manual_sync(): void {
        check_ajax_referer('pc_instagram_sync', 'nonce');

        if (! current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized'], 403);
        }

        $sync    = new PC_Instagram_Sync();
        $results = $sync->run();

        $ok_count  = count(array_filter($results, fn($r) => $r['ok']));
        $err_count = count($results) - $ok_count;

        wp_send_json_success([
            'total'   => count($results),
            'ok'      => $ok_count,
            'errors'  => $err_count,
            'results' => $results,
        ]);
    }

    // ── AJAX: save settings ──────────────────────────────────────────────────

    public static function ajax_save_settings(): void {
        check_ajax_referer('pc_instagram_settings', 'nonce');

        if (! current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized'], 403);
        }

        $endpoint = esc_url_raw(wp_unslash($_POST['apify_dataset_url'] ?? ''));
        $token    = sanitize_text_field(wp_unslash($_POST['apify_token'] ?? ''));

        if (empty($endpoint)) {
            wp_send_json_error(['message' => 'The Apify endpoint cannot be empty.']);
        }

        if (! PC_Instagram_Sync::is_valid_apify_url($endpoint)) {
            wp_send_json_error(['message' => 'Invalid Apify endpoint. The URL must start with https://api.apify.com/']);
        }

        $options = get_option('pc_instagram_settings', []);
        $options['apify_dataset_url'] = $endpoint;
        if (! empty($token)) {
            $options['apify_token'] = $token;
        }
        update_option('pc_instagram_settings', $options);

        wp_send_json_success(['message' => 'Settings saved.']);
    }

    // ── Pages ────────────────────────────────────────────────────────────────

    public static function render_dashboard(): void {
        $last_sync = get_option('pc_instagram_last_sync', null);
        $count     = PC_Instagram_DB::count();
        $next      = wp_next_scheduled('pc_instagram_daily_sync');
        $rest_url  = rest_url('pipecode/v1/instagram');
        $options   = get_option('pc_instagram_settings', []);
        $endpoint  = $options['apify_dataset_url'] ?? '';
        $token     = $options['apify_token']       ?? '';
        $valid     = PC_Instagram_Sync::is_valid_apify_url($endpoint);
        ?>
        <div class="wrap pc-instagram-wrap">
            <h1 class="pc-instagram-title">
                <span class="dashicons dashicons-instagram"></span>
                <?php esc_html_e('Instagram Apify — Dashboard', 'pipecode-instagram-apify'); ?>
            </h1>

            <?php if (! $valid) : ?>
            <div class="pc-notice pc-notice-warning">
                <span class="dashicons dashicons-warning"></span>
                <div>
                    <strong><?php esc_html_e('Apify endpoint not configured.', 'pipecode-instagram-apify'); ?></strong>
                    <?php esc_html_e('Sync (manual and automatic) will not run until you set a valid endpoint below.', 'pipecode-instagram-apify'); ?>
                </div>
            </div>
            <?php endif; ?>

            <div class="pc-stats-row">
                <div class="pc-stat-card">
                    <span class="pc-stat-number"><?php echo esc_html(number_format($count)); ?></span>
                    <span class="pc-stat-label"><?php esc_html_e('Posts stored', 'pipecode-instagram-apify'); ?></span>
                </div>
                <div class="pc-stat-card">
                    <span class="pc-stat-number">
                        <?php echo $last_sync ? esc_html(date('M j, Y H:i', strtotime($last_sync))) : '—'; ?>
                    </span>
                    <span class="pc-stat-label"><?php esc_html_e('Last sync (UTC)', 'pipecode-instagram-apify'); ?></span>
                </div>
                <div class="pc-stat-card">
                    <span class="pc-stat-number">
                        <?php echo $next ? esc_html(date('M j, Y H:i', $next) . ' UTC') : '—'; ?>
                    </span>
                    <span class="pc-stat-label"><?php esc_html_e('Next auto-sync', 'pipecode-instagram-apify'); ?></span>
                </div>
            </div>

            <div class="pc-section-card" id="pc-settings-card">
                <h2><?php esc_html_e('Apify Settings', 'pipecode-instagram-apify'); ?></h2>
                <p class="pc-section-desc">
                    <?php esc_html_e('The endpoint must be a valid Apify URL (https://api.apify.com/…). Leave the token field blank to keep the current token.', 'pipecode-instagram-apify'); ?>
                </p>
                <form id="pc-settings-form" autocomplete="off">
                    <table class="form-table pc-settings-table">
                        <tr>
                            <th scope="row">
                                <label for="pc-apify-endpoint"><?php esc_html_e('Apify Endpoint URL', 'pipecode-instagram-apify'); ?></label>
                            </th>
                            <td>
                                <input type="url"
                                       id="pc-apify-endpoint"
                                       name="apify_dataset_url"
                                       class="regular-text pc-endpoint-input"
                                       value="<?php echo esc_attr($endpoint); ?>"
                                       placeholder="https://api.apify.com/v2/actor-tasks/user~task-name/run-sync-get-dataset-items"
                                       spellcheck="false">
                                <div class="pc-field-hint">
                                    <?php esc_html_e('Must start with', 'pipecode-instagram-apify'); ?>
                                    <code>https://api.apify.com/</code>
                                </div>
                                <div class="pc-endpoint-validation" id="pc-endpoint-validation" aria-live="polite"></div>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="pc-apify-token"><?php esc_html_e('Apify Token', 'pipecode-instagram-apify'); ?></label>
                            </th>
                            <td>
                                <div class="pc-token-row">
                                    <input type="password"
                                           id="pc-apify-token"
                                           name="apify_token"
                                           class="regular-text"
                                           value=""
                                           placeholder="<?php echo $token ? esc_attr__('(saved — enter new value to replace)', 'pipecode-instagram-apify') : 'apify_api_…'; ?>"
                                           autocomplete="new-password">
                                    <button type="button" class="button pc-toggle-token" data-target="pc-apify-token" title="<?php esc_attr_e('Show / hide', 'pipecode-instagram-apify'); ?>">
                                        <span class="dashicons dashicons-visibility"></span>
                                    </button>
                                </div>
                                <?php if ($token) : ?>
                                <div class="pc-field-hint">
                                    <?php esc_html_e('Token is saved. Leave blank to keep the current one.', 'pipecode-instagram-apify'); ?>
                                </div>
                                <?php endif; ?>
                            </td>
                        </tr>
                    </table>
                    <div class="pc-settings-footer">
                        <button type="submit" class="button button-primary" id="pc-save-settings-btn">
                            <span class="dashicons dashicons-saved" style="display:none;" id="pc-save-icon-ok"></span>
                            <?php esc_html_e('Save Settings', 'pipecode-instagram-apify'); ?>
                        </button>
                        <span class="pc-settings-status" id="pc-settings-status" aria-live="polite"></span>
                    </div>
                </form>
            </div>

            <div class="pc-section-card">
                <h2><?php esc_html_e('Manual Sync', 'pipecode-instagram-apify'); ?></h2>
                <p class="pc-section-desc">
                    <?php esc_html_e('Trigger a sync right now. This may take up to 2 minutes while Apify runs and images are downloaded.', 'pipecode-instagram-apify'); ?>
                </p>
                <div class="pc-actions-row">
                    <button id="pc-sync-btn" class="button button-primary button-hero"
                            <?php echo ! $valid ? 'disabled title="' . esc_attr__('Configure a valid Apify endpoint first.', 'pipecode-instagram-apify') . '"' : ''; ?>>
                        <span class="dashicons dashicons-update pc-spin-icon" style="display:none;"></span>
                        <?php esc_html_e('Sync Now', 'pipecode-instagram-apify'); ?>
                    </button>
                    <p class="pc-sync-status" id="pc-sync-status">
                        <?php if (! $valid) : ?>
                            <span class="pc-error"><?php esc_html_e('Set a valid Apify endpoint in Settings above before syncing.', 'pipecode-instagram-apify'); ?></span>
                        <?php endif; ?>
                    </p>
                </div>
                <div id="pc-sync-log" class="pc-sync-log" style="display:none;">
                    <div class="pc-log-header">
                        <span><?php esc_html_e('Sync Results', 'pipecode-instagram-apify'); ?></span>
                        <button type="button" class="pc-log-close" id="pc-log-close">&times;</button>
                    </div>
                    <div id="pc-sync-log-content"></div>
                </div>
            </div>

            <div class="pc-section-card">
                <h2><?php esc_html_e('REST API', 'pipecode-instagram-apify'); ?></h2>
                <p class="pc-section-desc">
                    <?php esc_html_e('Use the following endpoint to retrieve posts. The', 'pipecode-instagram-apify'); ?>
                    <code>count</code>
                    <?php esc_html_e('parameter accepts values from 1 to 100 (default: 12).', 'pipecode-instagram-apify'); ?>
                </p>
                <div class="pc-api-url">
                    <code><?php echo esc_html($rest_url); ?>?count=12</code>
                    <button type="button" class="button pc-copy-btn" data-copy="<?php echo esc_attr($rest_url . '?count=12'); ?>">
                        <span class="dashicons dashicons-clipboard"></span>
                        <?php esc_html_e('Copy', 'pipecode-instagram-apify'); ?>
                    </button>
                </div>
            </div>
        </div>
        <?php
    }

    public static function render_posts(): void {
        $current_page = max(1, (int) ($_GET['paged'] ?? 1));
        $total        = PC_Instagram_DB::count();
        $total_pages  = (int) ceil($total / self::PER_PAGE);
        $posts        = PC_Instagram_DB::get_page(self::PER_PAGE, $current_page);
        $base_url     = admin_url('admin.php?page=' . self::POSTS_SLUG);
        ?>
        <div class="wrap pc-instagram-wrap">
            <h1 class="pc-instagram-title">
                <span class="dashicons dashicons-instagram"></span>
                <?php esc_html_e('Instagram Apify — Posts', 'pipecode-instagram-apify'); ?>
                <span class="pc-total-badge"><?php echo esc_html(number_format($total)); ?></span>
            </h1>

            <?php if (empty($posts)) : ?>
                <div class="pc-empty-state">
                    <span class="dashicons dashicons-images-alt2"></span>
                    <p><?php esc_html_e('No posts yet. Go to Dashboard and run a sync.', 'pipecode-instagram-apify'); ?></p>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=' . self::MENU_SLUG)); ?>" class="button button-primary">
                        <?php esc_html_e('Go to Dashboard', 'pipecode-instagram-apify'); ?>
                    </a>
                </div>
            <?php else : ?>

                <?php if ($total_pages > 1) : ?>
                <div class="pc-pagination pc-pagination-top">
                    <?php self::render_pagination($current_page, $total_pages, $base_url); ?>
                </div>
                <?php endif; ?>

                <table class="wp-list-table widefat fixed striped pc-posts-table">
                    <thead>
                        <tr>
                            <th class="col-img"><?php esc_html_e('Image', 'pipecode-instagram-apify'); ?></th>
                            <th class="col-id"><?php esc_html_e('Post ID', 'pipecode-instagram-apify'); ?></th>
                            <th class="col-type"><?php esc_html_e('Type', 'pipecode-instagram-apify'); ?></th>
                            <th><?php esc_html_e('Caption', 'pipecode-instagram-apify'); ?></th>
                            <th class="col-num"><?php esc_html_e('Likes', 'pipecode-instagram-apify'); ?></th>
                            <th class="col-num"><?php esc_html_e('Comments', 'pipecode-instagram-apify'); ?></th>
                            <th class="col-date"><?php esc_html_e('Posted', 'pipecode-instagram-apify'); ?></th>
                            <th class="col-date"><?php esc_html_e('Synced', 'pipecode-instagram-apify'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($posts as $post) : ?>
                        <tr>
                            <td class="col-img">
                                <?php if (! empty($post['display_url'])) : ?>
                                    <a href="<?php echo esc_url($post['url']); ?>" target="_blank" rel="noopener">
                                        <img src="<?php echo esc_url($post['display_url']); ?>"
                                             width="56" height="56"
                                             class="pc-thumb"
                                             alt="<?php echo esc_attr($post['alt']); ?>">
                                    </a>
                                <?php else : ?>
                                    <span class="dashicons dashicons-format-image pc-no-img"></span>
                                <?php endif; ?>
                            </td>
                            <td class="col-id">
                                <?php if (! empty($post['url'])) : ?>
                                    <a href="<?php echo esc_url($post['url']); ?>" target="_blank" rel="noopener">
                                        <?php echo esc_html($post['post_id']); ?>
                                    </a>
                                <?php else : ?>
                                    <?php echo esc_html($post['post_id']); ?>
                                <?php endif; ?>
                            </td>
                            <td class="col-type">
                                <span class="pc-badge pc-badge-<?php echo esc_attr(strtolower($post['type'])); ?>">
                                    <?php echo esc_html($post['type'] ?: '—'); ?>
                                </span>
                            </td>
                            <td class="col-caption">
                                <?php echo esc_html(mb_strimwidth($post['caption'] ?? '', 0, 120, '…')); ?>
                                <?php if (! empty($post['hashtags'])) : ?>
                                    <div class="pc-hashtags">
                                        <?php
                                        $tags = array_slice(explode(',', $post['hashtags']), 0, 5);
                                        foreach ($tags as $tag) {
                                            echo '<span class="pc-tag">' . esc_html(trim($tag)) . '</span>';
                                        }
                                        ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td class="col-num"><?php echo esc_html(number_format((int) $post['likes_count'])); ?></td>
                            <td class="col-num"><?php echo esc_html(number_format((int) $post['comments_count'])); ?></td>
                            <td class="col-date">
                                <?php echo $post['timestamp'] ? esc_html(date('M j, Y', strtotime($post['timestamp']))) : '—'; ?>
                            </td>
                            <td class="col-date">
                                <?php echo $post['created_at'] ? esc_html(date('M j, Y', strtotime($post['created_at']))) : '—'; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <?php if ($total_pages > 1) : ?>
                <div class="pc-pagination pc-pagination-bottom">
                    <?php self::render_pagination($current_page, $total_pages, $base_url); ?>
                </div>
                <?php endif; ?>

            <?php endif; ?>
        </div>
        <?php
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private static function render_pagination(int $current, int $total, string $base_url): void {
        $prev = $current - 1;
        $next = $current + 1;
        echo '<div class="pc-pager">';

        if ($current > 1) {
            echo '<a href="' . esc_url(add_query_arg('paged', $prev, $base_url)) . '" class="button">&laquo; ' . esc_html__('Previous', 'pipecode-instagram-apify') . '</a>';
        }

        echo '<span class="pc-pager-info">';
        printf(
            esc_html__('Page %1$d of %2$d', 'pipecode-instagram-apify'),
            $current,
            $total
        );
        echo '</span>';

        if ($current < $total) {
            echo '<a href="' . esc_url(add_query_arg('paged', $next, $base_url)) . '" class="button">' . esc_html__('Next', 'pipecode-instagram-apify') . ' &raquo;</a>';
        }

        echo '</div>';
    }
}
