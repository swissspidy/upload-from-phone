<?php
/**
 * Token-based access to core's media endpoints.
 *
 * @package UploadFromPhone
 */

declare(strict_types = 1);

namespace UploadFromPhone;

use stdClass;
use WP_Error;
use WP_Post;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lets whoever holds an upload request token use core's own media endpoints.
 *
 * The phone is not logged in, so on its own it cannot create an attachment. The
 * obvious answer — a private endpoint of our own that takes the file and makes
 * the attachment — is what this plugin used to do, and it is a dead end: the
 * client-side media pipeline does not just upload a file, it uploads the main
 * file, sideloads every sub-size it generated, then asks the server to commit
 * the resulting metadata. Those are three core endpoints working together, and
 * a bespoke endpoint can only ever stand in for the first of them.
 *
 * So instead of a parallel upload path, this grants a narrow, request-scoped
 * exception on the endpoints that already exist:
 *
 * - `POST /wp/v2/media`                — create the attachment
 * - `POST /wp/v2/media/<id>/sideload`  — add one client-generated sub-size
 * - `POST /wp/v2/media/<id>/finalize`  — write the attachment metadata
 *
 * The exception lasts exactly one request. It is installed after the route has
 * been matched and removed once the callback has run, it grants only the two
 * capabilities those endpoints ask for, and `edit_post` is granted only for
 * attachments this very upload request produced — so a token is not a way to
 * write to arbitrary media on the site.
 */
class Media_Endpoint_Access {

	/**
	 * Route of the endpoint that creates an attachment.
	 */
	private const ROUTE_CREATE = '/wp/v2/media';

	/**
	 * Route of the endpoint that adds a sub-size to an existing attachment.
	 */
	private const ROUTE_SIDELOAD = '/wp/v2/media/(?P<id>[\d]+)/sideload';

	/**
	 * Route of the endpoint that commits an attachment's metadata.
	 */
	private const ROUTE_FINALIZE = '/wp/v2/media/(?P<id>[\d]+)/finalize';

	/**
	 * Parameters each operation is allowed to send, beyond the file itself.
	 *
	 * Deliberately an allow-list. The token travels in a URL, and a URL is the
	 * sort of thing that ends up in a screenshot or a chat log, so what it
	 * authorises has to be exactly what the upload page needs and nothing that
	 * happens to be in the endpoint's schema — `url` would turn the token into
	 * a server-side fetch primitive, `post` would let it attach media to any
	 * post on the site, `author` and `status` speak for themselves.
	 */
	private const ALLOWED_PARAMS = [
		'create'   => [ 'upload_request', 'generate_sub_sizes', 'convert_format' ],
		'sideload' => [ 'upload_request', 'image_size', 'convert_format' ],
		'finalize' => [ 'upload_request', 'sub_sizes' ],
	];

	/**
	 * The upload request the current REST request is authorised by, if any.
	 *
	 * @var Upload_Request|null
	 */
	private ?Upload_Request $upload_request = null;

	/**
	 * Which of the three operations the current REST request is performing.
	 *
	 * @var string|null
	 */
	private ?string $operation = null;

	/**
	 * Mime types the current upload request may produce.
	 *
	 * Worked out before the filter goes on, because working it out needs
	 * `get_allowed_mime_types()`, which is what the filter filters.
	 *
	 * @var array<string, string>
	 */
	private array $allowed_mime_types = [];

	/**
	 * Hooks the class into the REST request lifecycle.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'rest_endpoints', [ $this, 'filter_rest_endpoints' ] );
		add_filter( 'rest_request_before_callbacks', [ $this, 'grant_access' ], 10, 3 );
		add_filter( 'rest_request_after_callbacks', [ $this, 'revoke_access' ], 10, 3 );
	}

	/**
	 * Adds the `upload_request` parameter to the media endpoints that accept it.
	 *
	 * @param array $endpoints Registered endpoints, keyed by route.
	 * @return array Filtered endpoints.
	 *
	 * @phpstan-param array<string, mixed> $endpoints
	 * @phpstan-return array<string, mixed>
	 */
	public function filter_rest_endpoints( array $endpoints ): array {
		foreach ( [ self::ROUTE_CREATE, self::ROUTE_SIDELOAD, self::ROUTE_FINALIZE ] as $route ) {
			if ( ! isset( $endpoints[ $route ] ) || ! \is_array( $endpoints[ $route ] ) ) {
				continue;
			}

			$endpoints[ $route ] = $this->add_token_param( $endpoints[ $route ] );
		}

		return $endpoints;
	}

