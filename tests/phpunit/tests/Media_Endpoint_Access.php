<?php
/**
 * Tests for token-based access to core's media endpoints.
 *
 * @package UploadFromPhone
 */

declare(strict_types = 1);

namespace UploadFromPhone\Tests;

use UploadFromPhone\Upload_Request;
use WP_REST_Request;
use WP_Test_REST_TestCase;

/**
 * @coversDefaultClass \UploadFromPhone\Media_Endpoint_Access
 */
class Test_Media_Endpoint_Access extends WP_Test_REST_TestCase {
	/**
	 * Core's media endpoint.
	 */
	private const MEDIA_ROUTE = '/wp/v2/media';

	/**
	 * Administrator user ID.
	 *
	 * @var int
	 */
	protected static int $admin_id;

	/**
	 * Author user ID.
	 *
	 * @var int
	 */
	protected static int $author_id;

	/**
	 * Attachments created during a test, to be cleaned up afterwards.
	 *
	 * @var int[]
	 */
	private array $attachment_ids = [];

	/**
	 * Sets up shared fixtures.
	 *
	 * @param \WP_UnitTest_Factory $factory Factory.
	 * @return void
	 */
	public static function wpSetUpBeforeClass( $factory ): void {
		self::$admin_id  = $factory->user->create( [ 'role' => 'administrator' ] );
		self::$author_id = $factory->user->create( [ 'role' => 'author' ] );
	}

	/**
	 * Sets up each test.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Firing a core hook, not declaring one.
		do_action( 'rest_api_init' );
	}

	/**
	 * Cleans up after each test.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		foreach ( $this->attachment_ids as $attachment_id ) {
			wp_delete_attachment( $attachment_id, true );
		}

		$this->attachment_ids = [];

		parent::tear_down();
	}

	/**
	 * @covers ::filter_rest_endpoints
	 */
	public function test_media_endpoints_accept_an_upload_request_token(): void {
		$routes = rest_get_server()->get_routes();

		foreach ( [ self::MEDIA_ROUTE, self::MEDIA_ROUTE . '/(?P<id>[\d]+)/sideload', self::MEDIA_ROUTE . '/(?P<id>[\d]+)/finalize' ] as $route ) {
			$this->assertArrayHasKey( $route, $routes, $route );

			$accepts_token = false;

			foreach ( $routes[ $route ] as $handler ) {
				if ( isset( $handler['methods']['POST'], $handler['args']['upload_request'] ) ) {
					$accepts_token = true;
				}
			}

			$this->assertTrue( $accepts_token, $route );
		}
	}

	/**
	 * The whole point: a phone with a link and no account can create media.
	 *
	 * @covers ::grant_access
	 * @covers ::filter_user_has_cap
	 * @covers ::filter_pre_insert_attachment
	 * @covers ::record_attachment
	 */
	public function test_a_token_lets_a_logged_out_visitor_create_an_attachment(): void {
		wp_set_current_user( self::$admin_id );

		$post_id        = self::factory()->post->create( [ 'post_author' => self::$admin_id ] );
		$upload_request = $this->create_upload_request( [ 'post' => $post_id ] );

		wp_set_current_user( 0 );

		$response = $this->upload( $upload_request->get_token() );

		$this->assertSame( 201, $response->get_status(), wp_json_encode( $response->get_data() ) );

		$attachment_id          = (int) $response->get_data()['id'];
		$this->attachment_ids[] = $attachment_id;

		$attachment = get_post( $attachment_id );
		$this->assertNotNull( $attachment );

		// Attributed to whoever asked for the upload, and filed against their post.
		$this->assertSame( self::$admin_id, (int) $attachment->post_author );
		$this->assertSame( $post_id, (int) $attachment->post_parent );

		$fresh = Upload_Request::get_by_token( $upload_request->get_token() );
		$this->assertNotNull( $fresh );
		$this->assertSame( [ $attachment_id ], $fresh->get_attachment_ids() );
	}

	/**
	 * @covers ::grant_access
	 */
	public function test_uploading_without_a_token_is_refused(): void {
		wp_set_current_user( 0 );

		$response = $this->upload( '' );

		$this->assertSame( 401, $response->get_status() );
	}

