<?php
/**
 * Class REST_Upload_Requests_Controller.
 *
 * @package UploadFromPhone
 */

declare(strict_types = 1);

namespace UploadFromPhone;

use WP_Error;
use WP_Post;
use WP_REST_Attachments_Controller;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST API controller for upload requests.
 *
 * Covers the lifecycle of the request itself — asking for a link, polling it,
 * and revoking it — all of which is done by the editor, as a logged-in user.
 *
 * The files themselves do not come through here. They go to core's own media
 * endpoints, which the phone reaches by presenting its token; see
 * {@see Media_Endpoint_Access}. Uploading through core rather than through a
 * private endpoint of our own is what lets the client-side media pipeline work
 * at all, since it needs to sideload sub-sizes and finalize metadata against
 * the same attachment it created.
 */
class REST_Upload_Requests_Controller extends WP_REST_Controller {
	/**
	 * Namespace these routes are registered under.
	 */
	private const REST_NAMESPACE = 'upload-from-phone/v1';

	/**
	 * Base of the upload request routes.
	 */
	private const REST_BASE = 'upload-requests';

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->namespace = self::REST_NAMESPACE;
		$this->rest_base = self::REST_BASE;
	}

	/**
	 * Registers the routes for upload requests.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/' . self::REST_BASE,
			[
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'create_item' ],
					'permission_callback' => [ $this, 'create_item_permissions_check' ],
					'args'                => [
						'post'          => [
							'description' => __( 'ID of the post the uploaded media should be attached to.', 'upload-from-phone' ),
							'type'        => 'integer',
							'minimum'     => 1,
						],
						'allowed_types' => [
							'description' => __( 'Media types the upload request accepts.', 'upload-from-phone' ),
							'type'        => 'array',
							'items'       => [
								'type' => 'string',
								'enum' => [ 'image', 'video', 'audio', 'application' ],
							],
							'default'     => [],
						],
						'accept'        => [
							'description' => __( 'Unique file type specifiers for the file picker.', 'upload-from-phone' ),
							'type'        => 'array',
							'items'       => [
								'type' => 'string',
							],
							'default'     => [],
						],
						'multiple'      => [
							'description' => __( 'Whether more than one file may be uploaded.', 'upload-from-phone' ),
							'type'        => 'boolean',
							'default'     => false,
						],
					],
				],
				'schema' => [ $this, 'get_public_item_schema' ],
			]
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/' . self::REST_BASE . '/(?P<token>[a-f0-9]{32})',
			[
				'args'   => [
					'token' => [
						'description' => __( 'Unique token identifying the upload request.', 'upload-from-phone' ),
						'type'        => 'string',
					],
				],
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_item' ],
					'permission_callback' => [ $this, 'get_item_permissions_check' ],
				],
				[
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => [ $this, 'delete_item' ],
					'permission_callback' => [ $this, 'delete_item_permissions_check' ],
				],
				'schema' => [ $this, 'get_public_item_schema' ],
			]
		);
	}

	/**
	 * Checks whether the current user may create an upload request.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return true|WP_Error True if the request has access, WP_Error object otherwise.
	 */
	public function create_item_permissions_check( $request ) {
		if ( ! current_user_can( 'upload_files' ) ) {
			return new WP_Error(
				'upload_from_phone_cannot_create',
				__( 'Sorry, you are not allowed to upload media on this site.', 'upload-from-phone' ),
				[ 'status' => rest_authorization_required_code() ]
			);
		}

		$parent_id = $this->get_post_id( $request );

		if ( $parent_id > 0 && ! current_user_can( 'edit_post', $parent_id ) ) {
			return new WP_Error(
				'upload_from_phone_cannot_edit',
				__( 'Sorry, you are not allowed to upload media to this post.', 'upload-from-phone' ),
				[ 'status' => rest_authorization_required_code() ]
			);
		}

		return true;
	}

	/**
	 * Creates a new upload request.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error Response object on success, error object on failure.
	 */
	public function create_item( $request ) {
		$upload_request = Upload_Request::create(
			[
				'post'          => $this->get_post_id( $request ),
				'allowed_types' => $this->get_string_array( $request, 'allowed_types' ),
				'accept'        => $this->get_string_array( $request, 'accept' ),
				'multiple'      => (bool) $request['multiple'],
			]
		);

		if ( is_wp_error( $upload_request ) ) {
			return $upload_request;
		}

		$response = $this->prepare_item_for_response( $upload_request, $request );
		$response->set_status( 201 );

		return $response;
	}

	/**
	 * Checks whether the current user may read a given upload request.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return true|WP_Error True if the request has access, WP_Error object otherwise.
	 */
	public function get_item_permissions_check( $request ) {
		return $this->check_owner_permission( $this->get_token( $request ) );
	}

	/**
	 * Returns the status of an upload request, including anything uploaded so far.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error Response object on success, error object on failure.
	 */
	public function get_item( $request ) {
		$upload_request = Upload_Request::get_by_token( $this->get_token( $request ) );

		if ( ! $upload_request instanceof Upload_Request ) {
			return $this->not_found_error();
		}

		return $this->prepare_item_for_response( $upload_request, $request );
	}

	/**
	 * Checks whether the current user may delete a given upload request.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return true|WP_Error True if the request has access, WP_Error object otherwise.
	 */
	public function delete_item_permissions_check( $request ) {
		return $this->check_owner_permission( $this->get_token( $request ) );
	}

	/**
	 * Deletes an upload request, revoking the link immediately.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error Response object on success, error object on failure.
	 */
	public function delete_item( $request ) {
		$upload_request = Upload_Request::get_by_token( $this->get_token( $request ) );

		if ( ! $upload_request instanceof Upload_Request ) {
			return $this->not_found_error();
		}

		$previous = $this->prepare_item_for_response( $upload_request, $request )->get_data();

		$upload_request->delete();

		return rest_ensure_response(
			[
				'deleted'  => true,
				'previous' => $previous,
			]
		);
	}

	/**
	 * Reads the parent post ID off a request.
	 *
	 * Request parameters arrive as whatever was sent; the schema is what
	 * narrows them, and these three readers are where that narrowing is
	 * spelled out for the rest of the class.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return int Post ID, or 0 if the parameter was not a number.
	 */
	private function get_post_id( WP_REST_Request $request ): int {
		$post_id = $request['post'];

		return is_numeric( $post_id ) ? (int) $post_id : 0;
	}

	/**
	 * Reads the upload request token off a request.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return string The token, or an empty string if the parameter was not one.
	 */
	private function get_token( WP_REST_Request $request ): string {
		$token = $request['token'];

		return is_string( $token ) ? $token : '';
	}

	/**
	 * Reads a list of strings off a request.
	 *
	 * Anything that is not a string is dropped rather than coerced: both lists
	 * read through here hold names — media types and file type specifiers —
	 * and a value of any other shape is not one.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @param string          $param   Name of the request parameter to read.
	 * @return string[] The strings that were sent.
	 */
	private function get_string_array( WP_REST_Request $request, string $param ): array {
		$values = $request[ $param ];

		if ( ! is_array( $values ) ) {
			return [];
		}

		return array_values( array_filter( $values, 'is_string' ) );
	}

	/**
	 * Checks whether the current user owns, or may administer, a given upload request.
	 *
	 * @param string $token The upload request token.
	 * @return true|WP_Error True if the request has access, WP_Error object otherwise.
	 */
	private function check_owner_permission( string $token ) {
		$upload_request = Upload_Request::get_by_token( $token );

		if ( ! $upload_request instanceof Upload_Request ) {
			return $this->not_found_error();
		}

		$user_id = get_current_user_id();

		if ( $user_id > 0 && $user_id === $upload_request->get_author_id() ) {
			return true;
		}

		if ( current_user_can( 'edit_others_posts' ) ) {
			return true;
		}

		return new WP_Error(
			'upload_from_phone_cannot_read',
			__( 'Sorry, you are not allowed to view this upload request.', 'upload-from-phone' ),
			[ 'status' => rest_authorization_required_code() ]
		);
	}

	/**
	 * Returns the error used for unknown, expired, and inaccessible upload requests alike.
	 *
	 * Deliberately indistinguishable, so that the endpoint cannot be used to
	 * probe which tokens exist.
	 *
	 * @return WP_Error The error.
	 */
	private function not_found_error(): WP_Error {
		return new WP_Error(
			'upload_from_phone_invalid_token',
			__( 'This upload link is invalid or has expired.', 'upload-from-phone' ),
			[ 'status' => 404 ]
		);
	}

	/**
	 * Prepares an upload request for response.
	 *
	 * @param Upload_Request  $item    Upload request.
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response Response object.
	 */
	public function prepare_item_for_response( $item, $request ): WP_REST_Response {
		/*
		 * Only files the browser has finished with. One that is still having
		 * its image sizes generated would otherwise reach the editor as an
		 * attachment whose file is about to be replaced and whose sizes do not
		 * exist yet, and the block would keep the URL it saw first.
		 */
		$attachment_ids = $item->get_ready_attachment_ids();

		$data = [
			'token'          => $item->get_token(),
			'url'            => $item->get_url(),
			'expires_at'     => $item->get_expires_at(),
			'multiple'       => $item->allows_multiple(),
			'max_files'      => $item->get_max_files(),
			'allowed_types'  => $item->get_allowed_types(),
			'accept'         => $item->get_accept(),
			'complete'       => $item->is_complete(),
			'processing'     => ! empty( $item->get_pending_attachment_ids() ),
			'attachment_ids' => $attachment_ids,
			'attachments'    => $this->prepare_attachments( $attachment_ids, $request ),
		];

		// Constructed directly rather than through rest_ensure_response(), which
		// is documented as also returning WP_Error and would widen the signature.
		return new WP_REST_Response( $data );
	}

	/**
	 * Prepares the attachments uploaded for a request, in the shape the editor expects.
	 *
	 * Reuses the core attachments controller so that consumers get exactly the
	 * same object they would get from `/wp/v2/media`, saving a second round trip.
	 *
	 * @param int[]           $attachment_ids Attachment IDs.
	 * @param WP_REST_Request $request        Full details about the request.
	 * @return array List of prepared attachments.
	 *
	 * @phpstan-return list<array<string, mixed>>
	 */
	private function prepare_attachments( array $attachment_ids, WP_REST_Request $request ): array {
		if ( empty( $attachment_ids ) ) {
			return [];
		}

		$controller  = new WP_REST_Attachments_Controller( 'attachment' );
		$attachments = [];

		foreach ( $attachment_ids as $attachment_id ) {
			$attachment = get_post( $attachment_id );

			if ( ! $attachment instanceof WP_Post ) {
				continue;
			}

			$sub_request = new WP_REST_Request( 'GET', '/wp/v2/media/' . $attachment_id );
			$sub_request->set_param( 'context', 'view' );

			$response = $controller->prepare_item_for_response( $attachment, $sub_request );

			if ( $response instanceof WP_REST_Response ) {
				$attachments[] = $this->prepare_response_for_collection( $response );
			}
		}

		return $attachments;
	}

	/**
	 * Retrieves the upload request schema, conforming to JSON Schema.
	 *
	 * @return array Item schema data.
	 *
	 * @phpstan-return array<string, mixed>
	 */
	public function get_item_schema(): array {
		if ( ! empty( $this->schema ) ) {
			return $this->add_additional_fields_schema( $this->schema );
		}

		$this->schema = [
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'upload-request',
			'type'       => 'object',
			'properties' => [
				'token'          => [
					'description' => __( 'Unique token identifying the upload request.', 'upload-from-phone' ),
					'type'        => 'string',
					'context'     => [ 'view', 'edit' ],
					'readonly'    => true,
				],
				'url'            => [
					'description' => __( 'URL of the upload page.', 'upload-from-phone' ),
					'type'        => 'string',
					'format'      => 'uri',
					'context'     => [ 'view', 'edit' ],
					'readonly'    => true,
				],
				'expires_at'     => [
					'description' => __( 'Unix timestamp at which the upload request expires.', 'upload-from-phone' ),
					'type'        => 'integer',
					'context'     => [ 'view', 'edit' ],
					'readonly'    => true,
				],
				'multiple'       => [
					'description' => __( 'Whether more than one file may be uploaded.', 'upload-from-phone' ),
					'type'        => 'boolean',
					'context'     => [ 'view', 'edit' ],
					'readonly'    => true,
				],
				'max_files'      => [
					'description' => __( 'Maximum number of files the upload request accepts.', 'upload-from-phone' ),
					'type'        => 'integer',
					'context'     => [ 'view', 'edit' ],
					'readonly'    => true,
				],
				'allowed_types'  => [
					'description' => __( 'Media types the upload request accepts.', 'upload-from-phone' ),
					'type'        => 'array',
					'items'       => [ 'type' => 'string' ],
					'context'     => [ 'view', 'edit' ],
					'readonly'    => true,
				],
				'accept'         => [
					'description' => __( 'Unique file type specifiers for the file picker.', 'upload-from-phone' ),
					'type'        => 'array',
					'items'       => [ 'type' => 'string' ],
					'context'     => [ 'view', 'edit' ],
					'readonly'    => true,
				],
				'complete'       => [
					'description' => __( 'Whether the upload request has received all the files it accepts.', 'upload-from-phone' ),
					'type'        => 'boolean',
					'context'     => [ 'view', 'edit' ],
					'readonly'    => true,
				],
				'processing'     => [
					'description' => __( 'Whether a file is still being processed in the browser.', 'upload-from-phone' ),
					'type'        => 'boolean',
					'context'     => [ 'view', 'edit' ],
					'readonly'    => true,
				],
				'attachment_ids' => [
					'description' => __( 'IDs of the attachments that are finished and ready to use.', 'upload-from-phone' ),
					'type'        => 'array',
					'items'       => [ 'type' => 'integer' ],
					'context'     => [ 'view', 'edit' ],
					'readonly'    => true,
				],
				'attachments'    => [
					'description' => __( 'The attachments that are finished and ready to use.', 'upload-from-phone' ),
					'type'        => 'array',
					'items'       => [ 'type' => 'object' ],
					'context'     => [ 'view', 'edit' ],
					'readonly'    => true,
				],
			],
		];

		return $this->add_additional_fields_schema( $this->schema );
	}
}
