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
 * Everything the phone talks to lives here, in this plugin's own namespace.
 * The core attachments controller is left untouched on purpose: overriding it
 * would put this plugin in conflict with every other plugin that does the same,
 * and would widen a core endpoint's permission model for the sake of one feature.
 */
class REST_Upload_Requests_Controller extends WP_REST_Controller {
	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->namespace = 'upload-from-phone/v1';
		$this->rest_base = 'upload-requests';
	}

	/**
	 * Registers the routes for upload requests.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
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
			$this->namespace,
			'/' . $this->rest_base . '/(?P<token>[a-f0-9]{32})',
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

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<token>[a-f0-9]{32})/media',
			[
				'args' => [
					'token' => [
						'description' => __( 'Unique token identifying the upload request.', 'upload-from-phone' ),
						'type'        => 'string',
					],
				],
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'upload_item' ],
					'permission_callback' => [ $this, 'upload_item_permissions_check' ],
					'args'                => [

						/*
						 * Deliberately not `required`. WordPress validates required
						 * parameters in the dispatcher, before any permission
						 * callback runs, and on a public endpoint the token should
						 * be the first thing checked. A missing name is caught in
						 * the callback instead.
						 */
						'filename' => [
							'description' => __( 'Name of the file being uploaded.', 'upload-from-phone' ),
							'type'        => 'string',
						],
					],
				],
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

		$parent_id = (int) $request['post'];

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
				'post'          => (int) $request['post'],
				'allowed_types' => (array) $request['allowed_types'],
				'accept'        => (array) $request['accept'],
				'multiple'      => (bool) $request['multiple'],
			]
		);

		if ( is_wp_error( $upload_request ) ) {
			return $upload_request;
		}

		$response = rest_ensure_response( $this->prepare_item_for_response( $upload_request, $request ) );
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
		return $this->check_owner_permission( (string) $request['token'] );
	}

	/**
	 * Returns the status of an upload request, including anything uploaded so far.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error Response object on success, error object on failure.
	 */
	public function get_item( $request ) {
		$upload_request = Upload_Request::get_by_token( (string) $request['token'] );

		if ( ! $upload_request instanceof Upload_Request ) {
			return $this->not_found_error();
		}

		return rest_ensure_response( $this->prepare_item_for_response( $upload_request, $request ) );
	}

	/**
	 * Checks whether the current user may delete a given upload request.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return true|WP_Error True if the request has access, WP_Error object otherwise.
	 */
	public function delete_item_permissions_check( $request ) {
		return $this->check_owner_permission( (string) $request['token'] );
	}

	/**
	 * Deletes an upload request, revoking the link immediately.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error Response object on success, error object on failure.
	 */
	public function delete_item( $request ) {
		$upload_request = Upload_Request::get_by_token( (string) $request['token'] );

		if ( ! $upload_request instanceof Upload_Request ) {
			return $this->not_found_error();
		}

		$previous = $this->prepare_item_for_response( $upload_request, $request );

		$upload_request->delete();

		return rest_ensure_response(
			[
				'deleted'  => true,
				'previous' => $previous,
			]
		);
	}

	/**
	 * Checks whether a file may be uploaded for a given upload request.
	 *
	 * This is the only endpoint in the plugin that logged-out visitors can reach.
	 * Holding a valid, unexpired, unfinished token is the entire authorisation.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return true|WP_Error True if the request has access, WP_Error object otherwise.
	 */
	public function upload_item_permissions_check( $request ) {
		$upload_request = Upload_Request::get_by_token( (string) $request['token'] );

		if ( ! $upload_request instanceof Upload_Request ) {
			return $this->not_found_error();
		}

		if ( $upload_request->is_complete() ) {
			return new WP_Error(
				'upload_from_phone_request_complete',
				__( 'This upload link has already been used.', 'upload-from-phone' ),
				[ 'status' => 409 ]
			);
		}

		if ( ! user_can( $upload_request->get_author_id(), 'upload_files' ) ) {
			return new WP_Error(
				'upload_from_phone_cannot_upload',
				__( 'This upload link is no longer valid.', 'upload-from-phone' ),
				[ 'status' => 403 ]
			);
		}

		return true;
	}

	/**
	 * Handles a file uploaded against an upload request.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error Response object on success, error object on failure.
	 */
	public function upload_item( $request ) {
		$upload_request = Upload_Request::get_by_token( (string) $request['token'] );

		// Re-checked here rather than trusted from the permission callback:
		// two files racing each other must not both slip past the limit.
		if ( ! $upload_request instanceof Upload_Request ) {
			return $this->not_found_error();
		}

		if ( $upload_request->is_complete() ) {
			return new WP_Error(
				'upload_from_phone_request_complete',
				__( 'This upload link has already been used.', 'upload-from-phone' ),
				[ 'status' => 409 ]
			);
		}

		// Cast: an empty body reaches us as null, not an empty string, and the
		// size check below is strict about its argument.
		$body = (string) $request->get_body();

		if ( '' === $body ) {
			return new WP_Error(
				'upload_from_phone_no_file',
				__( 'No file was submitted.', 'upload-from-phone' ),
				[ 'status' => 400 ]
			);
		}

		$max_size = wp_max_upload_size();

		if ( $max_size > 0 && \strlen( $body ) > $max_size ) {
			return new WP_Error(
				'upload_from_phone_file_too_large',
				sprintf(
					/* translators: %s: Maximum allowed file size. */
					__( 'That file is too large. The maximum size is %s.', 'upload-from-phone' ),
					size_format( $max_size )
				),
				[ 'status' => 413 ]
			);
		}

		$file = $this->handle_upload(
			$body,
			sanitize_file_name( (string) $request['filename'] ),
			(string) $request->get_header( 'content_type' ),
			$upload_request
		);

		if ( is_wp_error( $file ) ) {
			return $file;
		}

		$parent    = $upload_request->get_parent();
		$parent_id = $parent instanceof WP_Post ? $parent->ID : 0;

		$attachment_id = wp_insert_attachment(
			[
				'post_mime_type' => $file['type'],
				'post_title'     => sanitize_text_field( pathinfo( wp_basename( $file['file'] ), PATHINFO_FILENAME ) ),
				'post_content'   => '',
				'post_status'    => 'inherit',
				// Attribute the upload to whoever asked for it. The visitor has no
				// account, and an attachment owned by user 0 is orphaned in the
				// media library.
				'post_author'    => $upload_request->get_author_id(),
			],
			$file['file'],
			$parent_id,
			true
		);

		if ( is_wp_error( $attachment_id ) ) {
			wp_delete_file( $file['file'] );

			return $attachment_id;
		}

		wp_update_attachment_metadata(
			$attachment_id,
			wp_generate_attachment_metadata( $attachment_id, $file['file'] )
		);

		$upload_request->add_attachment( $attachment_id );

		/**
		 * Fires after a file has been uploaded through an upload request.
		 *
		 * This is the hook to use for any post-processing — image optimisation,
		 * format conversion, alt text generation, and so on.
		 *
		 * @param int     $attachment_id ID of the newly created attachment.
		 * @param WP_Post $post          The upload request post.
		 */
		do_action( 'upload_from_phone_media_uploaded', $attachment_id, $upload_request->get_post() );

		$response = rest_ensure_response(
			[
				'id'        => $attachment_id,
				'title'     => get_the_title( $attachment_id ),
				'mime_type' => $file['type'],
				'complete'  => $upload_request->is_complete(),
			]
		);
		$response->set_status( 201 );

		return $response;
	}

	/**
	 * Writes the request body to the uploads directory.
	 *
	 * Mirrors how the core media endpoint handles a raw-body upload: park the
	 * bytes in a temporary file, then hand that file to WordPress so that all
	 * the usual filename, mime type, and permission checks still apply.
	 *
	 * @param string         $body           Raw request body.
	 * @param string         $filename       Name of the file being uploaded.
	 * @param string         $type           Mime type as declared by the client.
	 * @param Upload_Request $upload_request The upload request.
	 * @return array|WP_Error File data on success, error object on failure.
	 *
	 * @phpstan-return array{file: string, url: string, type: string}|WP_Error
	 */
	private function handle_upload( string $body, string $filename, string $type, Upload_Request $upload_request ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		if ( '' === $filename ) {
			return new WP_Error(
				'upload_from_phone_no_filename',
				__( 'The file needs a name.', 'upload-from-phone' ),
				[ 'status' => 400 ]
			);
		}

		$tmp_name = wp_tempnam( $filename );

		if ( ! $tmp_name ) {
			return new WP_Error(
				'upload_from_phone_cannot_write',
				__( 'The file could not be saved. Please try again.', 'upload-from-phone' ),
				[ 'status' => 500 ]
			);
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		if ( false === file_put_contents( $tmp_name, $body ) ) {
			wp_delete_file( $tmp_name );

			return new WP_Error(
				'upload_from_phone_cannot_write',
				__( 'The file could not be saved. Please try again.', 'upload-from-phone' ),
				[ 'status' => 500 ]
			);
		}

		$file_data = [
			'error'    => null,
			'tmp_name' => $tmp_name,
			'name'     => $filename,
			'type'     => $type,
		];

		$restrict_mime_types = $this->get_mime_type_filter( $upload_request );

		add_filter( 'upload_mimes', $restrict_mime_types );
		$file = wp_handle_sideload( $file_data, [ 'test_form' => false ] );
		remove_filter( 'upload_mimes', $restrict_mime_types );

		if ( isset( $file['error'] ) ) {
			wp_delete_file( $tmp_name );

			return new WP_Error(
				'upload_from_phone_upload_error',
				(string) $file['error'],
				[ 'status' => 400 ]
			);
		}

		return $file;
	}

	/**
	 * Returns a closure restricting the allowed mime types to those of an upload request.
	 *
	 * @param Upload_Request $upload_request The upload request.
	 * @return callable Filter callback.
	 */
	private function get_mime_type_filter( Upload_Request $upload_request ): callable {
		$allowed_types = $upload_request->get_allowed_types();

		return static function ( array $mime_types ) use ( $allowed_types ): array {
			if ( empty( $allowed_types ) ) {
				return $mime_types;
			}

			return array_filter(
				$mime_types,
				static function ( string $mime_type ) use ( $allowed_types ): bool {
					return \in_array( explode( '/', $mime_type )[0], $allowed_types, true );
				}
			);
		};
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
	 * @return array Response data.
	 *
	 * @phpstan-return array<string, mixed>
	 */
	public function prepare_item_for_response( $item, $request ): array {
		$attachment_ids = $item->get_attachment_ids();

		$data = [
			'token'          => $item->get_token(),
			'url'            => $item->get_url(),
			'expires_at'     => $item->get_expires_at(),
			'multiple'       => $item->allows_multiple(),
			'max_files'      => $item->get_max_files(),
			'allowed_types'  => $item->get_allowed_types(),
			'accept'         => $item->get_accept(),
			'complete'       => $item->is_complete(),
			'attachment_ids' => $attachment_ids,
			'attachments'    => $this->prepare_attachments( $attachment_ids, $request ),
		];

		return $data;
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
				'attachment_ids' => [
					'description' => __( 'IDs of the attachments uploaded so far.', 'upload-from-phone' ),
					'type'        => 'array',
					'items'       => [ 'type' => 'integer' ],
					'context'     => [ 'view', 'edit' ],
					'readonly'    => true,
				],
				'attachments'    => [
					'description' => __( 'The attachments uploaded so far.', 'upload-from-phone' ),
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
