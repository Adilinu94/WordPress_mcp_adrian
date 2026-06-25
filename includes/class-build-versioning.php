<?php
declare(strict_types=1);
/**
 * CPT — elementor_build (Plan 4.2: Build-Versioning in WordPress)
 *
 * Registriert einen Custom Post Type für Build-Versionierung:
 *   - Build-Hash, Git-Commit, Designer, Timestamp
 *   - Snapshot der gesetzten Elementor-Daten
 *   - Approval-State (draft/published/rolled-back)
 *
 * @since 1.1.0
 */

namespace Novamira\AdrianV2;

if (!defined('ABSPATH')) exit();

class Build_Versioning {
    public static function register(): void {
        add_action('init', [self::class, 'register_cpt'], 5);
        add_action('add_meta_boxes', [self::class, 'add_meta_boxes']);
        add_action('save_post_elementor_build', [self::class, 'save_meta']);
    }

    public static function register_cpt(): void {
        register_post_type('elementor_build', [
            'label'        => 'Builds',
            'public'       => false,
            'show_ui'      => true,
            'show_in_menu' => 'elementor',
            'supports'     => ['title'],
            'menu_icon'    => 'dashicons-hammer',
            'labels'       => [
                'name'          => 'Builds',
                'singular_name' => 'Build',
                'add_new_item'  => 'Neuer Build',
            ],
        ]);
    }

    public static function add_meta_boxes(): void {
        add_meta_box('build_details', 'Build Details', function ($post) {
            $commit  = get_post_meta($post->ID, '_build_git_commit', true);
            $designer = get_post_meta($post->ID, '_build_designer', true);
            $target  = get_post_meta($post->ID, '_build_target_post', true);
            $state   = get_post_meta($post->ID, '_build_state', true) ?: 'completed';
            echo '<p><strong>Git Commit:</strong> ' . esc_html($commit ?: 'N/A') . '</p>';
            echo '<p><strong>Designer:</strong> ' . esc_html($designer ?: 'N/A') . '</p>';
            echo '<p><strong>Target Post ID:</strong> ' . esc_html($target ?: 'N/A') . '</p>';
            echo '<p><strong>State:</strong> ' . esc_html($state) . '</p>';
        }, 'elementor_build', 'side');
    }

    public static function save_meta($post_id): void {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        foreach (['_build_git_commit', '_build_designer', '_build_target_post', '_build_state', '_build_pipeline_version'] as $key) {
            if (isset($_POST[$key])) update_post_meta($post_id, $key, sanitize_text_field($_POST[$key]));
        }
    }

    public static function create_build(array $data): int {
        $post_id = wp_insert_post([
            'post_type'   => 'elementor_build',
            'post_title'  => sprintf('Build %s — Post %d', $data['timestamp'] ?? date('Y-m-d H:i'), $data['target_post'] ?? 0),
            'post_status' => 'publish',
        ]);
        if ($post_id && !is_wp_error($post_id)) {
            update_post_meta($post_id, '_build_git_commit', $data['git_commit'] ?? '');
            update_post_meta($post_id, '_build_designer', $data['designer'] ?? '');
            update_post_meta($post_id, '_build_target_post', $data['target_post'] ?? 0);
            update_post_meta($post_id, '_build_state', $data['state'] ?? 'completed');
            if (!empty($data['pipeline_version'])) {
                update_post_meta($post_id, '_build_pipeline_version', $data['pipeline_version']);
            }
        }
        return is_wp_error($post_id) ? 0 : $post_id;
    }
}

add_action('plugins_loaded', [Build_Versioning::class, 'register']);
