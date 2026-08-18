<?php
/**
 * Class Upload_Request.
 *
 * @package UploadFromPhone
 */

declare(strict_types = 1);

namespace UploadFromPhone;

use WP_Error;
use WP_Post;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Represents a single upload request.
 *
 * An upload request is a short-lived, unguessable token that grants whoever
 * holds it permission to upload a limited number of files — and nothing else.
 * It is stored as a post of the {@see Upload_Request::POST_TYPE} post type,
 * with the token as the post slug.
 */
final class Upload_Request {
	/**
	 * Post type name.
	 */
	public const POST_TYPE = 'ufph_upload_request';

	/**
	 * Meta key holding the expiration timestamp.
	 */
	public const META_EXPIRES_AT = 'ufph_expires_at';

	/**
	 * Meta key holding the allowed media types.
	 */
	public const META_ALLOWED_TYPES = 'ufph_allowed_types';

	/**
	 * Meta key holding the list of allowed file type specifiers.
	 */
	public const META_ACCEPT = 'ufph_accept';

	/**
	 * Meta key holding whether multiple files may be uploaded.
	 */
	public const META_MULTIPLE = 'ufph_multiple';

	/**
	 * Meta key holding the IDs of the attachments uploaded so far.
	 */
	public const META_ATTACHMENT_ID = 'ufph_attachment_id';

	/**
	 * Default lifetime of an upload request, in seconds.
	 */
	public const DEFAULT_TTL = 15 * MINUTE_IN_SECONDS;

	/**
	 * The underlying post.
	 *
	 * @var WP_Post
	 */
	private WP_Post $post;

	/**
	 * Constructor.
	 *
	 * @param WP_Post $post The underlying post.
	 */
	private function __construct( WP_Post $post ) {
		$this->post = $post;
	}

