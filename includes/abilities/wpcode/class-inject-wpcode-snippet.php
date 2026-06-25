<?php
declare(strict_types=1);

namespace Novamira\AdrianV2\Abilities\WpCode;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Ability: novamira-adrianv2/inject-wpcode-snippet
 *
 * Typed, idempotent replacement for the raw novamira/execute-php calls that
 * site-clone-to-v3's wpcode-adapter uses to manage WPCode snippets.
 *
 * Modes:
 *   - detect   — find WPCode CPT slug + count snippets (read-only)
 *   - create   — create a new snippet post with meta
 *   - activate — set _wpcode_auto_insert to 1 on an existing snippet
 *   - delete   — hard-delete a snippet post
 *
 * Maps exactly to the PHP strings that wpcode-adapter.ts builds and
 * passes to execute-php. No raw PHP strings needed anymore.
 *
 * @since 1.7.1
 */
class Inject_Wpcode_Snippet {

	/** Known WPCode CPT slugs in historical order. */
	const KNOWN_SLUGS = [ 'wpcode', 'wpcode_snippet', 'wpcode-snippets', 'wpcodes' ];

	public static function register(): void {
		wp_register_ability(
			'novamira-adrianv2/inject-wpcode-snippet',
			[
				'label'       => 'Inject WPCode Snippet',
				'description' => 'Typed replacement for raw execute-php WPCode calls in site-clone-to-v3. Modes: detect (find WPCode CPT slug + count), create (new snippet with code, type, location, priority), activate (enable auto-insert on existing snippet), delete (hard-delete snippet). Returns snippet post ID for create mode so the caller can reference or activate it later.',
				'category'    => 'adrianv2-wpcode',
				'input_schema' => [
					'type'       => 'object',
					'required'   => [ 'mode' ],
					'properties' => [
						'mode' => [
							'type'        => 'string',
							'enum'        => [ 'detect', 'create', 'activate', 'delete' ],
							'description' => 'detect — read WPCode status; create — insert new snippet; activate — enable snippet; delete — remove snippet.',
						],
						'title' => [
							'type'        => 'string',
							'description' => 'Snippet title (create mode, required).',
						],
						'code' => [
							'type'        => 'string',
							'description' => 'CSS or JS snippet content (create mode, required).',
						],
						'type' => [
							'type'        => 'string',
							'default'     => 'css',
							'description' => 'Snippet type: css or js (create mode).',
						],
						'location' => [
							'type'        => 'string',
							'default'     => 'head',
							'description' => 'Where to inject: head, footer, body_open (create mode).',
						],
						'auto_insert' => [
							'type'        => 'boolean',
							'default'     => true,
							'description' => 'Enable snippet immediately on creation.',
						],
						'priority' => [
							'type'        => 'integer',
							'default'     => 10,
							'description' => 'Hook priority (create mode).',
						],
						'note' => [
							'type'        => 'string',
							'default'     => '',
							'description' => 'Optional internal note.',
						],
						'snippet_id' => [
							'type'        => 'integer',
							'description' => 'WPCode snippet post ID (activate and delete modes).',
						],
					],
				],
				'output_schema' => [
					'type'       => 'object',
					'properties' => [
						'success'    => [ 'type' => 'boolean' ],
						'mode'       => [ 'type' => 'string' ],
						'slug'       => [ 'type' => 'string',  'description' => 'WPCode CPT slug found.' ],
						'count'      => [ 'type' => 'integer', 'description' => 'detect: existing snippet count.' ],
						'id'         => [ 'type' => 'integer', 'description' => 'create: new snippet post ID.' ],
						'activated'  => [ 'type' => 'boolean' ],
						'deleted'    => [ 'type' => 'boolean' ],
						'error'      => [ 'type' => 'string' ],
					],
				],
				'execute_callback'    => [ self::class, 'execute' ],
				'permission_callback' => 'novamira_permission_callback',
				'meta'                => [
					'show_in_rest' => true,
					'mcp'          => [ 'public' => true ],
					'annotations'  => [
						'readonly'    => false,
						'destructive' => false,
					],
				],
			]
		);
	}

