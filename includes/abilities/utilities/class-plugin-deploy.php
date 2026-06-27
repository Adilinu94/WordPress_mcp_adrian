<?php
declare(strict_types=1);

/**
 * Adrians - Plugin Deploy / Git Pull ability.
 * Name: novamira-adrianv2/plugin-deploy
 */

namespace Novamira\AdrianV2\Abilities\Utilities;

use Novamira\AdrianV2\Helpers;
use Novamira\AdrianV2\Helpers\Guards;

if (!defined('ABSPATH')) {
    exit();
}

class Plugin_Deploy
{
    const WEBHOOK_SECRET_OPTION = 'novamira_adrianv2_webhook_secret';

    public static function register(): void
    {
        wp_register_ability('novamira-adrianv2/plugin-deploy', [
            'label'               => 'Plugin Deploy (Git Pull)',
            'description'         => 'Führt git pull im Plugin-Verzeichnis aus, um das Plugin zu aktualisieren. dry_run:true (default) zeigt nur den Status an. Erfordert ein webhook_secret zur Autorisierung.',
            'category'            => 'adrianv2-utilities',
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'dry_run' => [
                        'type'        => 'boolean',
                        'description' => 'Wenn true (default), nur Status anzeigen ohne pull.',
                        'default'     => true,
                    ],
                    'webhook_secret' => [
                        'type'        => 'string',
                        'description' => 'Webhook-Secret zur Autorisierung. Wird beim ersten Aufruf generiert und gespeichert.',
                    ],
                ],
            ],
            'output_schema'       => [
                'type'       => 'object',
                'properties' => [
                    'success' => ['type' => 'boolean'],
                    'data'    => [
                        'type'       => 'object',
                        'properties' => [
                            'message' => ['type' => 'string'],
                            'output'  => ['type' => 'string'],
                            'git_log' => ['type' => 'string'],
                        ],
                    ],
                ],
            ],
            'execute_callback'    => [self::class, 'execute'],
            'permission_callback' => function () {
                return current_user_can('manage_options');
            },
            'meta'                => [
                'show_in_rest' => true,
                'mcp'          => ['public' => false],
                'annotations'  => [
                    'readonly'    => false,
                    'destructive' => true,
                    'idempotent'  => false,
                ],
            ],
        ]);
    }

    public static function execute($input = null)
    {
        $dry_run = isset($input['dry_run']) && true === $input['dry_run'];
        $secret  = isset($input['webhook_secret']) && is_string($input['webhook_secret'])
            ? $input['webhook_secret']
            : '';

        $stored_secret = get_option(self::WEBHOOK_SECRET_OPTION, '');

        // Generate a secret on first use if none exists.
        if (empty($stored_secret)) {
            $stored_secret = bin2hex(random_bytes(32));
            update_option(self::WEBHOOK_SECRET_OPTION, $stored_secret);
        }

        if (empty($secret)) {
            return [
                'success' => false,
                'data'    => [
                    'message' => 'webhook_secret ist erforderlich. Beim ersten Aufruf wurde ein Secret generiert – verwende dieses bei zukünftigen Aufrufen.',
                    'secret'  => $stored_secret,
                    'hint'    => 'Speichere dieses Secret als GitHub Webhook Secret.',
                ],
            ];
        }

        if (!hash_equals($stored_secret, $secret)) {
            return [
                'success' => false,
                'data'    => [
                    'message' => 'Ungültiges webhook_secret.',
                ],
            ];
        }

        $plugin_dir = self::get_plugin_dir();
        if (null === $plugin_dir) {
            return [
                'success' => false,
                'data'    => [
                    'message' => 'Konnte Plugin-Verzeichnis nicht ermitteln.',
                ],
            ];
        }

        if (!is_dir($plugin_dir . '/.git')) {
            return [
                'success' => false,
                'data'    => [
                    'message' => 'Plugin-Verzeichnis ist kein Git-Repository: ' . $plugin_dir,
                ],
            ];
        }

        $output = [];
        $exit_code = 0;

        if ($dry_run) {
            // Show current git status.
            exec('cd ' . escapeshellarg($plugin_dir) . ' && git status 2>&1', $output, $exit_code);
            $result = implode("\n", $output);

            exec('cd ' . escapeshellarg($plugin_dir) . ' && git log --oneline -5 2>&1', $output, $exit_code);
            $git_log = implode("\n", $output);

            return [
                'success' => true,
                'data'    => [
                    'message'    => 'Dry-Run: git status und letzte Commits.',
                    'output'     => $result,
                    'git_log'    => $git_log,
                    'has_updates' => self::has_remote_updates($plugin_dir),
                ],
            ];
        }

        // Run git pull.
        exec('cd ' . escapeshellarg($plugin_dir) . ' && git pull 2>&1', $output, $exit_code);

        return [
            'success' => 0 === $exit_code,
            'data'    => [
                'message' => 0 === $exit_code ? 'Git Pull erfolgreich.' : 'Git Pull fehlgeschlagen.',
                'output'  => implode("\n", $output),
            ],
        ];
    }

    public static function get_plugin_dir(): ?string
    {
        if (defined('NOVAMIRA_ADRIANV2_DIR')) {
            return rtrim(NOVAMIRA_ADRIANV2_DIR, '/\\');
        }
        return null;
    }

    private static function has_remote_updates(string $plugin_dir): bool
    {
        exec('cd ' . escapeshellarg($plugin_dir) . ' && git fetch --dry-run 2>&1', $fetch_output, $fetch_code);
        if (0 === $fetch_code && !empty($fetch_output)) {
            return true;
        }
        exec('cd ' . escapeshellarg($plugin_dir) . ' && git rev-list HEAD..origin/master --count 2>&1', $count_output, $count_code);
        if (0 === $count_code && isset($count_output[0]) && (int)$count_output[0] > 0) {
            return true;
        }
        return false;
    }
}