	/**
	 * Adds the token parameter to every POST handler in a route's handler list.
	 *
	 * `rest_endpoints` runs before the server normalises what was registered,
	 * so a route is either a single handler or a list of them, and `methods` is
	 * still whatever shape it was registered in.
	 *
	 * @param array $handlers Handlers registered for one route.
	 * @return array Filtered handlers.
	 *
	 * @phpstan-param array<mixed> $handlers
	 * @phpstan-return array<mixed>
	 */
	private function add_token_param( array $handlers ): array {
		if ( isset( $handlers['callback'] ) ) {
			return $this->add_token_param_to_handler( $handlers );
		}

		foreach ( $handlers as $key => $handler ) {
			if ( ! is_numeric( $key ) || ! \is_array( $handler ) ) {
				continue;
			}

			$handlers[ $key ] = $this->add_token_param_to_handler( $handler );
		}

		return $handlers;
	}

	/**
	 * Adds the token parameter to a single handler, if it accepts POST.
	 *
	 * @param array $handler A single route handler.
	 * @return array Filtered handler.
	 *
	 * @phpstan-param array<mixed> $handler
	 * @phpstan-return array<mixed>
	 */
	private function add_token_param_to_handler( array $handler ): array {
		$methods = $handler['methods'] ?? '';

		if ( \is_string( $methods ) ) {
			$methods = explode( ',', $methods );
		}

		if ( ! \is_array( $methods ) ) {
			return $handler;
		}

		/*
		 * Registered either as a list of method names or as a map of them to
		 * `true`, and the server has not normalised either shape yet.
		 */
		$accepts_post = false;

		foreach ( $methods as $key => $value ) {
			$name = \is_string( $key ) ? $key : $value;

			if ( \is_string( $name ) && WP_REST_Server::CREATABLE === strtoupper( trim( $name ) ) ) {
				$accepts_post = true;
				break;
			}
		}

		if ( ! $accepts_post ) {
			return $handler;
		}

		if ( ! isset( $handler['args'] ) || ! \is_array( $handler['args'] ) ) {
			$handler['args'] = [];
		}

		$handler['args']['upload_request'] = [
			'description' => __( 'Token of the upload request this file belongs to.', 'upload-from-phone' ),
			'type'        => 'string',
			'pattern'     => '^[a-f0-9]{32}$',
		];

		return $handler;
	}

	/**
	 * Grants access for the current request, if it carries a usable token.
	 *
	 * Runs on `rest_request_before_callbacks`, which is the last hook before an
	 * endpoint's permission callback and therefore the only place where the
	 * route is known but nothing has been decided yet.
	 *
	 * One grant is live at a time, cleared here and again once the callback has
	 * run. That is enough because none of these three endpoints is ever
	 * dispatched from inside another; a nested dispatch would need a stack.
	 *
	 * @param WP_REST_Response|WP_Error|mixed $response Current response, if something already produced one.
	 * @param array                           $handler  Route handler.
	 * @param WP_REST_Request                 $request  The request.
	 * @return WP_REST_Response|WP_Error|mixed Response, or an error if the token was offered but cannot be used.
	 *
	 * @phpstan-param array<mixed> $handler
	 */
	public function grant_access( $response, $handler, $request ) {
		// Whatever ran before us has already decided; nothing to authorise.
		if ( is_wp_error( $response ) || $response instanceof WP_REST_Response ) {
			return $response;
		}

		// A previous request in the same process must never leak into this one.
		$this->revoke();

		if ( ! $request instanceof WP_REST_Request ) {
			return $response;
		}

		$token = $request['upload_request'];

		if ( ! \is_string( $token ) || '' === $token ) {
			return $response;
		}

		$operation = $this->get_operation( $request );

		// The token means nothing here. Leave the endpoint to its own rules.
		if ( null === $operation ) {
			return $response;
		}

		$upload_request = Upload_Request::get_by_token( $token );

		if ( ! $upload_request instanceof Upload_Request ) {
			return new WP_Error(
				'upload_from_phone_invalid_token',
				__( 'This upload link is no longer valid.', 'upload-from-phone' ),
				[ 'status' => 403 ]
			);
		}

		$allowed = $this->check_operation( $operation, $upload_request, $request );

		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		/*
		 * The token stands in for the person who created it, so it cannot
		 * outlive their ability to upload — a revoked role has to take the
		 * outstanding links with it.
		 */
		if ( ! user_can( $upload_request->get_author_id(), 'upload_files' ) ) {
			return new WP_Error(
				'upload_from_phone_cannot_upload',
				__( 'This upload link is no longer valid.', 'upload-from-phone' ),
				[ 'status' => 403 ]
			);
		}

		$unexpected = $this->get_unexpected_params( $request, $operation );

		if ( ! empty( $unexpected ) ) {
			return new WP_Error(
				'upload_from_phone_unexpected_params',
				sprintf(
					/* translators: %s: Comma-separated list of parameter names. */
					__( 'An upload link cannot be used to set: %s.', 'upload-from-phone' ),
					implode( ', ', $unexpected )
				),
				[ 'status' => 400 ]
			);
		}

		$this->grant( $upload_request, $operation );

		return $response;
	}

