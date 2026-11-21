<?php
/**
 * Plugin Name: SEOGhost Connector
 * Description: Reçoit et publie automatiquement les articles générés par SEOGhost (import médias, SEOPress/Yoast/RankMath, fallback SEO autonome, liens internes dynamiques, tableaux responsive et mises à jour GitHub).
 * Version: 1.0.2
 * Author: SEOGhost
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) {
    exit;
}

class SEOGhost_Connector {
    const VERSION          = '1.0.2';
    const GITHUB_REPO      = 'n3twrks/seoghost-wordpress-plugin';
    const UPDATE_TRANSIENT = 'seoghost_github_release';

    const OPTION_KEY       = 'seoghost_connector_settings';
    const META_ARTICLE_ID  = '_seoghost_article_id';
    const META_COVER_SRC   = '_seoghost_cover_source_url';
    const META_IMAGE_MAP   = '_seoghost_image_map';
    const META_SEO_TITLE   = '_seoghost_seo_title';
    const META_SEO_DESC    = '_seoghost_meta_description';
    const META_COVER_ALT   = '_seoghost_cover_alt';

    public function __construct() {
        add_action('admin_menu', [$this, 'add_settings_page']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('rest_api_init', [$this, 'register_routes']);

        // Fallback SEO autonome + CSS responsive articles SEOGhost
        add_filter('pre_get_document_title', [$this, 'filter_document_title'], 99);
        add_action('wp_head', [$this, 'render_head_tags'], 5);

        // Résolution dynamique des liens internes + tableaux responsive à l'affichage
        add_filter('the_content', [$this, 'resolve_internal_links'], 20);
        add_filter('the_content', [$this, 'wrap_responsive_tables'], 25);

        // Auto-update depuis les Releases GitHub
        add_filter('pre_set_site_transient_update_plugins', [$this, 'check_for_update']);
        add_filter('plugins_api', [$this, 'plugin_update_info'], 20, 3);
        add_action('upgrader_process_complete', [$this, 'purge_update_cache'], 10, 2);
        add_action('load-update-core.php', function () {
            if (!empty($_GET['force-check'])) {
                delete_transient(self::UPDATE_TRANSIENT);
            }
        });
    }

    public static function get_settings(): array {
        $defaults = [
            'site_slug'        => '',
            'api_key'          => '',
            'default_category' => 0,
            'default_author'   => 0,
            'sideload_images'  => 1,
            'inject_jsonld'    => 1,
        ];
        $saved = get_option(self::OPTION_KEY, []);
        return wp_parse_args(is_array($saved) ? $saved : [], $defaults);
    }

    public static function detect_seo_plugin(): string {
        if (defined('SEOPRESS_VERSION')) return 'seopress';
        if (defined('WPSEO_VERSION')) return 'yoast';
        if (defined('RANK_MATH_VERSION')) return 'rankmath';
        return 'none';
    }

    // -------------------------------------------------------------------------
    // Admin Settings Page (Réglages > SEOGhost)
    // -------------------------------------------------------------------------

    public function add_settings_page(): void {
        add_options_page(
            'SEOGhost Connector',
            'SEOGhost',
            'manage_options',
            'seoghost-connector',
            [$this, 'render_settings_page']
        );
    }

    public function register_settings(): void {
        register_setting(self::OPTION_KEY, self::OPTION_KEY, [
            'sanitize_callback' => function ($input) {
                $clean = [];
                $clean['site_slug']        = sanitize_title($input['site_slug'] ?? '');
                $clean['api_key']          = sanitize_text_field(trim($input['api_key'] ?? ''));
                $clean['default_category'] = absint($input['default_category'] ?? 0);
                $clean['default_author']   = absint($input['default_author'] ?? 0);
                $clean['sideload_images']  = empty($input['sideload_images']) ? 0 : 1;
                $clean['inject_jsonld']    = empty($input['inject_jsonld']) ? 0 : 1;
                return $clean;
            },
        ]);
    }

    public function render_settings_page(): void {
        if (!current_user_can('manage_options')) return;
        $settings   = self::get_settings();
        $seo_plugin = self::detect_seo_plugin();
        $categories = get_categories(['hide_empty' => false]);
        $authors    = get_users(['capability' => ['edit_posts'], 'orderby' => 'display_name']);
        $endpoint   = rest_url('seoghost/v1/publish');
        ?>
        <div class="wrap">
            <h1>SEOGhost Connector <small style="font-weight:normal;color:#666;">v<?php echo esc_html(self::VERSION); ?></small></h1>
            <p>Connectez ce site WordPress à votre espace SEOGhost pour publier automatiquement vos articles.</p>

            <table class="widefat striped" style="max-width: 720px; margin: 16px 0;">
                <tbody>
                    <tr>
                        <th style="width: 220px;">Endpoint Push</th>
                        <td><code><?php echo esc_html($endpoint); ?></code></td>
                    </tr>
                    <tr>
                        <th>Plugin SEO détecté</th>
                        <td>
                            <strong><?php echo esc_html(strtoupper($seo_plugin)); ?></strong>
                            <?php if ($seo_plugin === 'none'): ?>
                                — Mode SEO autonome actif (Title, Meta Description, Open Graph).
                            <?php else: ?>
                                — Les balises SEO seront écrites directement dans ses champs natifs.
                            <?php endif; ?>
                        </td>
                    </tr>
                </tbody>
            </table>

            <form method="post" action="options.php">
                <?php settings_fields(self::OPTION_KEY); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="sg_site_slug">Site Slug (SEOGhost)</label></th>
                        <td>
                            <input name="<?php echo esc_attr(self::OPTION_KEY); ?>[site_slug]" id="sg_site_slug" type="text"
                                   value="<?php echo esc_attr($settings['site_slug']); ?>" class="regular-text" placeholder="ex: bisecco" />
                            <p class="description">Optionnel si vous voulez restreindre ce WordPress à un seul slug de site SEOGhost.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="sg_api_key">SEOGhost API Key</label></th>
                        <td>
                            <input name="<?php echo esc_attr(self::OPTION_KEY); ?>[api_key]" id="sg_api_key" type="password"
                                   value="<?php echo esc_attr($settings['api_key']); ?>" class="regular-text" autocomplete="off" />
                            <p class="description">Copiez l'<code>api_key</code> du site depuis SEOGhost.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="sg_default_cat">Catégorie par défaut</label></th>
                        <td>
                            <select name="<?php echo esc_attr(self::OPTION_KEY); ?>[default_category]" id="sg_default_cat">
                                <option value="0">— Catégorie par défaut de WordPress —</option>
                                <?php foreach ($categories as $cat): ?>
                                    <option value="<?php echo esc_attr($cat->term_id); ?>" <?php selected($settings['default_category'], $cat->term_id); ?>>
                                        <?php echo esc_html($cat->name); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="sg_default_author">Auteur par défaut</label></th>
                        <td>
                            <select name="<?php echo esc_attr(self::OPTION_KEY); ?>[default_author]" id="sg_default_author">
                                <option value="0">— Premier administrateur —</option>
                                <?php foreach ($authors as $user): ?>
                                    <option value="<?php echo esc_attr($user->ID); ?>" <?php selected($settings['default_author'], $user->ID); ?>>
                                        <?php echo esc_html($user->display_name . ' (' . $user->user_login . ')'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Médias</th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[sideload_images]" value="1" <?php checked($settings['sideload_images'], 1); ?> />
                                Importer automatiquement l'image de couverture et les images de l'article dans la Médiathèque WordPress
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Données structurées (JSON-LD)</th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[inject_jsonld]" value="1" <?php checked($settings['inject_jsonld'], 1); ?> />
                                Injecter le schema <code>BlogPosting</code> sur les articles SEOGhost (recommandé si vous n'avez pas SEOPress Pro / Yoast / RankMath)
                            </label>
                        </td>
                    </tr>
                </table>
                <?php submit_button('Enregistrer les réglages'); ?>
            </form>
        </div>
        <?php
    }

    // -------------------------------------------------------------------------
    // REST API Routes (/wp-json/seoghost/v1/*)
    // -------------------------------------------------------------------------

    public function register_routes(): void {
        register_rest_route('seoghost/v1', '/status', [
            'methods'             => 'GET',
            'callback'            => [$this, 'handle_status'],
            'permission_callback' => [$this, 'authorize_request'],
        ]);

        register_rest_route('seoghost/v1', '/publish', [
            'methods'             => 'POST',
            'callback'            => [$this, 'handle_publish'],
            'permission_callback' => [$this, 'authorize_request'],
        ]);
    }

    public function authorize_request(WP_REST_Request $request) {
        $settings = self::get_settings();
        $expected = $settings['api_key'];

        if (empty($expected)) {
            return new WP_Error('seoghost_not_configured', 'SEOGhost API key is not configured in WordPress.', ['status' => 503]);
        }

        $provided = (string) $request->get_header('x-seoghost-key');
        if ($provided === '') {
            $auth = (string) $request->get_header('authorization');
            if (stripos($auth, 'Bearer ') === 0) {
                $provided = trim(substr($auth, 7));
            }
        }

        if ($provided === '' || !hash_equals($expected, $provided)) {
            return new WP_Error('seoghost_unauthorized', 'Invalid SEOGhost API key.', ['status' => 401]);
        }

        $expected_slug = $settings['site_slug'];
        $sent_slug     = sanitize_title((string) $request->get_header('x-seoghost-site'));
        if ($expected_slug !== '' && $sent_slug !== '' && !hash_equals($expected_slug, $sent_slug)) {
            return new WP_Error('seoghost_wrong_site', 'Site slug mismatch.', ['status' => 403]);
        }

        return true;
    }

    public function handle_status(): WP_REST_Response {
        $categories = array_map(function ($c) {
            return ['id' => (int) $c->term_id, 'name' => $c->name, 'slug' => $c->slug];
        }, get_categories(['hide_empty' => false]));

        return new WP_REST_Response([
            'ok'         => true,
            'version'    => self::VERSION,
            'wp_version' => get_bloginfo('version'),
            'seo_plugin' => self::detect_seo_plugin(),
            'categories' => $categories,
        ], 200);
    }

    public function handle_publish(WP_REST_Request $request) {
        $payload  = $request->get_json_params();
        $settings = self::get_settings();

        if (!is_array($payload) || empty($payload['seoghost_id']) || empty($payload['title'])) {
            return new WP_Error('seoghost_invalid_payload', 'Missing seoghost_id or title.', ['status' => 400]);
        }

        $seoghost_id = sanitize_text_field((string) $payload['seoghost_id']);
        $title       = wp_strip_all_tags((string) $payload['title']);
        $slug        = !empty($payload['slug']) ? sanitize_title((string) $payload['slug']) : sanitize_title($title);
        $excerpt     = isset($payload['excerpt']) ? wp_strip_all_tags((string) $payload['excerpt']) : '';
        $raw_html    = isset($payload['content_html']) ? (string) $payload['content_html'] : '';
        $status      = $this->map_status($payload['status'] ?? 'publish');

        $existing_id = $this->find_post_by_seoghost_id($seoghost_id);

        if (!$existing_id && $status !== 'publish') {
            return new WP_REST_Response(['ok' => true, 'skipped' => true, 'reason' => 'not_published_yet'], 200);
        }

        $author_id = $this->resolve_author_id($payload['author_name'] ?? null, (int) $settings['default_author']);

        $post_date_args = [];
        if (!empty($payload['published_at'])) {
            $ts = strtotime((string) $payload['published_at']);
            if ($ts !== false) {
                if ($status === 'publish') {
                    $ts = min($ts, time());
                }
                $gmt = gmdate('Y-m-d H:i:s', $ts);
                $post_date_args = [
                    'post_date'     => get_date_from_gmt($gmt),
                    'post_date_gmt' => $gmt,
                    'edit_date'     => true,
                ];
            }
        }

        $raw_html = $this->tag_internal_links($raw_html);

        $post_data = array_merge([
            'post_type'    => 'post',
            'post_title'   => $title,
            'post_name'    => $slug,
            'post_excerpt' => $excerpt,
            'post_content' => $raw_html,
            'post_status'  => $status,
            'post_author'  => $author_id,
        ], $post_date_args);

        if ($existing_id) {
            $post_data['ID'] = $existing_id;
        }

        kses_remove_filters();
        $post_id = $existing_id ? wp_update_post($post_data, true) : wp_insert_post($post_data, true);
        kses_init_filters();

        if (is_wp_error($post_id)) {
            return $post_id;
        }

        update_post_meta($post_id, self::META_ARTICLE_ID, $seoghost_id);

        $cat_id = (int) $settings['default_category'];
        if ($cat_id > 0) {
            wp_set_post_categories($post_id, [$cat_id], false);
        }
        if (isset($payload['tags']) && is_array($payload['tags'])) {
            $tag_names = [];
            foreach ($payload['tags'] as $t) {
                if (is_string($t) && $t !== '') {
                    $tag_names[] = sanitize_text_field($t);
                } elseif (is_array($t) && !empty($t['name'])) {
                    $tag_names[] = sanitize_text_field((string) $t['name']);
                }
            }
            wp_set_post_tags($post_id, $tag_names, false);
        }

        $cover_url    = !empty($payload['cover_image_url']) ? esc_url_raw((string) $payload['cover_image_url']) : '';
        $cover_alt    = !empty($payload['cover_alt']) ? sanitize_text_field((string) $payload['cover_alt']) : $title;
        $cover_credit = is_array($payload['cover_credit'] ?? null) ? $payload['cover_credit'] : null;

        $featured_id = 0;
        $local_cover = $cover_url;

        if ($settings['sideload_images'] && $cover_url !== '') {
            $featured_id = $this->sync_cover_image($post_id, $cover_url, $cover_alt, $cover_credit);
            if ($featured_id > 0) {
                $local_cover = wp_get_attachment_url($featured_id) ?: $cover_url;
            }
        }

        if ($settings['sideload_images'] && $raw_html !== '') {
            $processed_html = $this->sideload_inline_images($post_id, $raw_html);
            if ($processed_html !== $raw_html) {
                kses_remove_filters();
                wp_update_post(['ID' => $post_id, 'post_content' => $processed_html]);
                kses_init_filters();
            }
        }

        $seo_title = !empty($payload['seo_title']) ? sanitize_text_field((string) $payload['seo_title']) : $title;
        $meta_desc = !empty($payload['meta_description']) ? sanitize_text_field((string) $payload['meta_description']) : $excerpt;
        $this->save_seo_metadata($post_id, $seo_title, $meta_desc, $local_cover, $featured_id, $cover_alt);

        return new WP_REST_Response([
            'ok'         => true,
            'post_id'    => (int) $post_id,
            'url'        => get_permalink($post_id),
            'status'     => get_post_status($post_id),
            'seo_plugin' => self::detect_seo_plugin(),
        ], 200);
    }

    private function map_status(string $status): string {
        switch ($status) {
            case 'published':
            case 'publish':
                return 'publish';
            case 'archived':
            case 'trash':
                return 'draft';
            default:
                return 'draft';
        }
    }

    private function find_post_by_seoghost_id(string $seoghost_id): int {
        $posts = get_posts([
            'post_type'      => 'post',
            'post_status'    => ['publish', 'future', 'draft', 'pending', 'private'],
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'meta_key'       => self::META_ARTICLE_ID,
            'meta_value'     => $seoghost_id,
        ]);
        return !empty($posts) ? (int) $posts[0] : 0;
    }

    private function resolve_author_id(?string $author_name, int $default_author): int {
        if ($default_author > 0 && get_userdata($default_author)) {
            return $default_author;
        }
        if ($author_name) {
            $users = get_users(['search' => $author_name, 'search_columns' => ['display_name', 'user_nicename'], 'number' => 1]);
            if (!empty($users)) return (int) $users[0]->ID;
        }
        $admins = get_users(['role' => 'administrator', 'number' => 1, 'orderby' => 'ID', 'order' => 'ASC']);
        return !empty($admins) ? (int) $admins[0]->ID : 1;
    }

    // -------------------------------------------------------------------------
    // Responsive Tables & Mobile Overflow Protection
    // -------------------------------------------------------------------------

    public function wrap_responsive_tables(string $content): string {
        if ($content === '' || !is_singular('post') || !in_the_loop() || !is_main_query()) {
            return $content;
        }
        if (!get_post_meta(get_the_ID(), self::META_ARTICLE_ID, true)) {
            return $content;
        }
        if (stripos($content, '<table') === false || strpos($content, 'seoghost-table-wrap') !== false) {
            return $content;
        }

        return (string) preg_replace(
            '/<table\b[^>]*>[\s\S]*?<\/table>/i',
            '<div class="seoghost-table-wrap">$0</div>',
            $content
        );
    }

    // -------------------------------------------------------------------------
    // Dynamic Internal Link Resolution
    // -------------------------------------------------------------------------

    private function tag_internal_links(string $html): string {
        if ($html === '' || stripos($html, '<a ') === false) {
            return $html;
        }

        return (string) preg_replace_callback('/<a\b([^>]*)>/i', function ($matches) {
            $attrs = $matches[1];
            if (stripos($attrs, 'data-seoghost-post=') !== false) {
                return $matches[0];
            }
            if (!preg_match('/\bhref=(["\'])([^"\']+)\1/i', $attrs, $href_m)) {
                return $matches[0];
            }
            $target = $this->find_target_post_by_href($href_m[2]);
            if (!$target) {
                return $matches[0];
            }
            return '<a' . $attrs . ' data-seoghost-post="' . (int) $target->ID . '">';
        }, $html);
    }

    public function resolve_internal_links(string $content): string {
        if ($content === '' || !is_singular('post') || !in_the_loop() || !is_main_query()) {
            return $content;
        }
        if (!get_post_meta(get_the_ID(), self::META_ARTICLE_ID, true)) {
            return $content;
        }
        if (stripos($content, '<a ') === false) {
            return $content;
        }

        return (string) preg_replace_callback('/<a\b([^>]*)>/i', function ($matches) {
            $attrs = $matches[1];
            if (!preg_match('/\bhref=(["\'])([^"\']+)\1/i', $attrs, $href_m)) {
                return $matches[0];
            }

            $href     = $href_m[2];
            $fragment = '';
            $hash_pos = strpos($href, '#');
            if ($hash_pos !== false) {
                $fragment = substr($href, $hash_pos);
            }

            $target_id = 0;
            if (preg_match('/\bdata-seoghost-post=(["\']?)(\d+)\1/i', $attrs, $id_m)) {
                $target_id = (int) $id_m[2];
            } else {
                $target = $this->find_target_post_by_href($href);
                if ($target) {
                    $target_id = (int) $target->ID;
                }
            }

            if ($target_id <= 0 || get_post_status($target_id) !== 'publish') {
                return $matches[0];
            }

            $permalink = get_permalink($target_id);
            if (!$permalink) {
                return $matches[0];
            }

            $new_href  = esc_url($permalink . $fragment);
            $new_attrs = preg_replace(
                '/\bhref=(["\'])([^"\']+)\1/i',
                'href=$1' . $new_href . '$1',
                $attrs,
                1
            );

            return '<a' . $new_attrs . '>';
        }, $content);
    }

    private function find_target_post_by_href(string $href): ?WP_Post {
        $href = trim(html_entity_decode($href, ENT_QUOTES, 'UTF-8'));
        if ($href === '' || $href[0] === '#' || preg_match('/^(mailto|tel|javascript):/i', $href)) {
            return null;
        }

        $parsed = wp_parse_url($href);
        if (!empty($parsed['host'])) {
            $site_host = preg_replace('/^www\./i', '', (string) wp_parse_url(home_url(), PHP_URL_HOST));
            $link_host = preg_replace('/^www\./i', '', (string) $parsed['host']);
            if (strcasecmp($site_host, $link_host) !== 0) {
                return null;
            }
        }

        $path = trim((string) ($parsed['path'] ?? ''), '/');
        if ($path === '' || preg_match('/\.[a-z0-9]{2,5}$/i', $path)) {
            return null;
        }

        $segments = explode('/', $path);
        if (count($segments) > 2) {
            return null;
        }

        if (get_page_by_path($path, OBJECT, 'page')) {
            return null;
        }

        $slug = end($segments);
        $post = get_page_by_path($slug, OBJECT, 'post');
        return ($post instanceof WP_Post) ? $post : null;
    }

    // -------------------------------------------------------------------------
    // Media Sideloading
    // -------------------------------------------------------------------------

    private function sync_cover_image(int $post_id, string $url, string $alt, ?array $credit): int {
        $prev_url   = (string) get_post_meta($post_id, self::META_COVER_SRC, true);
        $current_id = (int) get_post_thumbnail_id($post_id);

        if ($current_id > 0 && $prev_url === $url) {
            update_post_meta($current_id, '_wp_attachment_image_alt', $alt);
            return $current_id;
        }

        $caption = '';
        if ($credit && !empty($credit['name']) && !empty($credit['url'])) {
            $caption = sprintf(
                'Photo by <a href="%s" target="_blank" rel="noopener noreferrer">%s</a> on Unsplash',
                esc_url($credit['url']),
                esc_html($credit['name'])
            );
        }

        $attachment_id = $this->download_and_attach_image($url, $post_id, $alt, $caption);
        if ($attachment_id > 0) {
            set_post_thumbnail($post_id, $attachment_id);
            update_post_meta($post_id, self::META_COVER_SRC, $url);
        }
        return $attachment_id;
    }

    private function sideload_inline_images(int $post_id, string $html): string {
        if (!preg_match_all('/<img\b[^>]*\bsrc=["\']([^"\']+)["\'][^>]*>/i', $html, $matches, PREG_SET_ORDER)) {
            return $html;
        }

        $site_host = wp_parse_url(home_url(), PHP_URL_HOST);
        $map       = get_post_meta($post_id, self::META_IMAGE_MAP, true);
        $map       = is_array($map) ? $map : [];
        $changed   = false;

        foreach ($matches as $m) {
            $src = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
            if (strpos($src, 'http') !== 0) continue;

            $img_host = wp_parse_url($src, PHP_URL_HOST);
            if ($img_host && $site_host && strcasecmp($img_host, $site_host) === 0) {
                continue;
            }

            if (isset($map[$src]) && wp_get_attachment_url((int) $map[$src])) {
                $local_url = wp_get_attachment_url((int) $map[$src]);
            } else {
                $alt = '';
                if (preg_match('/\balt=["\']([^"\']*)["\']/i', $m[0], $alt_m)) {
                    $alt = html_entity_decode($alt_m[1], ENT_QUOTES, 'UTF-8');
                }
                $att_id = $this->download_and_attach_image($src, $post_id, $alt);
                if ($att_id <= 0) continue;
                $map[$src] = $att_id;
                $changed   = true;
                $local_url = wp_get_attachment_url($att_id);
            }

            if ($local_url) {
                $html = str_replace($m[1], esc_url($local_url), $html);
            }
        }

        if ($changed) {
            update_post_meta($post_id, self::META_IMAGE_MAP, $map);
        }

        return $html;
    }

    private function download_and_attach_image(string $url, int $post_id, string $alt = '', string $caption = ''): int {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $tmp = download_url($url, 30);
        if (is_wp_error($tmp)) {
            return 0;
        }

        $mime = function_exists('wp_get_image_mime') ? wp_get_image_mime($tmp) : false;
        $ext_map = [
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/webp' => 'webp',
            'image/gif'  => 'gif',
            'image/avif' => 'avif',
        ];
        $ext = $mime && isset($ext_map[$mime]) ? $ext_map[$mime] : '';

        $path_name = basename((string) wp_parse_url($url, PHP_URL_PATH));
        $base_name = sanitize_file_name(pathinfo($path_name, PATHINFO_FILENAME) ?: 'seoghost-image');
        if ($ext === '') {
            $path_ext = strtolower((string) pathinfo($path_name, PATHINFO_EXTENSION));
            $ext = in_array($path_ext, ['jpg', 'jpeg', 'png', 'webp', 'gif', 'avif'], true) ? $path_ext : 'jpg';
        }

        $file_array = [
            'name'     => $base_name . '.' . $ext,
            'tmp_name' => $tmp,
        ];

        $post_overrides = [];
        if ($caption !== '') {
            $post_overrides['post_excerpt'] = $caption;
        }

        $att_id = media_handle_sideload($file_array, $post_id, $alt, $post_overrides);
        if (is_wp_error($att_id)) {
            @unlink($tmp);
            return 0;
        }

        if ($alt !== '') {
            update_post_meta($att_id, '_wp_attachment_image_alt', $alt);
        }

        return (int) $att_id;
    }

    // -------------------------------------------------------------------------
    // SEO Metadata, Responsive Styles & Standalone Fallback
    // -------------------------------------------------------------------------

    private function save_seo_metadata(int $post_id, string $seo_title, string $meta_desc, string $cover_url, int $cover_id, string $cover_alt): void {
        update_post_meta($post_id, self::META_SEO_TITLE, $seo_title);
        update_post_meta($post_id, self::META_SEO_DESC, $meta_desc);
        update_post_meta($post_id, self::META_COVER_ALT, $cover_alt);

        $seo_plugin = self::detect_seo_plugin();

        if ($seo_plugin === 'seopress') {
            update_post_meta($post_id, '_seopress_titles_title', $seo_title);
            update_post_meta($post_id, '_seopress_titles_desc', $meta_desc);
            update_post_meta($post_id, '_seopress_social_fb_title', $seo_title);
            update_post_meta($post_id, '_seopress_social_fb_desc', $meta_desc);
            update_post_meta($post_id, '_seopress_social_twitter_title', $seo_title);
            update_post_meta($post_id, '_seopress_social_twitter_desc', $meta_desc);
            if ($cover_url !== '') {
                update_post_meta($post_id, '_seopress_social_fb_img', $cover_url);
                update_post_meta($post_id, '_seopress_social_twitter_img', $cover_url);
            }
            if ($cover_id > 0) {
                update_post_meta($post_id, '_seopress_social_fb_img_attachment_id', $cover_id);
                update_post_meta($post_id, '_seopress_social_twitter_img_attachment_id', $cover_id);
            }
        } elseif ($seo_plugin === 'yoast') {
            update_post_meta($post_id, '_yoast_wpseo_title', $seo_title);
            update_post_meta($post_id, '_yoast_wpseo_metadesc', $meta_desc);
            update_post_meta($post_id, '_yoast_wpseo_opengraph-title', $seo_title);
            update_post_meta($post_id, '_yoast_wpseo_opengraph-description', $meta_desc);
        } elseif ($seo_plugin === 'rankmath') {
            update_post_meta($post_id, 'rank_math_title', $seo_title);
            update_post_meta($post_id, 'rank_math_description', $meta_desc);
            update_post_meta($post_id, 'rank_math_facebook_title', $seo_title);
            update_post_meta($post_id, 'rank_math_facebook_description', $meta_desc);
        }
    }

    public function filter_document_title(string $title): string {
        if (!is_singular('post') || self::detect_seo_plugin() !== 'none') {
            return $title;
        }
        $post_id = get_queried_object_id();
        if (!get_post_meta($post_id, self::META_ARTICLE_ID, true)) {
            return $title;
        }
        $seo_title = (string) get_post_meta($post_id, self::META_SEO_TITLE, true);
        return $seo_title !== '' ? $seo_title : $title;
    }

    public function render_head_tags(): void {
        if (!is_singular('post')) return;
        $post_id = get_queried_object_id();
        if (!get_post_meta($post_id, self::META_ARTICLE_ID, true)) return;

        echo "<style id=\"seoghost-connector-responsive\">\n"
            . "main, article, .entry-content { min-width: 0; }\n"
            . "article img { max-width: 100%; height: auto; }\n"
            . "article p, article li, article a { overflow-wrap: break-word; }\n"
            . ".seoghost-table-wrap { display: block; width: 100%; max-width: min(100%, calc(100vw - 2rem)); overflow-x: auto; -webkit-overflow-scrolling: touch; margin: 1.5rem 0; }\n"
            . ".seoghost-table-wrap > table { width: 100%; min-width: 480px; border-collapse: collapse; }\n"
            . "</style>\n";

        $seo_plugin = self::detect_seo_plugin();
        $seo_title  = (string) get_post_meta($post_id, self::META_SEO_TITLE, true) ?: get_the_title($post_id);
        $meta_desc  = (string) get_post_meta($post_id, self::META_SEO_DESC, true);
        $permalink  = get_permalink($post_id);
        $cover_url  = get_the_post_thumbnail_url($post_id, 'full') ?: '';

        if ($seo_plugin === 'none') {
            if ($meta_desc !== '') {
                echo '<meta name="description" content="' . esc_attr($meta_desc) . "\" />\n";
                echo '<meta property="og:description" content="' . esc_attr($meta_desc) . "\" />\n";
            }
            echo '<meta property="og:type" content="article" />' . "\n";
            echo '<meta property="og:title" content="' . esc_attr($seo_title) . "\" />\n";
            echo '<meta property="og:url" content="' . esc_url($permalink) . "\" />\n";
            if ($cover_url !== '') {
                echo '<meta property="og:image" content="' . esc_url($cover_url) . "\" />\n";
            }
        }

        $settings = self::get_settings();
        $has_native_schema = ($seo_plugin === 'yoast' || $seo_plugin === 'rankmath' || defined('SEOPRESS_PRO_VERSION'));
        if ($settings['inject_jsonld'] && !$has_native_schema) {
            $post = get_post($post_id);
            $author_name = $post ? get_the_author_meta('display_name', $post->post_author) : get_bloginfo('name');
            $schema = [
                '@context'         => 'https://schema.org',
                '@type'            => 'BlogPosting',
                'headline'         => $seo_title,
                'description'      => $meta_desc,
                'mainEntityOfPage' => $permalink,
                'datePublished'    => get_the_date('c', $post_id),
                'dateModified'     => get_the_modified_date('c', $post_id),
                'author'           => ['@type' => 'Person', 'name' => $author_name],
                'publisher'        => ['@type' => 'Organization', 'name' => get_bloginfo('name')],
            ];
            if ($cover_url !== '') {
                $schema['image'] = [$cover_url];
            }
            echo '<script type="application/ld+json">' . wp_json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "</script>\n";
        }
    }

    // -------------------------------------------------------------------------
    // Auto-Update via GitHub Releases
    // -------------------------------------------------------------------------

    private function get_latest_github_release(): ?array {
        $cached = get_transient(self::UPDATE_TRANSIENT);
        if (is_array($cached)) {
            return !empty($cached['error']) ? null : $cached;
        }

        $url = 'https://api.github.com/repos/' . self::GITHUB_REPO . '/releases/latest';
        $res = wp_remote_get($url, [
            'timeout' => 10,
            'headers' => [
                'Accept'     => 'application/vnd.github+json',
                'User-Agent' => 'SEOGhost-Connector/' . self::VERSION,
            ],
        ]);

        if (is_wp_error($res) || wp_remote_retrieve_response_code($res) !== 200) {
            set_transient(self::UPDATE_TRANSIENT, ['error' => true], 30 * MINUTE_IN_SECONDS);
            return null;
        }

        $body = json_decode((string) wp_remote_retrieve_body($res), true);
        if (!is_array($body) || empty($body['tag_name'])) {
            set_transient(self::UPDATE_TRANSIENT, ['error' => true], 30 * MINUTE_IN_SECONDS);
            return null;
        }

        $package = '';
        if (!empty($body['assets']) && is_array($body['assets'])) {
            foreach ($body['assets'] as $asset) {
                if (!empty($asset['browser_download_url']) && substr((string) ($asset['name'] ?? ''), -4) === '.zip') {
                    $package = (string) $asset['browser_download_url'];
                    break;
                }
            }
        }

        if ($package === '') {
            set_transient(self::UPDATE_TRANSIENT, ['error' => true], 30 * MINUTE_IN_SECONDS);
            return null;
        }

        $release = [
            'version'   => ltrim((string) $body['tag_name'], 'vV'),
            'url'       => (string) ($body['html_url'] ?? ''),
            'package'   => $package,
            'changelog' => nl2br(esc_html((string) ($body['body'] ?? ''))),
            'published' => (string) ($body['published_at'] ?? ''),
        ];

        set_transient(self::UPDATE_TRANSIENT, $release, 6 * HOUR_IN_SECONDS);
        return $release;
    }

    public function check_for_update($transient) {
        if (!is_object($transient) || empty($transient->checked)) {
            return $transient;
        }

        $release = $this->get_latest_github_release();
        if (!$release) {
            return $transient;
        }

        $plugin_file = plugin_basename(__FILE__);
        if (version_compare($release['version'], self::VERSION, '>')) {
            $transient->response[$plugin_file] = (object) [
                'slug'         => 'seoghost-connector',
                'plugin'       => $plugin_file,
                'new_version'  => $release['version'],
                'url'          => $release['url'],
                'package'      => $release['package'],
                'requires'     => '6.0',
                'requires_php' => '7.4',
            ];
        }

        return $transient;
    }

    public function plugin_update_info($result, $action, $args) {
        if ($action !== 'plugin_information' || empty($args->slug) || $args->slug !== 'seoghost-connector') {
            return $result;
        }

        $release = $this->get_latest_github_release();
        if (!$release) {
            return $result;
        }

        return (object) [
            'name'          => 'SEOGhost Connector',
            'slug'          => 'seoghost-connector',
            'version'       => $release['version'],
            'author'        => 'SEOGhost',
            'homepage'      => $release['url'],
            'download_link' => $release['package'],
            'last_updated'  => $release['published'],
            'requires'      => '6.0',
            'requires_php'  => '7.4',
            'sections'      => [
                'description' => 'Connecteur officiel SEOGhost pour WordPress.',
                'changelog'   => $release['changelog'] ?: 'Mise à jour depuis GitHub.',
            ],
        ];
    }

    public function purge_update_cache($upgrader, $options): void {
        if (is_array($options) && ($options['action'] ?? '') === 'update' && ($options['type'] ?? '') === 'plugin') {
            delete_transient(self::UPDATE_TRANSIENT);
        }
    }
}

new SEOGhost_Connector();