	/**
	 * @covers ::grant_access
	 */
	public function test_uploading_with_an_unknown_token_is_refused(): void {
		wp_set_current_user( 0 );

		$response = $this->upload( str_repeat( 'b', 32 ) );

		$this->assertErrorResponse( 'upload_from_phone_invalid_token', $response, 403 );
	}

	/**
	 * @covers ::grant_access
	 */
	public function test_uploading_with_an_expired_token_is_refused(): void {
		wp_set_current_user( self::$admin_id );

		$upload_request = $this->create_upload_request();

		update_post_meta(
			$upload_request->get_post()->ID,
			Upload_Request::META_EXPIRES_AT,
			time() - 1
		);

		wp_set_current_user( 0 );

		$response = $this->upload( $upload_request->get_token() );

		$this->assertErrorResponse( 'upload_from_phone_invalid_token', $response, 403 );
	}

	/**
	 * @covers ::check_operation
	 */
	public function test_uploading_to_a_full_request_is_refused(): void {
		wp_set_current_user( self::$admin_id );

		$upload_request = $this->create_upload_request( [ 'multiple' => false ] );
		$upload_request->add_attachment( 42 );

		wp_set_current_user( 0 );

		$response = $this->upload( $upload_request->get_token() );

		$this->assertErrorResponse( 'upload_from_phone_request_complete', $response, 409 );
	}

	/**
	 * The link is only as good as the account behind it. If the person who
	 * created it loses the ability to upload, so does the link.
	 *
	 * @covers ::grant_access
	 */
	public function test_uploading_is_refused_once_the_author_loses_permission(): void {
		wp_set_current_user( self::$author_id );

		$upload_request = $this->create_upload_request();

		$author = get_userdata( self::$author_id );
		$this->assertNotFalse( $author );
		$author->set_role( 'subscriber' );

		wp_set_current_user( 0 );

		$response = $this->upload( $upload_request->get_token() );

		$this->assertErrorResponse( 'upload_from_phone_cannot_upload', $response, 403 );

		$author->set_role( 'author' );
	}

	/**
	 * A token authorises an upload and nothing else. Anything that would let it
	 * reach past that — naming a post to attach to, an author to attribute to,
	 * or a URL for the server to go and fetch — is refused outright.
	 *
	 * @dataProvider data_disallowed_params
	 * @covers ::get_unexpected_params
	 *
	 * @param string $name  Parameter name.
	 * @param mixed  $value Parameter value.
	 * @return void
	 */
	public function test_a_token_cannot_set_anything_it_should_not( string $name, $value ): void {
		wp_set_current_user( self::$admin_id );

		$upload_request = $this->create_upload_request();

		wp_set_current_user( 0 );

		$response = $this->upload( $upload_request->get_token(), [ $name => $value ] );

		$this->assertErrorResponse( 'upload_from_phone_unexpected_params', $response, 400 );
	}

	/**
	 * Data provider.
	 *
	 * @return array<string, array{0: string, 1: mixed}>
	 */
	public function data_disallowed_params(): array {
		return [
			'a post to attach to' => [ 'post', 1 ],
			'an author'           => [ 'author', 1 ],
			'a status'            => [ 'status', 'private' ],
			'a URL to fetch'      => [ 'url', 'https://example.com/image.jpg' ],
			'a description'       => [ 'description', 'Anything at all' ],
		];
	}

	/**
	 * A site without pretty permalinks addresses every REST route through a
	 * `rest_route` query parameter, so it rides along on every request the
	 * phone makes and must not be mistaken for something the caller chose.
	 *
	 * @covers ::get_unexpected_params
	 */
	public function test_the_rest_route_parameter_is_not_mistaken_for_an_unexpected_one(): void {
		wp_set_current_user( self::$admin_id );

		$upload_request = $this->create_upload_request();

		wp_set_current_user( 0 );

		$response = $this->upload(
			$upload_request->get_token(),
			[],
			[ 'rest_route' => self::MEDIA_ROUTE ]
		);

		$this->assertSame( 201, $response->get_status(), wp_json_encode( $response->get_data() ) );

		$this->attachment_ids[] = (int) $response->get_data()['id'];
	}

