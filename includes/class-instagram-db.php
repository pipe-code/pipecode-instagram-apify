<?php
defined('ABSPATH') || exit;

class PC_Instagram_DB {

    public static function table_name(): string {
        global $wpdb;
        return $wpdb->prefix . PC_INSTAGRAM_TABLE;
    }

    public static function create_table(): void {
        global $wpdb;
        $table   = self::table_name();
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$table} (
            id             BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            post_id        VARCHAR(255)        NOT NULL DEFAULT '',
            caption        LONGTEXT,
            display_url    VARCHAR(2048),
            type           VARCHAR(64),
            hashtags       TEXT,
            mentions       TEXT,
            url            VARCHAR(2048),
            alt            VARCHAR(512),
            likes_count    INT(11)             NOT NULL DEFAULT 0,
            comments_count INT(11)             NOT NULL DEFAULT 0,
            timestamp      DATETIME,
            images         LONGTEXT            COMMENT 'JSON array of local image URLs for carousel posts',
            created_at     DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at     DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY   post_id (post_id)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);

        update_option('pc_instagram_db_version', PC_INSTAGRAM_VERSION);
    }

    public static function drop_table(): void {
        global $wpdb;
        $table = self::table_name();
        $wpdb->query("DROP TABLE IF EXISTS {$table}");
    }

    public static function get_by_post_id(string $post_id): ?array {
        global $wpdb;
        $table = self::table_name();
        $row   = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE post_id = %s LIMIT 1", $post_id),
            ARRAY_A
        );
        return $row ?: null;
    }

    /**
     * Upsert a single post row. Returns row id or false.
     */
    public static function upsert(array $data) {
        global $wpdb;
        $table = self::table_name();

        $existing = $wpdb->get_var(
            $wpdb->prepare("SELECT id FROM {$table} WHERE post_id = %s LIMIT 1", $data['post_id'])
        );

        if ($existing) {
            $data['updated_at'] = current_time('mysql', true);
            $wpdb->update($table, $data, ['id' => $existing]);
            return (int) $existing;
        }

        $data['created_at'] = current_time('mysql', true);
        $data['updated_at'] = current_time('mysql', true);
        $wpdb->insert($table, $data);
        return $wpdb->insert_id ?: false;
    }

    /**
     * Paginated list, newest first.
     */
    public static function get_page(int $per_page = 20, int $page = 1): array {
        global $wpdb;
        $table  = self::table_name();
        $offset = ($page - 1) * $per_page;

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} ORDER BY timestamp DESC LIMIT %d OFFSET %d",
                $per_page,
                $offset
            ),
            ARRAY_A
        ) ?: [];
    }

    /**
     * Get N most recent posts (used by REST API).
     */
    public static function get_latest(int $count = 12): array {
        global $wpdb;
        $table = self::table_name();

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} ORDER BY timestamp DESC LIMIT %d",
                $count
            ),
            ARRAY_A
        ) ?: [];
    }

    /**
     * For posts with an empty cover but a downloaded gallery, promote gallery[0] as the cover.
     * Runs after every sync to repair orphaned posts that Apify no longer returns.
     */
    public static function backfill_covers_from_gallery(): int {
        global $wpdb;
        $table = self::table_name();

        $rows = $wpdb->get_results(
            "SELECT id, images FROM {$table}
             WHERE (display_url IS NULL OR display_url = '')
               AND images IS NOT NULL AND images != '' AND images != '[]'",
            ARRAY_A
        );

        $fixed = 0;
        foreach ($rows as $row) {
            $imgs = json_decode($row['images'], true);
            if (! empty($imgs[0])) {
                $wpdb->update($table, ['display_url' => $imgs[0]], ['id' => (int) $row['id']]);
                $fixed++;
            }
        }

        return $fixed;
    }

    public static function count(): int {
        global $wpdb;
        $table = self::table_name();
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
    }
}