	/**
	 * Creates a new upload request.
	 *
	 * @param array $args {
	 *     Arguments.
	 *
	 *     @type int      $post          Optional. ID of the post the media should be attached to.
	 *     @type string[] $allowed_types Optional. Allowed media types, e.g. `image`, `video`, `audio`.
	 *     @type string[] $accept        Optional. Unique file type specifiers, e.g. `image/*` or `.jpg`.
	 *     @type bool     $multiple      Optional. Whether more than one file may be uploaded.
	 * }
	 * @return Upload_Request|WP_Error New upload request on success, error object on failure.
	 *
	 * @phpstan-param array{post?: int, allowed_types?: string[], accept?: string[], multiple?: bool} $args
	 */
	public static function create( array $args ) {
		$post_id = wp_insert_post(
			[
				'post_type'   => self::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => __( 'Upload request', 'upload-from-phone' ),
				'post_name'   => self::generate_token(),
				'post_parent' => $args['post'] ?? 0,
				'post_author' => get_current_user_id(),
			],
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$post = get_post( $post_id );

		if ( ! $post instanceof WP_Post ) {
			return new WP_Error(
				'upload_from_phone_cannot_create',
				__( 'The upload link could not be created. Please try again.', 'upload-from-phone' ),
				[ 'status' => 500 ]
			);
		}

		update_post_meta( $post_id, self::META_EXPIRES_AT, time() + self::get_ttl() );
		update_post_meta( $post_id, self::META_ALLOWED_TYPES, array_values( $args['allowed_types'] ?? [] ) );
		update_post_meta( $post_id, self::META_ACCEPT, array_values( $args['accept'] ?? [] ) );
		update_post_meta( $post_id, self::META_MULTIPLE, ! empty( $args['multiple'] ) );

		$request = new self( $post );

		/**
		 * Fires right after an upload request has been created.
		 *
		 * @param WP_Post $post The upload request post.
		 */
		do_action( 'upload_from_phone_request_created', $post );

		return $request;
	}

	/**
	 * Returns the upload request for a given token, if it is still usable.
	 *
	 * Expired requests are treated as if they did not exist. This is deliberate:
	 * cleanup runs on cron, which is unreliable on low-traffic sites, so expiry
	 * must also be enforced at the time of use.
	 *
	 * @param string $token The upload request token.
	 * @return Upload_Request|null Upload request if found and still valid, null otherwise.
	 */
	public static function get_by_token( string $token ): ?self {
		if ( '' === $token || ! self::is_valid_token_format( $token ) ) {
			return null;
		}

		$posts = get_posts(
			[
				'name'                   => $token,
				'post_type'              => self::POST_TYPE,
				'post_status'            => 'publish',
				'numberposts'            => 1,
				'suppress_filters'       => false,
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
			]
		);

		if ( empty( $posts ) ) {
			return null;
		}

		$request = new self( $posts[0] );

		if ( $request->is_expired() ) {
			return null;
		}

		return $request;
	}

	/**
	 * Returns the upload request for a given post, regardless of whether it has expired.
	 *
	 * @param WP_Post $post The upload request post.
	 * @return Upload_Request|null Upload request, or null if the post is not an upload request.
	 */
	public static function from_post( WP_Post $post ): ?self {
		if ( self::POST_TYPE !== $post->post_type ) {
			return null;
		}

		return new self( $post );
	}

	/**
	 * Generates a new, unguessable upload request token.
	 *
	 * 32 hexadecimal characters, i.e. 128 bits of entropy. Lower case throughout
	 * so that it survives {@see sanitize_title()} unchanged when stored as a slug.
	 *
	 * @return string The token.
	 */
	private static function generate_token(): string {
		return bin2hex( random_bytes( 16 ) );
	}

	/**
	 * Determines whether a string has the shape of an upload request token.
	 *
	 * @param string $token Token to check.
	 * @return bool Whether the token is well-formed.
	 */
	public static function is_valid_token_format( string $token ): bool {
		return 1 === preg_match( '/^[a-f0-9]{32}$/', $token );
	}

	/**
	 * Returns the lifetime of an upload request, in seconds.
	 *
	 * @return int Lifetime in seconds.
	 */
	public static function get_ttl(): int {
		/**
		 * Filters how long an upload request stays valid.
		 *
		 * Keep this short. The token is the only thing standing between the
		 * public and an upload endpoint on your site.
		 *
		 * @param int $ttl Lifetime in seconds. Default 15 minutes.
		 */
		$ttl = (int) apply_filters( 'upload_from_phone_request_ttl', self::DEFAULT_TTL );

		return max( MINUTE_IN_SECONDS, $ttl );
	}

	/**
	 * Returns the underlying post.
	 *
	 * @return WP_Post The post.
	 */
	public function get_post(): WP_Post {
		return $this->post;
	}

	/**
	 * Returns the upload request token.
	 *
	 * @return string The token.
	 */
	public function get_token(): string {
		return $this->post->post_name;
	}

	/**
	 * Returns the public URL of the upload page.
	 *
	 * @return string The URL.
	 */
	public function get_url(): string {
		return (string) get_permalink( $this->post );
	}

	/**
	 * Returns the ID of the user who created this upload request.
	 *
	 * @return int User ID.
	 */
	public function get_author_id(): int {
		return (int) $this->post->post_author;
	}

	/**
	 * Returns the post the uploaded media should be attached to, if any.
	 *
	 * @return WP_Post|null The parent post, or null if the request is not tied to one.
	 */
	public function get_parent(): ?WP_Post {
		if ( $this->post->post_parent <= 0 ) {
			return null;
		}

		$parent = get_post( $this->post->post_parent );

		return $parent instanceof WP_Post ? $parent : null;
	}

	/**
	 * Returns the timestamp at which this upload request expires.
	 *
	 * @return int Unix timestamp.
	 */
	public function get_expires_at(): int {
		return (int) get_post_meta( $this->post->ID, self::META_EXPIRES_AT, true );
	}

	/**
	 * Determines whether this upload request has expired.
	 *
	 * @return bool Whether the request has expired.
	 */
	public function is_expired(): bool {
		return $this->get_expires_at() <= time();
	}

	/**
	 * Returns the media types this upload request accepts.
	 *
	 * @return string[] Allowed media types, e.g. `image`. Empty array means no restriction.
	 */
	public function get_allowed_types(): array {
		$types = get_post_meta( $this->post->ID, self::META_ALLOWED_TYPES, true );

		return is_array( $types ) ? array_values( array_filter( array_map( 'strval', $types ) ) ) : [];
	}

	/**
	 * Returns the unique file type specifiers for the file picker.
	 *
	 * @link https://developer.mozilla.org/en-US/docs/Web/HTML/Element/input/file#unique_file_type_specifiers
	 *
	 * @return string[] File type specifiers, e.g. `image/*` or `.jpg`.
	 */
	public function get_accept(): array {
		$accept = get_post_meta( $this->post->ID, self::META_ACCEPT, true );

		return is_array( $accept ) ? array_values( array_filter( array_map( 'strval', $accept ) ) ) : [];
	}

	/**
	 * Determines whether more than one file may be uploaded.
	 *
	 * @return bool Whether multiple files are allowed.
	 */
	public function allows_multiple(): bool {
		return (bool) get_post_meta( $this->post->ID, self::META_MULTIPLE, true );
	}

	/**
	 * Returns how many files may be uploaded in total for this request.
	 *
	 * @return int Maximum number of files.
	 */
	public function get_max_files(): int {
		if ( ! $this->allows_multiple() ) {
			return 1;
		}

		/**
		 * Filters the maximum number of files a single upload request accepts.
		 *
		 * @param int     $max_files Maximum number of files. Default 20.
		 * @param WP_Post $post      The upload request post.
		 */
		$max_files = (int) apply_filters( 'upload_from_phone_max_files', 20, $this->post );

		return max( 1, $max_files );
	}

	/**
	 * Returns the IDs of the attachments uploaded for this request so far.
	 *
	 * @return int[] Attachment IDs, oldest first.
	 */
	public function get_attachment_ids(): array {
		// Passed explicitly: this key holds one row per uploaded file, not a single value.
		$ids = get_post_meta( $this->post->ID, self::META_ATTACHMENT_ID, false );

		return array_values( array_map( 'intval', (array) $ids ) );
	}

	/**
	 * Determines whether this upload request has received all the files it accepts.
	 *
	 * @return bool Whether the request is complete.
	 */
	public function is_complete(): bool {
		return \count( $this->get_attachment_ids() ) >= $this->get_max_files();
	}

	/**
	 * Records an uploaded attachment against this upload request.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return void
	 */
	public function add_attachment( int $attachment_id ): void {
		add_post_meta( $this->post->ID, self::META_ATTACHMENT_ID, $attachment_id );
	}

	/**
	 * Permanently deletes this upload request.
	 *
	 * The uploaded attachments are deliberately left alone — by this point they
	 * belong to the post, not to the request.
	 *
	 * @return void
	 */
	public function delete(): void {
		/**
		 * Fires right before an upload request is deleted.
		 *
		 * @param WP_Post $post The upload request post.
		 */
		do_action( 'upload_from_phone_request_deleted', $this->post );

		wp_delete_post( $this->post->ID, true );
	}
}