	/**
	 * Removes the access granted for the current request.
	 *
	 * @param WP_REST_Response|WP_Error|mixed $response Current response.
	 * @param array                           $handler  Route handler.
	 * @param WP_REST_Request                 $request  The request.
	 * @return WP_REST_Response|WP_Error|mixed The response, untouched.
	 *
	 * @phpstan-param array<mixed> $handler
	 */
	public function revoke_access( $response, $handler, $request ) {
		if (
			$this->upload_request instanceof Upload_Request &&
			! is_wp_error( $response ) &&
			$request instanceof WP_REST_Request
		) {
			$attachment_id = $this->get_attachment_id( $request );

			if ( 'finalize' === $this->operation ) {
				/*
				 * Finalizing is the last thing the browser does to a file.
				 * Until it lands, the attachment is missing the sizes and the
				 * scaled file the editor is waiting for, so this is the moment
				 * it becomes safe to hand over.
				 */
				$this->upload_request->mark_attachment_ready( $attachment_id );
			} elseif ( 'sideload' === $this->operation ) {
				/*
				 * Each size that arrives says the browser is still at it. Long
				 * jobs are common — a large photo on a modest phone can take a
				 * while — so the file is judged by whether anything is still
				 * happening to it, not by how long it has been going.
				 */
				$this->upload_request->touch_pending_attachment( $attachment_id );
			}
		}

		$this->revoke();

		return $response;
	}

	/**
	 * Returns which of the three operations a request is for, if any.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return string|null Operation name, or null if this is not an endpoint we grant access to.
	 */
	private function get_operation( WP_REST_Request $request ): ?string {
		if ( WP_REST_Server::CREATABLE !== $request->get_method() ) {
			return null;
		}

		$route = (string) $request->get_route();

		if ( self::ROUTE_CREATE === $route ) {
			return 'create';
		}

		if ( preg_match( '#^/wp/v2/media/\d+/sideload$#', $route ) ) {
			return 'sideload';
		}

		if ( preg_match( '#^/wp/v2/media/\d+/finalize$#', $route ) ) {
			return 'finalize';
		}

		return null;
	}

	/**
	 * Checks that the requested operation is one this upload request may perform.
	 *
	 * @param string          $operation      Operation name.
	 * @param Upload_Request  $upload_request The upload request.
	 * @param WP_REST_Request $request        The request.
	 * @return true|WP_Error True when allowed, error object otherwise.
	 */
	private function check_operation( string $operation, Upload_Request $upload_request, WP_REST_Request $request ) {
		if ( 'create' === $operation ) {
			if ( $upload_request->is_complete() ) {
				return new WP_Error(
					'upload_from_phone_request_complete',
					__( 'This upload link has already been used.', 'upload-from-phone' ),
					[ 'status' => 409 ]
				);
			}

			return true;
		}

		/*
		 * Sideloading and finalizing are follow-up steps on a file this very
		 * request already uploaded. Checking the attachment against the
		 * request's own list is what keeps a token from being a way to write
		 * over unrelated media: without it, any live token would be a licence
		 * to attach a file to any attachment ID on the site.
		 */
		$attachment_id = $this->get_attachment_id( $request );

		if ( $attachment_id <= 0 || ! \in_array( $attachment_id, $upload_request->get_attachment_ids(), true ) ) {
			return new WP_Error(
				'upload_from_phone_unknown_attachment',
				__( 'This upload link cannot be used to change that file.', 'upload-from-phone' ),
				[ 'status' => 403 ]
			);
		}

		return true;
	}

	/**
	 * Reads the attachment ID off a request.
	 *
	 * All three routes declare `id` as an integer, and core validates and
	 * sanitizes request parameters against that schema before it reaches
	 * either of the filters this class hooks. So the value has already been
	 * checked and cast by the time this runs; `mixed` is only what reading a
	 * request through ArrayAccess gives back.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return int Attachment ID, or 0 if the parameter was not a number.
	 */
	private function get_attachment_id( WP_REST_Request $request ): int {
		$attachment_id = $request['id'];

		return is_numeric( $attachment_id ) ? (int) $attachment_id : 0;
	}

