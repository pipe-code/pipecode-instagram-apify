<?php
defined('ABSPATH') || exit;

/**
 * REST API — namespace: pipecode/v1
 *
 * GET /wp-json/pipecode/v1/instagram
 *   ?count=12   — number of posts to return (1–100, default 12)
 *
 * Returns a JSON array of post objects.
 */
class PC_Instagram_API {

    const NAMESPACE = 'pipecode/v1';
    const ROUTE     = '/instagram';

    public static function init(): void {
        add_action('rest_api_init', [__CLASS__, 'register_routes']);
    }

    public static function register_routes(): void {
        register_rest_route(self::NAMESPACE, self::ROUTE, [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [__CLASS__, 'get_posts'],
            'permission_callback' => '__return_true', // public endpoint
            'args'                => [
                'count' => [
                    'description'       => 'Number of posts to return (1–100).',
                    'type'              => 'integer',
                    'default'           => 12,
                    'minimum'           => 1,
                    'maximum'           => 100,
                    'sanitize_callback' => 'absint',
                    'validate_callback' => function ($value) {
                        return is_numeric($value) && (int) $value >= 1 && (int) $value <= 100;
                    },
                ],
            ],
        ]);
    }

    public static function get_posts(WP_REST_Request $request): WP_REST_Response {
        $count = (int) $request->get_param('count');
        $rows  = PC_Instagram_DB::get_latest($count);

        $data = array_map([__CLASS__, 'format_post'], $rows);

        return new WP_REST_Response($data, 200);
    }

    private static function format_post(array $row): array {
        return [
            'id'             => (int) $row['id'],
            'post_id'        => $row['post_id'],
            'caption'        => $row['caption'],
            'display_url'    => $row['display_url'] ?: null,
            'type'           => $row['type'],
            'hashtags'       => $row['hashtags'] ? explode(',', $row['hashtags']) : [],
            'mentions'       => $row['mentions']  ? explode(',', $row['mentions'])  : [],
            'url'            => $row['url'],
            'alt'            => $row['alt'],
            'likes_count'    => (int) $row['likes_count'],
            'comments_count' => (int) $row['comments_count'],
            'timestamp'      => $row['timestamp'],
            'images'         => $row['images'] ? json_decode($row['images'], true) : [],
        ];
    }
}
