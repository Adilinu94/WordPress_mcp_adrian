<?php
declare(strict_types=1);

/**
 * WPCode Snippet Abilities.
 *
 * Allows a remote MCP client (Novamira / @automattic/mcp-wordpress-remote) to
 * create, read, update, list, duplicate, delete, and toggle the active state
 * of WPCode snippets (HTML, CSS, JS, PHP, universal, text, blocks, scss)
 * directly over JSON-RPC — without WP-CLI access.
 *
 * Provides 7 abilities (mirrors PHP_Snippets parity + status toggle + duplicate):
 *   - list-wpcode-snippets        (read)
 *   - get-wpcode-snippet          (read)
 *   - create-wpcode-snippet       (write — inactive draft by default)
 *   - update-wpcode-snippet       (write)
 *   - set-wpcode-snippet-status   (write — toggle active; tightens permission)
 *   - duplicate-wpcode-snippet    (write)
 *   - delete-wpcode-snippet       (write, destructive)
 *
 * Permission model:
 *   - Read:                manage_options
 *   - Write (create,update,duplicate,delete): manage_options + unfiltered_html
 *   - Status (set-status): the write above + wpcode_activate_snippets (so
 *                           less-privileged automation that can edit code
 *                           cannot flip activation state on its own).
 *
 * All writes go through WPCode's own WPCode_Snippet class so the following
 * plugin-internal contracts are honoured transparently:
 *   - wpcode_block_unauthorized_snippet_writes cap filter
 *   - wpcode_maybe_remove_core_content_filters during save (preserves raw PHP)
 *   - WPCode_Snippet::save() → run_activation_checks() auto-demotes bad PHP
 *     to draft and surfaces _wpcode_last_error (we surface that back to MCP).
 *   - WPCode_Snippet::save() → rebuild_cache() so the snippet is picked up
 *     by the front-end execution queue on the next request.
 *   - wp_set_post_terms on wpcode_type / wpcode_location / wpcode_tags.
 *   - All _wpcode_* post meta keys via property setters.
 *
 * @package novamira-adrianv2
 * @since   1.1.0
 */

namespace Novamira\AdrianV2\Abilities\WpCode;

use Novamira\AdrianV2\Helpers\Ability_Registry;
use Novamira\AdrianV2\Helpers\WPCode_Kses_Bypass;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and implements the WPCode snippet abilities.
 *
 * @since 1.1.0
 */
class WpCode_Snippets {
	use Ability_Registry;

	/** @var string[] */
	private static array $ability_names = array();

	/**
	 * Whether the WPCode plugin is active.
	 *
	 * If false, all 7 abilities silently skip registration, and any execute
	 * callback that somehow fires returns a clear WP_Error.
	 *
	 * @return bool
	 */
	public static function is_available(): bool {
		return class_exists( 'WPCode_Snippet' );
	}

	// -------------------------------------------------------------------------
	// Registration
	// -------------------------------------------------------------------------

	public static function register(): void {
		if ( ! self::is_available() ) {
			return;
		}
		self::register_list();
		self::register_get();
		self::register_create();
		self::register_update();
		self::register_set_status();
		self::register_duplicate();
		self::register_delete();
	}

	// -------------------------------------------------------------------------
	// Permission callbacks
	// -------------------------------------------------------------------------

	public static function check_read_permission(): bool {
		return current_user_can( 'manage_options' );
	}

	public static function check_write_permission(): bool {
		return current_user_can( 'manage_options' ) && current_user_can( 'unfiltered_html' );
	}

	public static function check_status_permission(): bool {
		return self::check_write_permission() && current_user_can( 'wpcode_activate_snippets' );
	}

	// -------------------------------------------------------------------------
	// Schema helpers
	// -------------------------------------------------------------------------

	private static function code_type_enum(): array {
		return array( 'php', 'universal', 'css', 'html', 'js', 'text', 'blocks', 'scss' );
	}

	private static function device_type_enum(): array {
		return array( 'any', 'desktop', 'mobile' );
	}