	public static function execute( ?array $input ): array {
		$mode       = trim( (string) ( $input['mode'] ?? 'detect' ) );

		switch ( $mode ) {
			case 'detect':
				return self::detect();

			case 'create':
				$title       = trim( (string) ( $input['title']    ?? '' ) );
				$code        = (string) ( $input['code']       ?? '' );
				$type        = trim( (string) ( $input['type']       ?? 'css' ) );
				$location    = trim( (string) ( $input['location']   ?? 'head' ) );
				$auto_insert = (bool) ( $input['auto_insert'] ?? true );
				$priority    = (int)  ( $input['priority']    ?? 10 );
				$note        = (string) ( $input['note']       ?? '' );

				if ( $title === '' ) {
					return [ 'success' => false, 'error' => 'title is required for create mode.' ];
				}
				if ( $code === '' ) {
					return [ 'success' => false, 'error' => 'code is required for create mode.' ];
				}
				return self::create( $title, $code, $type, $location, $auto_insert, $priority, $note );

			case 'activate':
				$snippet_id = (int) ( $input['snippet_id'] ?? 0 );
				if ( $snippet_id <= 0 ) {
					return [ 'success' => false, 'error' => 'snippet_id is required for activate mode.' ];
				}
				return self::activate( $snippet_id );

			case 'delete':
				$snippet_id = (int) ( $input['snippet_id'] ?? 0 );
				if ( $snippet_id <= 0 ) {
					return [ 'success' => false, 'error' => 'snippet_id is required for delete mode.' ];
				}
				return self::delete_snippet( $snippet_id );

			default:
				return [ 'success' => false, 'error' => "Unknown mode '{$mode}'." ];
		}
	}

	// -------------------------------------------------------------------------

	/**
	 * Detect WPCode CPT slug and return snippet count.
	 */
	private static function detect(): array {
		$slug = self::find_slug();
		if ( ! $slug ) {
			return [
				'success' => true,
				'mode'    => 'detect',
				'slug'    => null,
				'count'   => 0,
				'note'    => 'WPCode plugin not found or CPT not registered.',
			];
		}

		$count = (int) wp_count_posts( $slug )->publish ?? 0;

		return [
			'success' => true,
			'mode'    => 'detect',
			'slug'    => $slug,
			'count'   => $count,
			'version' => defined( 'WPCODE_VERSION' ) ? WPCODE_VERSION : null,
		];
	}

	/**
	 * Create a WPCode snippet post with the given meta.
	 */
	private static function create(
		string $title,
		string $code,
		string $type,
		string $location,
		bool $auto_insert,
		int $priority,
		string $note
	): array {
		$slug = self::find_slug();
		if ( ! $slug ) {
			return [ 'success' => false, 'error' => 'WPCode plugin not found — cannot create snippet.' ];
		}

		$post_id = wp_insert_post( [
			'post_type'   => $slug,
			'post_status' => 'draft',
			'post_title'  => $title,
		], true );

		if ( is_wp_error( $post_id ) ) {
			return [ 'success' => false, 'error' => 'wp_insert_post failed: ' . $post_id->get_error_message() ];
		}

		update_post_meta( $post_id, '_wpcode_code',         $code );
		update_post_meta( $post_id, '_wpcode_type',         $type );
		update_post_meta( $post_id, '_wpcode_location',     $location );
		update_post_meta( $post_id, '_wpcode_auto_insert',  $auto_insert ? '1' : '0' );
		update_post_meta( $post_id, '_wpcode_priority',     (string) $priority );

		if ( $note !== '' ) {
			update_post_meta( $post_id, '_wpcode_note', $note );
		}

		return [
			'success' => true,
			'mode'    => 'create',
			'id'      => $post_id,
			'slug'    => $slug,
		];
	}

	/**
	 * Activate auto-insert on an existing WPCode snippet.
	 */
	private static function activate( int $snippet_id ): array {
		update_post_meta( $snippet_id, '_wpcode_auto_insert', '1' );
		return [
			'success'   => true,
			'mode'      => 'activate',
			'id'        => $snippet_id,
			'activated' => true,
		];
	}

	/**
	 * Hard-delete a WPCode snippet post.
	 */
	private static function delete_snippet( int $snippet_id ): array {
		$result = wp_delete_post( $snippet_id, true );
		return [
			'success' => $result !== false,
			'mode'    => 'delete',
			'id'      => $snippet_id,
			'deleted' => $result !== false,
		];
	}

	/**
	 * Find the active WPCode CPT slug.
	 *
	 * @return string|null
	 */
	public static function find_slug(): ?string {
		foreach ( self::KNOWN_SLUGS as $slug ) {
			if ( post_type_exists( $slug ) ) {
				return $slug;
			}
		}
		return null;
	}
}
