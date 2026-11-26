<?php
/**
 * Plugin Name: AMS Wren Website
 * Description: Site-side support for Wren Workspace (AMS AI Village, Website & Automation). Lets Wren's dedicated Editor account save and read back page drafts' Elementor layout and Yoast SEO fields over the REST API, keeps Elementor and SiteGround caches in step when Wren changes a page, and gives Wren a status check. Wren publishes or replaces a live page only on Jimy's click in Wren; reading or writing a page's fields requires permission to edit that page.
 * Version: 1.4.1
 * Update URI: https://github.com/jimster-status/ams-wren-website
 * Author: Awaken Media Solutions
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) {
    exit;
}

const AMS_WREN_WEBSITE_VERSION = '1.4.1';
const AMS_WREN_WEBSITE_REPO = 'https://github.com/jimster-status/ams-wren-website';
const AMS_WREN_WEBSITE_UPDATE_URL = 'https://raw.githubusercontent.com/jimster-status/ams-wren-website/main/update.json';

/*
 * Updates come from the plugin's own GitHub repository, so WordPress shows the usual
 * "There is a new version ... update now" notice. Only zips hosted in that repository are accepted.
 */
function ams_wren_website_remote_info($force = false) {
    $cached = $force ? false : get_site_transient('ams_wren_website_update');
    if ($cached !== false) {
        return $cached ?: null;
    }
    $info = null;
    $response = wp_remote_get(AMS_WREN_WEBSITE_UPDATE_URL, ['timeout' => 10, 'headers' => ['Accept' => 'application/json']]);
    if (!is_wp_error($response) && (int) wp_remote_retrieve_response_code($response) === 200) {
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (is_array($data) && !empty($data['version']) && !empty($data['download_url'])
            && strpos($data['download_url'], AMS_WREN_WEBSITE_REPO . '/') === 0) {
            $info = $data;
        }
    }
    set_site_transient('ams_wren_website_update', $info ?: 0, 6 * HOUR_IN_SECONDS);
    return $info;
}

add_filter('pre_set_site_transient_update_plugins', function ($transient) {
    if (!is_object($transient)) {
        return $transient;
    }
    $file = plugin_basename(__FILE__);
    $info = ams_wren_website_remote_info();
    $item = (object) [
        'id'           => AMS_WREN_WEBSITE_REPO,
        'slug'         => 'ams-wren-website',
        'plugin'       => $file,
        'url'          => AMS_WREN_WEBSITE_REPO,
        'new_version'  => AMS_WREN_WEBSITE_VERSION,
        'package'      => '',
        'requires_php' => '7.4',
        'icons'        => [],
        'banners'      => [],
    ];
    if ($info && version_compare($info['version'], AMS_WREN_WEBSITE_VERSION, '>')) {
        $item->new_version = $info['version'];
        $item->package = $info['download_url'];
        $item->tested = isset($info['tested']) ? $info['tested'] : '';
        $transient->response[$file] = $item;
        unset($transient->no_update[$file]);
    } else {
        $transient->no_update[$file] = $item;
        unset($transient->response[$file]);
    }
    return $transient;
});

add_filter('plugins_api', function ($result, $action, $args) {
    if ($action !== 'plugin_information' || empty($args->slug) || $args->slug !== 'ams-wren-website') {
        return $result;
    }
    $info = ams_wren_website_remote_info();
    if (!$info) {
        return $result;
    }
    return (object) [
        'name'          => 'AMS Wren Website',
        'slug'          => 'ams-wren-website',
        'version'       => $info['version'],
        'author'        => 'Awaken Media Solutions',
        'homepage'      => AMS_WREN_WEBSITE_REPO,
        'requires'      => '6.0',
        'requires_php'  => '7.4',
        'last_updated'  => isset($info['released']) ? $info['released'] : '',
        'download_link' => $info['download_url'],
        'sections'      => [
            'description' => 'Site-side support for Wren Workspace (AMS AI Village).',
            'changelog'   => wp_kses_post(isset($info['changelog_html']) ? $info['changelog_html'] : ''),
        ],
    ];
}, 10, 3);

// "Check again" on Dashboard > Updates, and finished updates, re-read the update feed.
add_action('load-update-core.php', function () {
    delete_site_transient('ams_wren_website_update');
});
add_action('upgrader_process_complete', function () {
    delete_site_transient('ams_wren_website_update');
});