	/**
	 * @covers ::filter_upload_mimes
	 */
	public function test_uploaded_file_must_match_the_allowed_types(): void {
		wp_set_current_user( self::$admin_id );

		$upload_request = $this->create_upload_request( [ 'allowed_types' => [ 'video' ] ] );

		wp_set_current_user( 0 );

		$response = $this->upload( $upload_request->get_token() );

		/*
		 * The refusal comes out of `wp_handle_sideload()`, whose error code and
		 * status are core's business. What matters here is that the file was
		 * turned away and nothing was recorded against the request.
		 */
		$this->assertTrue( $response->is_error(), wp_json_encode( $response->get_data() ) );

		$fresh = Upload_Request::get_by_token( $upload_request->get_token() );
		$this->assertNotNull( $fresh );
		$this->assertSame( [], $fresh->get_attachment_ids() );
	}

	/**
	 * @covers ::check_operation
	 */
	public function test_a_token_cannot_sideload_onto_an_unrelated_attachment(): void {
		wp_set_current_user( self::$admin_id );

		$upload_request = $this->create_upload_request();

		$unrelated              = self::factory()->attachment->create_object(
			[
				'file'           => 'unrelated.jpg',
				'post_mime_type' => 'image/jpeg',
				'post_author'    => self::$admin_id,
			]
		);
		$this->attachment_ids[] = $unrelated;

		wp_set_current_user( 0 );

		$request = new WP_REST_Request( 'POST', self::MEDIA_ROUTE . '/' . $unrelated . '/sideload' );
		$request->set_param( 'upload_request', $upload_request->get_token() );
		$request->set_param( 'image_size', 'medium' );

		$response = rest_get_server()->dispatch( $request );

		$this->assertErrorResponse( 'upload_from_phone_unknown_attachment', $response, 403 );
	}

	/**
	 * @covers ::check_operation
	 */
	public function test_a_token_cannot_finalize_an_unrelated_attachment(): void {
		wp_set_current_user( self::$admin_id );

		$upload_request = $this->create_upload_request();

		$unrelated              = self::factory()->attachment->create_object(
			[
				'file'           => 'unrelated.jpg',
				'post_mime_type' => 'image/jpeg',
				'post_author'    => self::$admin_id,
			]
		);
		$this->attachment_ids[] = $unrelated;

		wp_set_current_user( 0 );

		$request = new WP_REST_Request( 'POST', self::MEDIA_ROUTE . '/' . $unrelated . '/finalize' );
		$request->set_param( 'upload_request', $upload_request->get_token() );

		$response = rest_get_server()->dispatch( $request );

		$this->assertErrorResponse( 'upload_from_phone_unknown_attachment', $response, 403 );
	}

	/**
	 * A file the browser is still cutting sizes for is not handed over yet.
	 *
	 * @covers ::record_attachment
	 */
	public function test_a_client_processed_file_is_withheld_until_it_is_finalized(): void {
		wp_set_current_user( self::$admin_id );

		$upload_request = $this->create_upload_request();

		wp_set_current_user( 0 );

		$response = $this->upload(
			$upload_request->get_token(),
			[ 'generate_sub_sizes' => false ]
		);

		$this->assertSame( 201, $response->get_status(), wp_json_encode( $response->get_data() ) );

		$attachment_id          = (int) $response->get_data()['id'];
		$this->attachment_ids[] = $attachment_id;

		$fresh = Upload_Request::get_by_token( $upload_request->get_token() );
		$this->assertNotNull( $fresh );

		$this->assertSame( [ $attachment_id ], $fresh->get_attachment_ids() );
		$this->assertSame( [ $attachment_id ], $fresh->get_pending_attachment_ids() );
		$this->assertSame( [], $fresh->get_ready_attachment_ids() );

		// Finalizing is what says the browser is done with it.
		$finalize = new WP_REST_Request( 'POST', self::MEDIA_ROUTE . '/' . $attachment_id . '/finalize' );
		$finalize->set_param( 'upload_request', $upload_request->get_token() );
		$finalize->set_param( 'sub_sizes', [] );

		$finalized = rest_get_server()->dispatch( $finalize );

		$this->assertFalse( $finalized->is_error(), wp_json_encode( $finalized->get_data() ) );

		$fresh = Upload_Request::get_by_token( $upload_request->get_token() );
		$this->assertNotNull( $fresh );
		$this->assertSame( [], $fresh->get_pending_attachment_ids() );
		$this->assertSame( [ $attachment_id ], $fresh->get_ready_attachment_ids() );
	}

