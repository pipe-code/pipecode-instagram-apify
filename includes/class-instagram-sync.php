<?php
defined('ABSPATH') || exit;

class PC_Instagram_Sync {

    private string $apify_token;
    private string $apify_dataset_url;

    public function __construct() {
        $options = get_option('pc_instagram_settings', []);

        $this->apify_token       = $options['apify_token']       ?? '';
        $this->apify_dataset_url = $options['apify_dataset_url'] ?? '';
    }

    /**
     * Validate that a URL is a proper Apify API endpoint.
     * Must be https://api.apify.com/...
     */
    public static function is_valid_apify_url(string $url): bool {
        if (empty($url)) return false;
        $parsed = parse_url($url);
        return isset($parsed['scheme'], $parsed['host'])
            && $parsed['scheme'] === 'https'
            && $parsed['host']   === 'api.apify.com';
    }

    /**
     * Fetch items from Apify, upsert each one. Returns result log.
     */
    public function run(): array {
        if (empty($this->apify_token)) {
            return [['ok' => false, 'error' => 'Apify token is not configured.']];
        }

        if (! self::is_valid_apify_url($this->apify_dataset_url)) {
            return [['ok' => false, 'error' => 'Invalid or missing Apify endpoint. It must start with https://api.apify.com/']];
        }

        $url      = add_query_arg('token', $this->apify_token, $this->apify_dataset_url);
        $response = wp_remote_post($url, [
            'timeout' => 120,
            'headers' => ['Content-Type' => 'application/json'],
        ]);

        if (is_wp_error($response)) {
            return [['ok' => false, 'error' => $response->get_error_message()]];
        }

        $body  = wp_remote_retrieve_body($response);
        $items = json_decode($body, true);

        if (! is_array($items)) {
            return [['ok' => false, 'error' => 'Invalid response from Apify: ' . substr($body, 0, 200)]];
        }

        // Sort newest first
        usort($items, fn($a, $b) => strtotime($b['timestamp'] ?? 0) - strtotime($a['timestamp'] ?? 0));

        $results = [];
        foreach ($items as $item) {
            $results[] = $this->process_item($item);
        }

        // Repair any posts that have a downloaded gallery but no cover image.
        PC_Instagram_DB::backfill_covers_from_gallery();

        update_option('pc_instagram_last_sync', current_time('mysql', true));
        return $results;
    }

    private function process_item(array $item): array {
        $post_id = $item['id'] ?? '';
        if (empty($post_id)) {
            return ['ok' => false, 'post_id' => '', 'error' => 'Missing post id'];
        }

        try {
            $existing    = PC_Instagram_DB::get_by_post_id($post_id);
            $is_new      = empty($existing);

            // ── Gallery images ───────────────────────────────────────────────
            // Only download if none are stored yet.
            $gallery = ($existing['images'] ?? null)
                ? json_decode($existing['images'], true)
                : [];

            if (empty($gallery)) {
                // Prefer childPosts (has individual shortCodes), fall back to top-level images array.
                $gallery_srcs = [];
                if (! empty($item['childPosts']) && is_array($item['childPosts'])) {
                    foreach ($item['childPosts'] as $idx => $child) {
                        $src = $child['displayUrl'] ?? (is_array($item['images'] ?? null) ? ($item['images'][$idx] ?? '') : '');
                        if (! empty($src)) {
                            $gallery_srcs[] = $src;
                        }
                    }
                } elseif (! empty($item['images']) && is_array($item['images'])) {
                    $gallery_srcs = $item['images'];
                }

                foreach ($gallery_srcs as $i => $img_src) {
                    $child_id = $this->download_image(
                        $img_src,
                        'ig-' . ($item['shortCode'] ?? $post_id) . '-img-' . ($i + 1)
                    );
                    if ($child_id) {
                        $gallery[] = wp_get_attachment_url($child_id);
                    }
                }
            }

            // ── Cover image ──────────────────────────────────────────────────
            // Keep the already-stored local URL; only download if we don't have one.
            $display_url = $existing['display_url'] ?? '';

            if (empty($display_url)) {
                // Priority: displayUrl → childPosts[0] → top-level images[0] → already-downloaded gallery[0]
                $cover_src = $item['displayUrl'] ?? '';
                if (empty($cover_src) && ! empty($item['childPosts'][0]['displayUrl'])) {
                    $cover_src = $item['childPosts'][0]['displayUrl'];
                }
                if (empty($cover_src) && ! empty($item['images'][0])) {
                    $cover_src = $item['images'][0];
                }

                if (! empty($cover_src)) {
                    $attachment_id = $this->download_image(
                        $cover_src,
                        'ig-' . ($item['shortCode'] ?? $post_id) . '-cover'
                    );
                    if ($attachment_id) {
                        $display_url = wp_get_attachment_url($attachment_id);
                    }
                }

                // Last resort: if gallery was already downloaded, promote first image as cover.
                if (empty($display_url) && ! empty($gallery[0])) {
                    $display_url = $gallery[0];
                }
            }

            // ── Always update text / engagement fields ───────────────────────
            $data = [
                'post_id'        => $post_id,
                'caption'        => $item['caption']       ?? '',
                'display_url'    => $display_url,
                'type'           => $item['type']           ?? '',
                'hashtags'       => isset($item['hashtags'])  ? implode(',', (array) $item['hashtags'])  : '',
                'mentions'       => isset($item['mentions'])  ? implode(',', (array) $item['mentions'])  : '',
                'url'            => $item['url']            ?? '',
                'alt'            => $item['alt']            ?? '',
                'likes_count'    => (int) ($item['likesCount']    ?? 0),
                'comments_count' => (int) ($item['commentsCount'] ?? 0),
                'timestamp'      => ! empty($item['timestamp'])
                                        ? date('Y-m-d H:i:s', strtotime($item['timestamp']))
                                        : null,
                'images'         => $gallery ? wp_json_encode($gallery) : null,
            ];

            $row_id = PC_Instagram_DB::upsert($data);

            return ['ok' => true, 'post_id' => $post_id, 'row_id' => $row_id, 'action' => $is_new ? 'created' : 'updated'];

        } catch (Throwable $e) {
            return ['ok' => false, 'post_id' => $post_id, 'error' => $e->getMessage()];
        }
    }

    /**
     * Download a remote image and sideload it into the WP media library.
     * Returns attachment ID or 0 on failure.
     */
    private function download_image(string $url, string $name_base = 'instagram'): int {
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $url_path = parse_url($url, PHP_URL_PATH);
        $ext      = pathinfo($url_path, PATHINFO_EXTENSION) ?: 'jpg';
        $ext      = strtolower(preg_replace('/\?.*/', '', $ext));
        if (! in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'mp4', 'mov'], true)) {
            $ext = 'jpg';
        }

        $tmp = download_url($url, 60);
        if (is_wp_error($tmp)) {
            return 0;
        }

        $file_array = [
            'name'     => $name_base . '.' . $ext,
            'tmp_name' => $tmp,
        ];

        $id = media_handle_sideload($file_array, 0);

        if (is_wp_error($id)) {
            @unlink($tmp);
            return 0;
        }

        return (int) $id;
    }
}