	/**
	 * Returns the parameters the client sent that this operation does not allow.
	 *
	 * Only what the client actually sent is considered: route placeholders and
	 * the defaults the server fills in for registered arguments are not the
	 * caller's doing, and treating them as such would reject every request the
	 * moment core gave one of its media arguments a default.
	 *
	 * @param WP_REST_Request $request   The request.
	 * @param string          $operation Operation name.
	 * @return string[] Parameter names that are not allowed here.
	 */
	private function get_unexpected_params( WP_REST_Request $request, string $operation ): array {
		/**
		 * Filters the parameters an upload request may send to core's media endpoints.
		 *
		 * @param string[] $allowed   Allowed parameter names.
		 * @param string   $operation One of `create`, `sideload`, or `finalize`.
		 */
		$allowed = (array) apply_filters(
			'upload_from_phone_allowed_media_params',
			self::ALLOWED_PARAMS[ $operation ] ?? [],
			$operation
		);

		$sent = array_merge(
			(array) $request->get_query_params(),
			(array) $request->get_body_params(),
			(array) ( $request->get_json_params() ?? [] )
		);

		$unexpected = [];

		foreach ( array_keys( $sent ) as $name ) {
			$name = (string) $name;

			/*
			 * The REST API's own parameters, not the endpoint's. `rest_route`
			 * is how a site without pretty permalinks addresses a route at all,
			 * so it rides along on every request such a site makes.
			 */
			if ( str_starts_with( $name, '_' ) || 'rest_route' === $name ) {
				continue;
			}

			if ( ! \in_array( $name, $allowed, true ) ) {
				// Named back to the caller, so reduced to something printable.
				$unexpected[] = sanitize_key( $name );
			}
		}

		return $unexpected;
	}

	/**
	 * Installs the filters that make the endpoints answer to the token.
	 *
	 * @param Upload_Request $upload_request The upload request.
	 * @param string         $operation      Operation name.
	 * @return void
	 */
	private function grant( Upload_Request $upload_request, string $operation ): void {
		$this->upload_request     = $upload_request;
		$this->operation          = $operation;
		$this->allowed_mime_types = $this->get_allowed_mime_types( $upload_request );

		add_filter( 'user_has_cap', [ $this, 'filter_user_has_cap' ], 10, 3 );
		add_filter( 'upload_mimes', [ $this, 'filter_upload_mimes' ], 100 );
		add_filter( 'rest_pre_insert_attachment', [ $this, 'filter_pre_insert_attachment' ], 10, 2 );
		add_action( 'rest_after_insert_attachment', [ $this, 'record_attachment' ], 10, 3 );
	}

	/**
	 * Takes the granted access back.
	 *
	 * @return void
	 */
	private function revoke(): void {
		if ( ! $this->upload_request instanceof Upload_Request ) {
			return;
		}

		remove_filter( 'user_has_cap', [ $this, 'filter_user_has_cap' ], 10 );
		remove_filter( 'upload_mimes', [ $this, 'filter_upload_mimes' ], 100 );
		remove_filter( 'rest_pre_insert_attachment', [ $this, 'filter_pre_insert_attachment' ], 10 );
		remove_action( 'rest_after_insert_attachment', [ $this, 'record_attachment' ], 10 );

		$this->upload_request     = null;
		$this->operation          = null;
		$this->allowed_mime_types = [];
	}

	/**
	 * Grants the capabilities core's media endpoints ask for.
	 *
	 * Granting whatever `map_meta_cap()` resolved the request down to, rather
	 * than a fixed list of primitive capabilities, is what keeps this working
	 * on sites that have rearranged their roles: the gate is the meta
	 * capability being asked about, which is stable, not the primitives it
	 * happens to map to on this site.
	 *
	 * @param array $allcaps All capabilities the user has.
	 * @param array $caps    Primitive capabilities being checked for.
	 * @param array $args    Arguments: cap being checked, user ID, object ID.
	 * @return array Filtered capabilities.
	 *
	 * @phpstan-param array<string, bool> $allcaps
	 * @phpstan-param string[]            $caps
	 * @phpstan-param array<mixed>        $args
	 * @phpstan-return array<string, bool>
	 */
	public function filter_user_has_cap( array $allcaps, array $caps, array $args ): array {
		if ( ! $this->upload_request instanceof Upload_Request ) {
			return $allcaps;
		}

		if ( ! $this->is_capability_granted( $args ) ) {
			return $allcaps;
		}

		foreach ( $caps as $cap ) {
			$allcaps[ (string) $cap ] = true;
		}

		return $allcaps;
	}

