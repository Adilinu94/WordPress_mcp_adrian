<?php
// SPDX-FileCopyrightText: 2026 Ovation S.r.l. <dev@novamira.ai>
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/**
 * Skill Installer — installs the 8 adrianv2-* skills as novamira_skill CPT posts.
 *
 * Runs on plugin activation. Idempotent: checks if a skill with the same
 * post_name already exists before inserting. Does NOT delete skills on
 * deactivation (users may have customized them).
 *
 * Skills are stored in the novamira_skill CPT registered by the Novamira
 * Core plugin. Each skill SKILL.md is read from
 * includes/skills/<slug>/SKILL.md and inserted as post_content.
 *
 * @package Novamira_AdrianV2
 * @since   1.1.0
 */

namespace Novamira\AdrianV2\Skills;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Installs the 8 adrianv2-* skills on plugin activation.
 *
 * @since 1.1.0
 */
final class Installer {

    /**
     * Skill slugs in install order.
     *
     * @var string[]
     */
    private const SKILLS = [
        'adrianv2-v4-invariants',
        'adrianv2-v4-atomic-build',
        'adrianv2-v3-page-edit',
        'adrianv2-v3-to-v4-convert',
        'adrianv2-token-mapping',
        'adrianv2-discover-abilities-protocol',
        'adrianv2-self-audit',
        'adrianv2-rollback-build',
        'adrianv2-clonerlabs',
    ];

    /**
     * Skill title mapping (slug → human-readable title).
     *
     * @var array<string, string>
     */
    private const SKILL_TITLES = [
        'adrianv2-v4-invariants'              => 'V4 Atomic Invariants',
        'adrianv2-v4-atomic-build'            => 'V4 Atomic Page Build',
        'adrianv2-v3-page-edit'               => 'V3 Page Editing',
        'adrianv2-v3-to-v4-convert'           => 'V3 → V4 Conversion',
        'adrianv2-token-mapping'              => 'Token Mapping ($$type System)',
        'adrianv2-discover-abilities-protocol' => 'Discover Abilities Protocol',
        'adrianv2-self-audit'                 => 'Self-Audit (Plugin Health)',
        'adrianv2-rollback-build'             => 'Rollback Build',
    ];

    /**
     * Elementor version compatibility per skill (meta).
     *
     * @var array<string, string>
     */
    private const SKILL_ELEMENTOR_VERSIONS = [
        'adrianv2-v4-invariants'              => 'v4',
        'adrianv2-v4-atomic-build'            => 'v4',
        'adrianv2-v3-page-edit'               => 'v3',
        'adrianv2-v3-to-v4-convert'           => 'mixed',
        'adrianv2-token-mapping'              => 'v4',
        'adrianv2-discover-abilities-protocol' => 'mixed',
        'adrianv2-self-audit'                 => 'mixed',
        'adrianv2-rollback-build'             => 'v4',
    ];

    /**
     * Install all 8 skills. Idempotent — skips already-installed skills.
     *
     * @return array{ installed: string[], skipped: string[], errors: string[] }
     */
    public static function install(): array {
        $installed = [];
        $skipped   = [];
        $errors    = [];

        foreach (self::SKILLS as $slug) {
            $result = self::install_skill($slug);
            if ('installed' === $result) {
                $installed[] = $slug;
            } elseif ('skipped' === $result) {
                $skipped[] = $slug;
            } else {
                $errors[] = $slug . ': ' . $result;
            }
        }

        return [
            'installed' => $installed,
            'skipped'   => $skipped,
            'errors'    => $errors,
        ];
    }

    /**
     * Install a single skill.
     *
     * @param string $slug Skill directory slug.
     * @return string 'installed'|'skipped'|error message
     */
    private static function install_skill(string $slug): string {
        // 1. Check if already installed (by post_name).
        if (!function_exists('get_posts')) {
            return 'WordPress not fully loaded';
        }

        $existing = get_posts([
            'post_type'      => 'novamira_skill',
            'name'           => $slug,
            'posts_per_page' => 1,
            'post_status'    => 'any',
            'fields'         => 'ids',
        ]);

        if (!empty($existing)) {
            // Update existing skill content (non-destructive — only updates post_content).
            $skill_path = NOVAMIRA_ADRIANV2_DIR . '/includes/skills/' . $slug . '/SKILL.md';
            if (file_exists($skill_path)) {
                $content = file_get_contents($skill_path);
                if (false !== $content) {
                    wp_update_post(wp_slash([
                        'ID'           => $existing[0],
                        'post_content' => self::sanitize_skill_content($content),
                    ]));
                }
            }
            return 'skipped';
        }

        // 2. Read SKILL.md content.
        $skill_path = NOVAMIRA_ADRIANV2_DIR . '/includes/skills/' . $slug . '/SKILL.md';
        if (!file_exists($skill_path)) {
            return 'SKILL.md not found at ' . $skill_path;
        }

        $content = file_get_contents($skill_path);
        if (false === $content) {
            return 'Could not read SKILL.md for ' . $slug;
        }

        // 3. Insert as novamira_skill CPT post.
        $title   = self::SKILL_TITLES[$slug] ?? $slug;
        $post_id = wp_insert_post(wp_slash([
            'post_type'    => 'novamira_skill',
            'post_name'    => $slug,
            'post_title'   => $title,
            'post_content' => self::sanitize_skill_content($content),
            'post_status'  => 'publish',
            'meta_input'   => [
                '_novamira_skill_source'       => 'novamira-adrianv2',
                '_novamira_skill_elementor_ver' => self::SKILL_ELEMENTOR_VERSIONS[$slug] ?? 'mixed',
                '_novamira_skill_visibility'    => 'admin-only',
            ],
        ]));

        if (is_wp_error($post_id)) {
            return $post_id->get_error_message();
        }

        if (!$post_id) {
            return 'wp_insert_post returned 0 for ' . $slug;
        }

        return 'installed';
    }

    /**
     * Sanitize skill markdown content for safe database storage.
     *
     * Uses wp_kses_post to allow safe HTML/markdown while stripping
     * dangerous tags. Does NOT strip PHP code examples inside markdown
     * code blocks (they're harmless as stored content).
     *
     * @param string $content Raw markdown content.
     * @return string
     */
    private static function sanitize_skill_content(string $content): string {
        // Allow markdown/HTML through but strip dangerous tags.
        if (function_exists('wp_kses_post')) {
            return wp_kses_post($content);
        }
        return $content;
    }
}