add_action('wp_abilities_api_init', [Plugin_Deploy::class, 'register']);

// ─── REST-API: Deploy Webhook für GitHub ───────────────────────────────────
// POST /wp-json/novamira/v1/deploy-webhook
add_action(
    'rest_api_init',
    function () {
        register_rest_route(
            'novamira/v1',
            '/deploy-webhook',
            [
                'methods'             => 'POST',
                'callback'            => function (\WP_REST_Request $request) {
                    $headers = $request->get_headers();
                    $body = $request->get_body();

                    $stored_secret = get_option(Plugin_Deploy::WEBHOOK_SECRET_OPTION, '');
                    if (empty($stored_secret)) {
                        return new \WP_REST_Response([
                            'success' => false,
                            'message' => 'Kein Webhook-Secret konfiguriert. Rufe zuerst die plugin-deploy Ability auf.',
                        ], 403);
                    }

                    // Verify GitHub HMAC signature.
                    $sig_header = $headers['x_hub_signature_256'][0] ?? '';
                    if (empty($sig_header)) {
                        return new \WP_REST_Response([
                            'success' => false,
                            'message' => 'Fehlende X-Hub-Signature-256.',
                        ], 403);
                    }

                    $expected = 'sha256=' . hash_hmac('sha256', $body, $stored_secret);
                    $actual = explode('=', $sig_header, 2)[1] ?? '';

                    if (!hash_equals($expected, $actual)) {
                        return new \WP_REST_Response([
                            'success' => false,
                            'message' => 'Ungültige Signatur.',
                        ], 403);
                    }

                    // Run git pull.
                    $plugin_dir = Plugin_Deploy::get_plugin_dir();
                    if (null === $plugin_dir || !is_dir($plugin_dir . '/.git')) {
                        return new \WP_REST_Response([
                            'success' => false,
                            'message' => 'Plugin ist kein Git-Repository.',
                        ], 500);
                    }

                    $output = [];
                    $exit_code = 0;
                    exec('cd ' . escapeshellarg($plugin_dir) . ' && git pull 2>&1', $output, $exit_code);

                    $event = $request->get_header('X-GitHub-Event') ?: 'unknown';

                    return new \WP_REST_Response([
                        'success'  => 0 === $exit_code,
                        'event'    => $event,
                        'message'  => 0 === $exit_code ? 'Deploy erfolgreich.' : 'Git Pull fehlgeschlagen.',
                        'output'   => implode("\n", $output),
                    ], 0 === $exit_code ? 200 : 500);
                },
                'permission_callback' => '__return_true',
            ]
        );
    }
);