/** Fields Wren writes on pages. */
function ams_wren_website_fields() {
    return [
        '_elementor_data'          => 'string',
        '_elementor_edit_mode'     => 'string',
        '_elementor_template_type' => 'string',
        '_elementor_page_settings' => 'object',
        '_yoast_wpseo_title'       => 'string',
        '_yoast_wpseo_metadesc'    => 'string',
        '_yoast_wpseo_focuskw'     => 'string',
    ];
}

/** True when another plugin already exposes the field on pages over REST. */
function ams_wren_website_exposed($key) {
    foreach (['page', ''] as $subtype) {
        $keys = get_registered_meta_keys('post', $subtype);
        if (!empty($keys[$key]['show_in_rest'])) {
            return true;
        }
    }
    return false;
}

add_action('init', function () {
    $can_edit = function ($allowed, $meta_key, $post_id) {
        return current_user_can('edit_post', $post_id);
    };
    foreach (ams_wren_website_fields() as $key => $type) {
        if (ams_wren_website_exposed($key)) {
            continue; // e.g. Elementor fields already exposed by AMS AI Bridge.
        }
        // A page-specific registration takes precedence over a hidden all-post-types one
        // (Yoast registers its fields without REST access).
        register_post_meta('page', $key, [
            'type'          => $type,
            'single'        => true,
            'auth_callback' => $can_edit,
            'show_in_rest'  => $type === 'object'
                ? ['schema' => ['type' => 'object', 'additionalProperties' => true]]
                : true,
        ]);
    }
}, 99);

/**
 * Clear what would make visitors see an older version of a page:
 * Elementor's cached widget HTML and generated CSS for that page, and SiteGround's page cache.
 */
function ams_wren_website_refresh_page($post_id) {
    $post_id = (int) $post_id;
    if ($post_id <= 0) {
        return [];
    }
    $done = [];
    delete_post_meta($post_id, '_elementor_element_cache');
    delete_post_meta($post_id, '_elementor_css');
    $done[] = 'elementor-cache';
    if (class_exists('\Elementor\Core\Files\CSS\Post')) {
        try {
            $css = \Elementor\Core\Files\CSS\Post::create($post_id);
            if ($css) {
                $css->delete();
                $done[] = 'elementor-css-file';
            }
        } catch (\Throwable $e) {
            // Elementor rebuilds the file on the next visit anyway.
        }
    }
    $url = get_permalink($post_id);
    if ($url && function_exists('sg_cachepress_purge_cache')) {
        sg_cachepress_purge_cache($url);
        $done[] = 'siteground';
    }
    clean_post_cache($post_id);
    return $done;
}

/** After Wren (or anyone) saves a page over REST, refresh it. */
add_action('rest_after_insert_page', function ($post) {
    ams_wren_website_refresh_page($post->ID);
}, 20);

/** When a page goes live or comes down, make sure caches follow. */
add_action('transition_post_status', function ($new, $old, $post) {
    if ($post instanceof WP_Post && $post->post_type === 'page' && $new !== $old && ($new === 'publish' || $old === 'publish')) {
        ams_wren_website_refresh_page($post->ID);
    }
}, 20, 3);

add_action('rest_api_init', function () {
    register_rest_route('ams-wren/v1', '/status', [
        'methods'             => 'GET',
        'permission_callback' => function () {
            return current_user_can('edit_pages');
        },
        'callback'            => function () {
            $fields = [];
            foreach (array_keys(ams_wren_website_fields()) as $key) {
                $fields[$key] = ams_wren_website_exposed($key);
            }
            return [
                'plugin'     => 'ams-wren-website',
                'version'    => AMS_WREN_WEBSITE_VERSION,
                'fields'     => $fields,
                'elementor'  => defined('ELEMENTOR_VERSION') ? ELEMENTOR_VERSION : null,
                'yoast'      => defined('WPSEO_VERSION') ? WPSEO_VERSION : null,
                'siteground' => function_exists('sg_cachepress_purge_cache'),
                'updates'    => AMS_WREN_WEBSITE_UPDATE_URL,
                'latest'     => ($latest = ams_wren_website_remote_info()) ? $latest['version'] : null,
            ];
        },
    ]);

    // Manual refresh for one page (used after restore, or if a visitor still sees an old version).
    register_rest_route('ams-wren/v1', '/pages/(?P<id>\d+)/refresh', [
        'methods'             => 'POST',
        'permission_callback' => function ($request) {
            return current_user_can('edit_post', (int) $request['id']);
        },
        'callback'            => function ($request) {
            return ['id' => (int) $request['id'], 'refreshed' => ams_wren_website_refresh_page((int) $request['id'])];
        },
    ]);
});