	/**
	 * Determines whether a capability check is one this token answers for.
	 *
	 * @param array $args Arguments passed to the capability check.
	 * @return bool Whether to grant the capability.
	 *
	 * @phpstan-param array<mixed> $args
	 */
	private function is_capability_granted( array $args ): bool {
		if ( ! $this->upload_request instanceof Upload_Request ) {
			return false;
		}

		$capability = isset( $args[0] ) && \is_string( $args[0] ) ? $args[0] : '';

		// Creating an attachment, and the `create_posts` capability that the
		// attachment post type maps to the same thing.
		if ( 'upload_files' === $capability ) {
			return true;
		}

		// Sideloading and finalizing edit the attachment that was just created.
		if ( 'edit_post' !== $capability ) {
			return false;
		}

		$object_id = isset( $args[2] ) && is_numeric( $args[2] ) ? (int) $args[2] : 0;

		return $object_id > 0
			&& \in_array( $object_id, $this->upload_request->get_attachment_ids(), true );
	}

	/**
	 * Restricts uploads to the file types this upload request asked for.
	 *
	 * @param array $mime_types Allowed mime types.
	 * @return array Filtered mime types.
	 *
	 * @phpstan-param array<string, string> $mime_types
	 * @phpstan-return array<string, string>
	 */
	public function filter_upload_mimes( array $mime_types ): array {
		if ( ! $this->upload_request instanceof Upload_Request ) {
			return $mime_types;
		}

		return $this->allowed_mime_types;
	}

	/**
	 * Works out which mime types an upload request may produce.
	 *
	 * Bounded by what the person who created the link may upload, so a link
	 * never becomes a way around its author's own restrictions, and then
	 * narrowed to the media types the block asked for.
	 *
	 * @param Upload_Request $upload_request The upload request.
	 * @return array Allowed mime types, keyed by file extension pattern.
	 *
	 * @phpstan-return array<string, string>
	 */
	private function get_allowed_mime_types( Upload_Request $upload_request ): array {
		$mime_types    = get_allowed_mime_types( $upload_request->get_author_id() );
		$allowed_types = $upload_request->get_allowed_types();

		if ( empty( $allowed_types ) ) {
			return $mime_types;
		}

		return array_filter(
			$mime_types,
			static function ( string $mime_type ) use ( $allowed_types ): bool {
				return \in_array( explode( '/', $mime_type )[0], $allowed_types, true );
			}
		);
	}

	/**
	 * Forces the attachment's author and parent to the upload request's own.
	 *
	 * Set here rather than sent by the phone: an attachment owned by nobody is
	 * orphaned in the media library, and letting the request name its own
	 * author or parent post is precisely the thing a leaked token should not
	 * be able to do.
	 *
	 * @param stdClass|mixed  $prepared Attachment about to be inserted.
	 * @param WP_REST_Request $request  The request.
	 * @return stdClass|mixed Filtered attachment.
	 */
	public function filter_pre_insert_attachment( $prepared, $request ) {
		if ( ! $this->upload_request instanceof Upload_Request || ! $prepared instanceof stdClass ) {
			return $prepared;
		}

		$parent = $this->upload_request->get_parent();

		$prepared->post_author = $this->upload_request->get_author_id();
		$prepared->post_parent = $parent instanceof WP_Post ? $parent->ID : 0;

		return $prepared;
	}

	/**
	 * Records a newly created attachment against the upload request.
	 *
	 * @param WP_Post         $attachment The attachment.
	 * @param WP_REST_Request $request    The request.
	 * @param bool            $creating   Whether the attachment was just created.
	 * @return void
	 */
	public function record_attachment( $attachment, $request, $creating ): void {
		if ( ! $creating || ! $this->upload_request instanceof Upload_Request || ! $attachment instanceof WP_Post ) {
			return;
		}

		/*
		 * `generate_sub_sizes` is how the pipeline tells the server it will
		 * produce the image sizes itself — which means this attachment is not
		 * finished yet, and more requests are coming for it. Anything else is
		 * complete the moment the server has written its metadata.
		 */
		$is_pending = false === $request['generate_sub_sizes'];

		$this->upload_request->add_attachment( $attachment->ID, $is_pending );

		/**
		 * Fires after a file has been uploaded through an upload request.
		 *
		 * This is the hook to use for any post-processing — image optimisation,
		 * format conversion, alt text generation, and so on.
		 *
		 * @param int     $attachment_id ID of the newly created attachment.
		 * @param WP_Post $post          The upload request post.
		 */
		do_action( 'upload_from_phone_media_uploaded', $attachment->ID, $this->upload_request->get_post() );
	}
}