	/**
	 * A file the server processed itself is finished the moment it is created.
	 *
	 * @covers ::record_attachment
	 */
	public function test_a_server_processed_file_is_ready_straight_away(): void {
		wp_set_current_user( self::$admin_id );

		$upload_request = $this->create_upload_request();

		wp_set_current_user( 0 );

		$response = $this->upload( $upload_request->get_token() );

		$this->assertSame( 201, $response->get_status(), wp_json_encode( $response->get_data() ) );

		$attachment_id          = (int) $response->get_data()['id'];
		$this->attachment_ids[] = $attachment_id;

		$fresh = Upload_Request::get_by_token( $upload_request->get_token() );
		$this->assertNotNull( $fresh );
		$this->assertSame( [], $fresh->get_pending_attachment_ids() );
		$this->assertSame( [ $attachment_id ], $fresh->get_ready_attachment_ids() );
	}

	/**
	 * The exception a token buys lasts one request and no longer.
	 *
	 * @covers ::revoke_access
	 */
	public function test_the_granted_capabilities_do_not_outlive_the_request(): void {
		wp_set_current_user( self::$admin_id );

		$upload_request = $this->create_upload_request();

		wp_set_current_user( 0 );

		$response = $this->upload( $upload_request->get_token() );

		$this->assertSame( 201, $response->get_status(), wp_json_encode( $response->get_data() ) );

		$this->attachment_ids[] = (int) $response->get_data()['id'];

		$this->assertFalse( current_user_can( 'upload_files' ) );

		// And a second upload without a token is refused as it was before.
		$this->assertSame( 401, $this->upload( '' )->get_status() );
	}

	/**
	 * Creates an upload request, asserting that it worked.
	 *
	 * @param array $args Optional. Arguments for the upload request.
	 * @return Upload_Request The upload request.
	 *
	 * @phpstan-param array{post?: int, allowed_types?: string[], accept?: string[], multiple?: bool} $args
	 */
	private function create_upload_request( array $args = [] ): Upload_Request {
		$upload_request = Upload_Request::create( $args );

		$this->assertInstanceOf( Upload_Request::class, $upload_request );

		return $upload_request;
	}

	/**
	 * Dispatches an upload of the test suite's sample image to core's endpoint.
	 *
	 * @param string $token        Upload request token, or an empty string to send none.
	 * @param array  $params       Optional. Extra parameters to send with it.
	 * @param array  $query_params Optional. Query parameters to send with it.
	 * @return \WP_REST_Response Response object.
	 *
	 * @phpstan-param array<string, mixed> $params
	 * @phpstan-param array<string, mixed> $query_params
	 */
	private function upload( string $token, array $params = [], array $query_params = [] ) {
		$request = new WP_REST_Request( 'POST', self::MEDIA_ROUTE );

		$request->set_header( 'Content-Type', 'image/jpeg' );
		$request->set_header( 'Content-Disposition', 'attachment; filename=canola.jpg' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a fixture off local disk.
		$request->set_body( file_get_contents( DIR_TESTDATA . '/images/canola.jpg' ) );

		if ( '' !== $token ) {
			$request->set_param( 'upload_request', $token );
		}

		foreach ( $params as $name => $value ) {
			$request->set_param( $name, $value );
		}

		if ( ! empty( $query_params ) ) {
			$request->set_query_params( $query_params );
		}

		return rest_get_server()->dispatch( $request );
	}
}