	/**
	 * Properties shared by create and update input_schemas.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private static function write_input_props(): array {
		return array(
			'title'            => array(
				'type'        => 'string',
				'description' => __( 'Snippet title — stored as the post_title.', 'novamira-adrianv2' ),
			),
			'code'             => array(
				'type'        => 'string',
				'description' => __( 'The snippet code. For "php" type, do not include opening <?php — WPCode wraps execution. For "html"/"js", keep or strip tags as you wish; content_save_pre filters are removed during save to preserve raw bytes.', 'novamira-adrianv2' ),
			),
			'code_type'        => array(
				'type'        => 'string',
				'enum'        => self::code_type_enum(),
				'description' => __( 'The code language slug: php, universal, css, html, js, text, blocks, scss.', 'novamira-adrianv2' ),
			),
			'location'         => array(
				'type'        => 'string',
				'description' => __( 'WPCode location slug (e.g. "site_wide_header", "site_wide_body", "site_footer"). Stored as `wpcode_location` term; only meaningful when `auto_insert=true`.', 'novamira-adrianv2' ),
			),
			'auto_insert'      => array(
				'type'        => 'boolean',
				'description' => __( 'If true, auto-insert via the `wpcode_location` taxonomy. If false, treat as a shortcode snippet.', 'novamira-adrianv2' ),
			),
			'insert_number'    => array(
				'type'        => 'integer',
				'description' => __( 'Auto-insert position number (e.g. paragraph index). Used when `auto_insert=true`. Default: 1.', 'novamira-adrianv2' ),
			),
			'tags'             => array(
				'type'        => 'array',
				'items'       => array( 'type' => 'string' ),
				'description' => __( 'Array of tag slugs (stored as `wpcode_tags` terms).', 'novamira-adrianv2' ),
			),
			'priority'         => array(
				'type'        => 'integer',
				'description' => __( 'Loading priority (1-99). Lower = earlier. Default: 10.', 'novamira-adrianv2' ),
			),
			'device_type'      => array(
				'type'        => 'string',
				'enum'        => self::device_type_enum(),
				'description' => __( 'any | desktop | mobile. Default: any.', 'novamira-adrianv2' ),
			),
			'schedule'         => array(
				'type'        => 'object',
				'properties'  => array(
					'start' => array(
						'type'        => 'string',
						'description' => __( 'ISO 8601 start (or empty).', 'novamira-adrianv2' ),
					),
					'end'   => array(
						'type'        => 'string',
						'description' => __( 'ISO 8601 end (or empty).', 'novamira-adrianv2' ),
					),
				),
				'description' => __( 'Optional schedule window. Empty start/end mean no bound.', 'novamira-adrianv2' ),
			),
			'use_rules'        => array(
				'type'        => 'boolean',
				'description' => __( 'Enable conditional logic rules. Default: false.', 'novamira-adrianv2' ),
			),
			'rules'            => array(
				'type'        => 'array',
				'description' => __( 'Conditional logic rules array — stored verbatim as `_wpcode_conditional_logic`.', 'novamira-adrianv2' ),
			),
			'custom_shortcode' => array(
				'type'        => 'string',
				'description' => __( 'Optional custom shortcode tag. Empty means WPCode uses its auto-generated tag.', 'novamira-adrianv2' ),
			),
			'compress_output'  => array(
				'type'        => 'boolean',
				'description' => __( 'Compress CSS/HTML/JS output. Default: false.', 'novamira-adrianv2' ),
			),
			'active'           => array(
				'type'        => 'boolean',
				'description' => __( 'Whether the snippet should be active on save. If true, WPCode runs activation checks and may auto-demote to draft on PHP errors — see `last_error`. Default: false.', 'novamira-adrianv2' ),
			),
			'cache_bust_token' => array(
				'type'        => 'string',
				'description' => __( 'When provided alongside `code`, appends ?v={token} to all CDN URLs (jsdelivr, unpkg, cdnjs) in the snippet that do not already carry a ?v= query string. Use to force browser cache invalidation after a versioned deploy. Pass the build ID or semver.', 'novamira-adrianv2' ),
			),
		);
	}

	/**
	 * Output schema for a single snippet record (used by get, create, update, duplicate).
	 *
	 * @return array<string,mixed>
	 */
	private static function snippet_record_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'snippet_id'       => array( 'type' => 'integer' ),
				'title'            => array( 'type' => 'string' ),
				'code'             => array( 'type' => 'string' ),
				'code_type'        => array( 'type' => 'string' ),
				'location'         => array( 'type' => 'string' ),
				'auto_insert'      => array( 'type' => 'boolean' ),
				'insert_number'    => array( 'type' => 'integer' ),
				'active'           => array( 'type' => 'boolean' ),
				'status'           => array(
					'type'        => 'string',
					'description' => __( 'WPCode post_status: publish (active) or draft (inactive).', 'novamira-adrianv2' ),
				),
				'tags'             => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
				'priority'         => array( 'type' => 'integer' ),
				'device_type'      => array( 'type' => 'string' ),
				'schedule'         => array( 'type' => 'object' ),
				'use_rules'        => array( 'type' => 'boolean' ),
				'rules'            => array( 'type' => 'array' ),
				'custom_shortcode' => array( 'type' => 'string' ),
				'compress_output'  => array( 'type' => 'boolean' ),
				'last_error'       => array(
					'type'        => array( 'object', 'null' ),
					'description' => __( 'WPCode _wpcode_last_error object — populated only if activation checks failed.', 'novamira-adrianv2' ),
				),
				'edit_url'         => array( 'type' => 'string' ),
			),
		);
	}

	/**
	 * Convert a WPCode_Snippet instance into the standard MCP record shape.
	 *
	 * @param \WPCode_Snippet $snippet Snippet.
	 * @return array<string,mixed>
	 */
	private static function snippet_to_record( $snippet ): array {
		return array(
			'snippet_id'       => $snippet->get_id(),
			'title'            => $snippet->get_title(),
			'code'             => $snippet->get_code(),
			'code_type'        => $snippet->get_code_type(),
			'location'         => $snippet->get_location(),
			'auto_insert'      => (bool) $snippet->get_auto_insert(),
			'insert_number'    => (int) $snippet->get_auto_insert_number(),
			'active'           => (bool) $snippet->is_active(),
			'status'           => $snippet->is_active() ? 'publish' : 'draft',
			'tags'             => (array) $snippet->get_tags(),
			'priority'         => (int) $snippet->get_priority(),
			'device_type'      => (string) $snippet->get_device_type(),
			'schedule'         => (array) $snippet->get_schedule(),
			'use_rules'        => (bool) $snippet->conditional_rules_enabled(),
			'rules'            => (array) $snippet->get_conditional_rules(),
			'custom_shortcode' => (string) $snippet->get_custom_shortcode(),
			'compress_output'  => (bool) $snippet->maybe_compress_output(),
			'last_error'       => $snippet->get_last_error() ?: null,
			'edit_url'         => $snippet->get_edit_url(),
		);
	}

	/**
	 * Apply a write-input array onto an existing WPCode_Snippet instance.
	 *
	 * Only keys present in $input are touched, so update can patch a subset.
	 *
	 * @param \WPCode_Snippet     $snippet Snippet.
	 * @param array<string,mixed> $input Input.
	 * @return void
	 */
	private static function apply_input_to_snippet( $snippet, array $input ): void {
		if ( isset( $input['title'] ) ) {
			$snippet->title = (string) $input['title'];
		}
		if ( array_key_exists( 'code', $input ) ) {
			$code = (string) $input['code'];
			if ( ! empty( $input['cache_bust_token'] ) ) {
				$token = preg_replace( '/[^a-zA-Z0-9._-]/', '', (string) $input['cache_bust_token'] );
				// Append ?v={token} to CDN URLs that don't already have a ?v= param.
				$code = preg_replace_callback(
					'~(https://(?:cdn\\.jsdelivr\\.net|unpkg\\.com|cdnjs\\.cloudflare\\.com)/[^\\s'"<>]+)~',
					static function ( array $m ) use ( $token ): string {
						$url = $m[1];
						if ( str_contains( $url, '?v=' ) || str_contains( $url, '&v=' ) ) {
							return $url;
						}
						return $url . ( str_contains( $url, '?' ) ? '&' : '?' ) . 'v=' . $token;
					},
					$code
				);
			}
			$snippet->code = $code;
		}
		if ( isset( $input['code_type'] ) ) {
			$snippet->code_type = (string) $input['code_type'];
		}
		if ( isset( $input['location'] ) ) {
			$snippet->location = (string) $input['location'];
		}
		if ( isset( $input['auto_insert'] ) ) {
			$snippet->auto_insert = $input['auto_insert'] ? 1 : 0;
		}
		if ( isset( $input['insert_number'] ) ) {
			$snippet->insert_number = absint( $input['insert_number'] );
		}
		if ( isset( $input['tags'] ) ) {
			$snippet->tags = array_map( 'strval', (array) $input['tags'] );
		}
		if ( isset( $input['priority'] ) ) {
			$snippet->priority = absint( $input['priority'] );
		}
		if ( isset( $input['device_type'] ) ) {
			$snippet->device_type = (string) $input['device_type'];
		}
		if ( isset( $input['schedule'] ) && is_array( $input['schedule'] ) ) {
			$snippet->schedule = array(
				'start' => isset( $input['schedule']['start'] ) ? (string) $input['schedule']['start'] : '',
				'end'   => isset( $input['schedule']['end'] ) ? (string) $input['schedule']['end'] : '',
			);
		}
		if ( isset( $input['use_rules'] ) ) {
			$snippet->use_rules = (bool) $input['use_rules'];
		}
		if ( isset( $input['rules'] ) ) {
			$snippet->rules = (array) $input['rules'];
		}
		if ( array_key_exists( 'custom_shortcode', $input ) ) {
			$snippet->custom_shortcode = (string) $input['custom_shortcode'];
		}
		if ( isset( $input['compress_output'] ) ) {
			$snippet->compress_output = (bool) $input['compress_output'];
		}
		if ( isset( $input['active'] ) ) {
			$snippet->active = (bool) $input['active'];
		}
	}

	/**
	 * Add the final active/status/last_error fields to an MCP response after a save.
	 *
	 * @param \WPCode_Snippet     $snippet Snippet post-save.
	 * @param array<string,mixed> $base Re-shaped on top of the snippet record.
	 * @return array<string,mixed>
	 */
	private static function finalize_response( $snippet, array $base ): array {
		$base['active']     = (bool) $snippet->is_active();
		$base['status']     = $snippet->is_active() ? 'publish' : 'draft';
		$base['last_error'] = $snippet->get_last_error() ?: null;
		return $base;
	}

	// -------------------------------------------------------------------------
	// list-wpcode-snippets
	// -------------------------------------------------------------------------

	private static function register_list(): void {
		$name                  = 'novamira-adrianv2/list-wpcode-snippets';
		self::$ability_names[] = $name;

		wp_register_ability(
			$name,
			array(
				'label'               => __( 'List WPCode Snippets', 'novamira-adrianv2' ),
				'description'         => __( 'Lists WPCode snippets (HTML/CSS/JS/PHP/universal/text/blocks/scss) with their id, title, code_type, location, active status, and tags. Filterable by status, code_type and location slug.', 'novamira-adrianv2' ),
				'category'            => 'adrianv2-wpcode',
				'execute_callback'    => array( __CLASS__, 'execute_list' ),
				'permission_callback' => 'novamira_permission_callback',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'status'    => array(
							'type'        => 'string',
							'enum'        => array( 'publish', 'draft', 'any' ),
							'description' => __( 'Filter by post_status. Default: any.', 'novamira-adrianv2' ),
						),
						'code_type' => array(
							'type'        => 'string',
							'enum'        => self::code_type_enum(),
							'description' => __( 'Filter by wpcode_type slug.', 'novamira-adrianv2' ),
						),
						'location'  => array(
							'type'        => 'string',
							'description' => __( 'Filter by wpcode_location slug.', 'novamira-adrianv2' ),
						),
						'per_page'  => array(
							'type'        => 'integer',
							'description' => __( 'Max results (1-200). Default: 50.', 'novamira-adrianv2' ),
						),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'count'    => array( 'type' => 'integer' ),
						'snippets' => array(
							'type'  => 'array',
							'items' => array(
								'type'       => 'object',
								'properties' => array(
									'snippet_id' => array( 'type' => 'integer' ),
									'title'      => array( 'type' => 'string' ),
									'code_type'  => array( 'type' => 'string' ),
									'location'   => array( 'type' => 'string' ),
									'active'     => array( 'type' => 'boolean' ),
									'status'     => array( 'type' => 'string' ),
									'tags'       => array(
										'type'  => 'array',
										'items' => array( 'type' => 'string' ),
									),
									'edit_url'   => array( 'type' => 'string' ),
								),
							),
						),
					),
				),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					),
					'show_in_rest' => true,
					'mcp'          => array( 'public' => true ),
				),
			)
		);
	}

	public static function execute_list( $input ) {
		$input     = is_array( $input ) ? $input : array();
		$status    = sanitize_key( $input['status'] ?? 'any' );
		$code_type = sanitize_key( $input['code_type'] ?? '' );
		$location  = sanitize_key( $input['location'] ?? '' );
		$per_page  = max( 1, min( 200, (int) ( $input['per_page'] ?? 50 ) ) );

		$args = array(
			'post_type'      => 'wpcode',
			'posts_per_page' => $per_page,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'no_found_rows'  => true,
		);

		if ( 'publish' === $status || 'draft' === $status ) {
			$args['post_status'] = $status;
		} else {
			$args['post_status'] = array( 'publish', 'draft' );
		}

		$tax_query = array();
		if ( '' !== $code_type ) {
			$tax_query[] = array(
				'taxonomy' => 'wpcode_type',
				'field'    => 'slug',
				'terms'    => $code_type,
			);
		}
		if ( '' !== $location ) {
			$tax_query[] = array(
				'taxonomy' => 'wpcode_location',
				'field'    => 'slug',
				'terms'    => $location,
			);
		}
		if ( ! empty( $tax_query ) ) {
			$args['tax_query'] = array_merge( array( 'relation' => 'AND' ), $tax_query ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
		}

		$posts    = get_posts( $args );
		$snippets = array();
		foreach ( $posts as $post ) {
			$snippet    = new \WPCode_Snippet( $post );
			$snippets[] = array(
				'snippet_id' => $snippet->get_id(),
				'title'      => $snippet->get_title(),
				'code_type'  => $snippet->get_code_type(),
				'location'   => $snippet->get_location(),
				'active'     => $snippet->is_active(),
				'status'     => $post->post_status,
				'tags'       => (array) $snippet->get_tags(),
				'edit_url'   => $snippet->get_edit_url(),
			);
		}

		return array(
			'count'    => count( $snippets ),
			'snippets' => $snippets,
		);
	}

	// -------------------------------------------------------------------------
	// get-wpcode-snippet
	// -------------------------------------------------------------------------

	private static function register_get(): void {
		$name                  = 'novamira-adrianv2/get-wpcode-snippet';
		self::$ability_names[] = $name;

		wp_register_ability(
			$name,
			array(
				'label'               => __( 'Get WPCode Snippet', 'novamira-adrianv2' ),
				'description'         => __( 'Returns the full record for a WPCode snippet: code, status, location, tags, schedule, conditional rules, last_error, and the admin edit URL.', 'novamira-adrianv2' ),
				'category'            => 'adrianv2-wpcode',
				'execute_callback'    => array( __CLASS__, 'execute_get' ),
				'permission_callback' => 'novamira_permission_callback',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'snippet_id' => array(
							'type'        => 'integer',
							'description' => __( 'The snippet id.', 'novamira-adrianv2' ),
						),
					),
					'required'   => array( 'snippet_id' ),
				),
				'output_schema'       => self::snippet_record_schema(),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					),
					'show_in_rest' => true,
					'mcp'          => array( 'public' => true ),
				),
			)
		);
	}

	public static function execute_get( $input ) {
		$id = isset( $input['snippet_id'] ) ? absint( $input['snippet_id'] ) : 0;
		if ( ! $id ) {
			return new \WP_Error( 'wpcode_missing_id', __( 'snippet_id is required.', 'novamira-adrianv2' ) );
		}
		$snippet = new \WPCode_Snippet( $id );
		if ( ! $snippet->get_id() || ! $snippet->get_post_data() || ( isset( $snippet->post_data->post_type ) && 'wpcode' !== $snippet->post_data->post_type ) ) {
			return new \WP_Error(
				'wpcode_snippet_not_found',
				sprintf(
					/* translators: %d: snippet post id */
					__( 'Snippet #%d not found or is not a WPCode snippet.', 'novamira-adrianv2' ),
					$id
				)
			);
		}
		return self::snippet_to_record( $snippet );
	}

	// -------------------------------------------------------------------------
	// create-wpcode-snippet
	// -------------------------------------------------------------------------

	private static function register_create(): void {
		$name                  = 'novamira-adrianv2/create-wpcode-snippet';
		self::$ability_names[] = $name;

		wp_register_ability(
			$name,
			array(
				'label'               => __( 'Create WPCode Snippet', 'novamira-adrianv2' ),
				'description'         => __( 'Creates a WPCode snippet. Defaults to inactive (draft) so an admin can review. If `active=true`, WPCode runs activation checks and may auto-demote to draft on PHP errors — the response shape reflects what actually happened (see `active`, `status`, `last_error`).', 'novamira-adrianv2' ),
				'category'            => 'adrianv2-wpcode',
				'execute_callback'    => array( __CLASS__, 'execute_create' ),
				'permission_callback' => array( __CLASS__, 'check_write_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => self::write_input_props(),
					'required'   => array( 'title', 'code', 'code_type' ),
				),
				'output_schema'       => self::snippet_record_schema(),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => false,
					),
					'show_in_rest' => true,
					'mcp'          => array( 'public' => true ),
				),
			)
		);
	}

	public static function execute_create( $input ) {
		$input     = is_array( $input ) ? $input : array();
		$title     = (string) ( $input['title'] ?? '' );
		$code      = (string) ( $input['code'] ?? '' );
		$code_type = (string) ( $input['code_type'] ?? '' );

		if ( '' === $title || '' === $code || '' === $code_type ) {
			return new \WP_Error( 'wpcode_missing_required', __( 'title, code and code_type are required.', 'novamira-adrianv2' ) );
		}
		if ( ! in_array( $code_type, self::code_type_enum(), true ) ) {
			return new \WP_Error(
				'wpcode_invalid_code_type',
				sprintf(
					/* translators: %s: comma-separated list of allowed wpcode code_type slugs */
					__( 'code_type must be one of: %s.', 'novamira-adrianv2' ),
					implode( ', ', self::code_type_enum() )
				)
			);
		}

		// Default to inactive so an admin reviews before activation.
		if ( ! isset( $input['active'] ) ) {
			$input['active'] = false;
		}

		$snippet = new \WPCode_Snippet( array() );
		self::apply_input_to_snippet( $snippet, $input );
		$id = $snippet->save();

		if ( ! $id ) {
			return new \WP_Error( 'wpcode_save_failed', __( 'WPCode refused to save the snippet. Verify the code parses, the user has wpcode_edit_snippets, and there is no wpcode_last_error blocking the save.', 'novamira-adrianv2' ) );
		}

		return self::finalize_response( $snippet, self::snippet_to_record( $snippet ) );
	}

	// -------------------------------------------------------------------------
	// update-wpcode-snippet
	// -------------------------------------------------------------------------

	private static function register_update(): void {
		$name                  = 'novamira-adrianv2/update-wpcode-snippet';
		self::$ability_names[] = $name;

		wp_register_ability(
			$name,
			array(
				'label'               => __( 'Update WPCode Snippet', 'novamira-adrianv2' ),
				'description'         => __( 'Updates any subset of fields on an existing WPCode snippet. Returns the post-update state, honouring WPCode auto-demotion when activation checks fail. Set `bypass_kses: true` to route the write through `WPCode_Kses_Bypass::edit_post` (post row + compiled-asset cache purge only; meta fields like `code_type`, `location`, `tags` are not honoured in that mode — the agent should explicitly choose between the two modes per call).', 'novamira-adrianv2' ),
				'category'            => 'adrianv2-wpcode',
				'execute_callback'    => array( __CLASS__, 'execute_update' ),
				'permission_callback' => array( __CLASS__, 'check_write_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array_merge(
						array(
							'snippet_id'  => array(
								'type'        => 'integer',
								'description' => __( 'Snippet id (required).', 'novamira-adrianv2' ),
							),
							'bypass_kses' => array(
								'type'        => 'boolean',
								'default'     => false,
								'description' => __( 'When true, the write routes through `WPCode_Kses_Bypass::edit_post` instead of `WPCode_Snippet::save()`. In that mode ONLY `snippet_id`, `title`, and `code` are honoured; meta fields such as `code_type`, `location`, `tags`, `priority`, `device_type`, `schedule`, `use_rules`, `rules`, `custom_shortcode`, `compress_output`, and `active` are rejected. Use this when the normal `WPCode_Snippet::save()` path is broken or when the agent needs the production-safe compiled-asset cache purge that comes with the helper.', 'novamira-adrianv2' ),
							),
						),
						self::write_input_props()
					),
					'required'   => array( 'snippet_id' ),
				),
				'output_schema'       => self::snippet_record_schema(),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => false,
					),
					'show_in_rest' => true,
					'mcp'          => array( 'public' => true ),
				),
			)
		);
	}

	public static function execute_update( $input ) {
		$input = is_array( $input ) ? $input : array();
		$id    = isset( $input['snippet_id'] ) ? absint( $input['snippet_id'] ) : 0;
		if ( ! $id ) {
			return new \WP_Error( 'wpcode_missing_id', __( 'snippet_id is required.', 'novamira-adrianv2' ) );
		}

		$bypass_kses = ! empty( $input['bypass_kses'] );

		if ( $bypass_kses ) {
			return self::execute_update_via_kses_bypass( $id, $input );
		}

		$snippet = new \WPCode_Snippet( $id );
		if ( ! $snippet->get_id() || ! $snippet->get_post_data() ) {
			return new \WP_Error(
				'wpcode_snippet_not_found',
				sprintf(
					/* translators: %d: snippet post id */
					__( 'Snippet #%d not found.', 'novamira-adrianv2' ),
					$id
				)
			);
		}
		if ( isset( $input['code_type'] ) && ! in_array( (string) $input['code_type'], self::code_type_enum(), true ) ) {
			return new \WP_Error(
				'wpcode_invalid_code_type',
				sprintf(
					/* translators: %s: comma-separated list of allowed wpcode code_type slugs */
					__( 'code_type must be one of: %s.', 'novamira-adrianv2' ),
					implode( ', ', self::code_type_enum() )
				)
			);
		}

		self::apply_input_to_snippet( $snippet, $input );
		$result = $snippet->save();
		if ( ! $result ) {
			return new \WP_Error( 'wpcode_save_failed', __( 'WPCode refused to update the snippet.', 'novamira-adrianv2' ) );
		}

		// Reload to ensure output reflects saved state plus any auto-demotion.
		$snippet = new \WPCode_Snippet( $snippet->get_id() );
		return self::finalize_response( $snippet, self::snippet_to_record( $snippet ) );
	}

	/**
	 * Executes the update via the production-safe kses bypass + compiled-cache purge.
	 *
	 * In this mode ONLY `snippet_id`, `title`, and `code` are honoured. Everything else
	 * (`code_type`, `location`, `tags`, `priority`, `device_type`, `schedule`,
	 * `use_rules`, `rules`, `custom_shortcode`, `compress_output`, `active`) is
	 * rejected so the agent explicitly opts out of WPCode_Snippet::save()'s
	 * full-feature run and accepts the limited post-row semantics of the helper.
	 *
	 * @param int                 $id    Snippet post id.
	 * @param array<string,mixed> $input Input array.
	 * @return array<string,mixed>|\WP_Error
	 */
	private static function execute_update_via_kses_bypass( int $id, array $input ) {
		$disallowed = array(
			'code_type',
			'location',
			'auto_insert',
			'insert_number',
			'tags',
			'priority',
			'device_type',
			'schedule',
			'use_rules',
			'rules',
			'custom_shortcode',
			'compress_output',
			'active',
		);
		$rejected   = array();
		foreach ( $disallowed as $key ) {
			if ( array_key_exists( $key, $input ) ) {
				$rejected[] = $key;
			}
		}
		if ( ! empty( $rejected ) ) {
			return new \WP_Error(
				'wpcode_bypass_kses_disallowed_fields',
				sprintf(
					/* translators: %s: comma-separated list of field names */
					__( 'bypass_kses=true ignores meta fields. Remove these from the input: %s.', 'novamira-adrianv2' ),
					implode( ', ', $rejected )
				)
			);
		}

		if ( ! class_exists( '\\Novamira\\AdrianV2\\Helpers\\WPCode_Kses_Bypass' ) ) {
			return new \WP_Error(
				'wpcode_bypass_kses_helper_missing',
				__( 'WPCode_Kses_Bypass helper class is not loaded; ensure includes/helpers/class-wpcode-kses-bypass.php is required.', 'novamira-adrianv2' )
			);
		}

		$code      = isset( $input['code'] ) ? (string) $input['code'] : '';
		$title_arg = isset( $input['title'] ) ? (string) $input['title'] : '';

		$saved = WPCode_Kses_Bypass::edit_post( $id, $code, $title_arg, array() );

		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		// Reload the snippet so the response shape matches the existing WPCode_Snippet
		// contract (covers cases where the helper ran a no-op or noop-equivalent path).
		$snippet = new \WPCode_Snippet( (int) $saved );
		if ( ! $snippet->get_id() || ! $snippet->get_post_data() ) {
			return new \WP_Error(
				'wpcode_snippet_not_found',
				sprintf(
					/* translators: %d: snippet post id that the kses-bypass save returned */
					__( 'Snippet #%d not found after kses bypass save.', 'novamira-adrianv2' ),
					(int) $saved
				)
			);
		}
		return self::finalize_response( $snippet, self::snippet_to_record( $snippet ) );
	}

	// -------------------------------------------------------------------------
	// set-wpcode-snippet-status
	// -------------------------------------------------------------------------

	private static function register_set_status(): void {
		$name                  = 'novamira-adrianv2/set-wpcode-snippet-status';
		self::$ability_names[] = $name;

		wp_register_ability(
			$name,
			array(
				'label'               => __( 'Set WPCode Snippet Status', 'novamira-adrianv2' ),
				'description'         => __( 'Activate (publish) or deactivate (draft) a WPCode snippet. Requires wpcode_activate_snippets IN ADDITION to the standard write capability, so this ability cannot be used to flip activation state by less-privileged agents that can still edit code.', 'novamira-adrianv2' ),
				'category'            => 'adrianv2-wpcode',
				'execute_callback'    => array( __CLASS__, 'execute_set_status' ),
				'permission_callback' => array( __CLASS__, 'check_status_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'snippet_id' => array(
							'type'        => 'integer',
							'description' => __( 'Snippet id.', 'novamira-adrianv2' ),
						),
						'active'     => array(
							'type'        => 'boolean',
							'description' => __( 'true to activate (publish), false to deactivate (draft).', 'novamira-adrianv2' ),
						),
					),
					'required'   => array( 'snippet_id', 'active' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'snippet_id' => array( 'type' => 'integer' ),
						'active'     => array( 'type' => 'boolean' ),
						'status'     => array( 'type' => 'string' ),
						'last_error' => array( 'type' => array( 'object', 'null' ) ),
					),
				),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => true,
					),
					'show_in_rest' => true,
					'mcp'          => array( 'public' => true ),
				),
			)
		);
	}

	public static function execute_set_status( $input ) {
		$input  = is_array( $input ) ? $input : array();
		$id     = isset( $input['snippet_id'] ) ? absint( $input['snippet_id'] ) : 0;
		$active = ! empty( $input['active'] );
		if ( ! $id ) {
			return new \WP_Error( 'wpcode_missing_id', __( 'snippet_id is required.', 'novamira-adrianv2' ) );
		}
		$snippet = new \WPCode_Snippet( $id );
		if ( ! $snippet->get_id() || ! $snippet->get_post_data() ) {
			return new \WP_Error(
				'wpcode_snippet_not_found',
				sprintf(
					/* translators: %d: snippet post id */
					__( 'Snippet #%d not found.', 'novamira-adrianv2' ),
					$id
				)
			);
		}

		if ( $active ) {
			$snippet->activate();
		} else {
			$snippet->deactivate();
		}

		// Reload so the response reflects the post-save state + any demotion.
		$snippet = new \WPCode_Snippet( $id );
		return self::finalize_response(
			$snippet,
			array(
				'snippet_id' => $snippet->get_id(),
			)
		);
	}

	// -------------------------------------------------------------------------
	// duplicate-wpcode-snippet
	// -------------------------------------------------------------------------

	private static function register_duplicate(): void {
		$name                  = 'novamira-adrianv2/duplicate-wpcode-snippet';
		self::$ability_names[] = $name;

		wp_register_ability(
			$name,
			array(
				'label'               => __( 'Duplicate WPCode Snippet', 'novamira-adrianv2' ),
				'description'         => __( 'Clones an existing WPCode snippet (code, type, location, tags, rules, priority, etc.) into a new INACTIVE draft. Default title suffix " - Copy" can be overridden with `new_title`. Useful for iterating on a snippet without touching the original.', 'novamira-adrianv2' ),
				'category'            => 'adrianv2-wpcode',
				'execute_callback'    => array( __CLASS__, 'execute_duplicate' ),
				'permission_callback' => array( __CLASS__, 'check_write_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'snippet_id' => array(
							'type'        => 'integer',
							'description' => __( 'Source snippet id.', 'novamira-adrianv2' ),
						),
						'new_title'  => array(
							'type'        => 'string',
							'description' => __( 'Optional override for the new snippet title.', 'novamira-adrianv2' ),
						),
					),
					'required'   => array( 'snippet_id' ),
				),
				'output_schema'       => self::snippet_record_schema(),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => false,
					),
					'show_in_rest' => true,
					'mcp'          => array( 'public' => true ),
				),
			)
		);
	}

	public static function execute_duplicate( $input ) {
		$input = is_array( $input ) ? $input : array();
		$id    = isset( $input['snippet_id'] ) ? absint( $input['snippet_id'] ) : 0;
		if ( ! $id ) {
			return new \WP_Error( 'wpcode_missing_id', __( 'snippet_id is required.', 'novamira-adrianv2' ) );
		}

		$snippet = new \WPCode_Snippet( $id );
		if ( ! $snippet->get_id() || ! $snippet->get_post_data() ) {
			return new \WP_Error(
				'wpcode_snippet_not_found',
				sprintf(
					/* translators: %d: snippet post id */
					__( 'Snippet #%d not found.', 'novamira-adrianv2' ),
					$id
				)
			);
		}

		// Pre-load everything WPCode_Snippet::duplicate() needs.
		$snippet->get_data_for_caching();
		$snippet->get_note();
		$snippet->get_custom_shortcode();
		$snippet->get_device_type();
		$snippet->get_schedule();

		if ( ! empty( $input['new_title'] ) ) {
			$snippet->title = (string) $input['new_title'];
		} else {
			$snippet->title = $snippet->get_title() . ' - Copy';
		}

		// Always inactive copy.
		if ( isset( $snippet->post_data ) ) {
			$snippet->post_data->post_status = 'draft';
		}
		// wp_slash so the content save filters don't strip slashes from the code.
		$snippet->code = wp_slash( (string) $snippet->code );

		unset( $snippet->id );
		$new_id = $snippet->save();
		if ( ! $new_id ) {
			return new \WP_Error( 'wpcode_duplicate_failed', __( 'Failed to duplicate the snippet.', 'novamira-adrianv2' ) );
		}
		$snippet = new \WPCode_Snippet( $new_id );
		return self::snippet_to_record( $snippet );
	}

	// -------------------------------------------------------------------------
	// delete-wpcode-snippet
	// -------------------------------------------------------------------------

	private static function register_delete(): void {
		$name                  = 'novamira-adrianv2/delete-wpcode-snippet';
		self::$ability_names[] = $name;

		wp_register_ability(
			$name,
			array(
				'label'               => __( 'Delete WPCode Snippet', 'novamira-adrianv2' ),
				'description'         => __( 'Permanently deletes a WPCode snippet (skips trash). Destructive — there is no undo.', 'novamira-adrianv2' ),
				'category'            => 'adrianv2-wpcode',
				'execute_callback'    => array( __CLASS__, 'execute_delete' ),
				'permission_callback' => array( __CLASS__, 'check_write_permission' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'snippet_id' => array(
							'type'        => 'integer',
							'description' => __( 'Snippet id.', 'novamira-adrianv2' ),
						),
					),
					'required'   => array( 'snippet_id' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'    => array( 'type' => 'boolean' ),
						'snippet_id' => array( 'type' => 'integer' ),
					),
				),
				'meta'                => array(
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => true,
						'idempotent'  => false,
					),
					'show_in_rest' => true,
					'mcp'          => array( 'public' => true ),
				),
			)
		);
	}

	/**
	 * Permanently deletes (skips trash) the WPCode snippet identified by
	 * `snippet_id`. Refuses anything that is not of post_type `wpcode` so a
	 * dangling id from another post type can never reach `wp_delete_post`.
	 *
	 * @param array<string,mixed> $input Input.
	 * @return array<string,mixed>|\WP_Error Success record or WP_Error.
	 */
	public static function execute_delete( $input ) {
		$input = is_array( $input ) ? $input : array();
		$id    = isset( $input['snippet_id'] ) ? absint( $input['snippet_id'] ) : 0;
		if ( ! $id ) {
			return new \WP_Error( 'wpcode_missing_id', __( 'snippet_id is required.', 'novamira-adrianv2' ) );
		}
		$post = get_post( $id );
		if ( ! $post || 'wpcode' !== $post->post_type ) {
			return new \WP_Error(
				'wpcode_snippet_not_found',
				sprintf(
					/* translators: %d: snippet post id */
					__( 'Snippet #%d not found or is not a WPCode snippet.', 'novamira-adrianv2' ),
					$id
				)
			);
		}
		$deleted = wp_delete_post( $id, true );
		if ( ! $deleted ) {
			return new \WP_Error( 'wpcode_delete_failed', __( 'Failed to delete the snippet.', 'novamira-adrianv2' ) );
		}
		return array(
			'success'    => true,
			'snippet_id' => $id,
		);
	}
}
