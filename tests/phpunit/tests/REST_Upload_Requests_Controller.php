<?php
/**
 * Tests for the REST API controller.
 *
 * @package UploadFromPhone
 */

declare(strict_types = 1);

namespace UploadFromPhone\Tests;

use UploadFromPhone\Upload_Request;
use WP_REST_Request;
use WP_Test_REST_TestCase;

/**
 * @coversDefaultClass \UploadFromPhone\REST_Upload_Requests_Controller
 */
class Test_REST_Upload_Requests_Controller extends WP_Test_REST_TestCase {
	/**
	 * REST route base.
	 */
	private const ROUTE = '/upload-from-phone/v1/upload-requests';

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
	 * Subscriber user ID.
	 *
	 * @var int
	 */
	protected static int $subscriber_id;

	/**
	 * Sets up shared fixtures.
	 *
	 * @param \WP_UnitTest_Factory $factory Factory.
	 * @return void
	 */
	public static function wpSetUpBeforeClass( $factory ): void {
		self::$admin_id      = $factory->user->create( [ 'role' => 'administrator' ] );
		self::$author_id     = $factory->user->create( [ 'role' => 'author' ] );
		self::$subscriber_id = $factory->user->create( [ 'role' => 'subscriber' ] );
	}

	/**
	 * Sets up each test.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		do_action( 'rest_api_init' );
	}

	/**
	 * @covers ::register_routes
	 */
	public function test_routes_are_registered(): void {
		$routes = rest_get_server()->get_routes();

		$this->assertArrayHasKey( self::ROUTE, $routes );
		$this->assertArrayHasKey( self::ROUTE . '/(?P<token>[a-f0-9]{32})', $routes );
		$this->assertArrayHasKey( self::ROUTE . '/(?P<token>[a-f0-9]{32})/media', $routes );
	}

	/**
	 * @covers ::create_item_permissions_check
	 */
	public function test_creating_a_request_requires_upload_permission(): void {
		wp_set_current_user( self::$subscriber_id );

		$response = rest_get_server()->dispatch( new WP_REST_Request( 'POST', self::ROUTE ) );

		$this->assertErrorResponse( 'upload_from_phone_cannot_create', $response, 403 );
	}

	/**
	 * @covers ::create_item_permissions_check
	 */
	public function test_creating_a_request_is_refused_for_logged_out_visitors(): void {
		wp_set_current_user( 0 );

		$response = rest_get_server()->dispatch( new WP_REST_Request( 'POST', self::ROUTE ) );

		$this->assertErrorResponse( 'upload_from_phone_cannot_create', $response, 401 );
	}

	/**
	 * An upload link must not become a way to attach media to somebody else's post.
	 *
	 * @covers ::create_item_permissions_check
	 */
	public function test_creating_a_request_for_an_uneditable_post_is_refused(): void {
		$post_id = self::factory()->post->create( [ 'post_author' => self::$admin_id ] );

		wp_set_current_user( self::$author_id );

		$request = new WP_REST_Request( 'POST', self::ROUTE );
		$request->set_param( 'post', $post_id );

		$response = rest_get_server()->dispatch( $request );

		$this->assertErrorResponse( 'upload_from_phone_cannot_edit', $response, 403 );
	}

	/**
	 * @covers ::create_item
	 */
	public function test_creating_a_request_returns_a_token_and_url(): void {
		wp_set_current_user( self::$admin_id );

		$request = new WP_REST_Request( 'POST', self::ROUTE );
		$request->set_param( 'allowed_types', [ 'image' ] );
		$request->set_param( 'multiple', true );

		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 201, $response->get_status() );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{32}$/', $data['token'] );
		$this->assertNotEmpty( $data['url'] );
		$this->assertGreaterThan( time(), $data['expires_at'] );
		$this->assertSame( [ 'image' ], $data['allowed_types'] );
		$this->assertTrue( $data['multiple'] );
		$this->assertSame( [], $data['attachments'] );
	}

	/**
	 * @covers ::get_item_permissions_check
	 */
	public function test_reading_someone_elses_request_is_refused(): void {
		wp_set_current_user( self::$admin_id );
		$upload_request = Upload_Request::create( [] );
		$this->assertInstanceOf( Upload_Request::class, $upload_request );

		wp_set_current_user( self::$author_id );

		$response = rest_get_server()->dispatch(
			new WP_REST_Request( 'GET', self::ROUTE . '/' . $upload_request->get_token() )
		);

		$this->assertErrorResponse( 'upload_from_phone_cannot_read', $response, 403 );
	}

	/**
	 * @covers ::get_item
	 */
	public function test_reading_own_request_returns_its_status(): void {
		wp_set_current_user( self::$author_id );

		$upload_request = Upload_Request::create( [] );
		$this->assertInstanceOf( Upload_Request::class, $upload_request );

		$response = rest_get_server()->dispatch(
			new WP_REST_Request( 'GET', self::ROUTE . '/' . $upload_request->get_token() )
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $upload_request->get_token(), $response->get_data()['token'] );
		$this->assertFalse( $response->get_data()['complete'] );
	}

	/**
	 * An unknown token and an inaccessible one must look identical from outside,
	 * so the endpoint cannot be used to find out which tokens exist.
	 *
	 * @covers ::get_item_permissions_check
	 */
	public function test_unknown_tokens_are_reported_as_not_found(): void {
		wp_set_current_user( self::$admin_id );

		$response = rest_get_server()->dispatch(
			new WP_REST_Request( 'GET', self::ROUTE . '/' . str_repeat( 'a', 32 ) )
		);

		$this->assertErrorResponse( 'upload_from_phone_invalid_token', $response, 404 );
	}

	/**
	 * @covers ::delete_item
	 */
	public function test_deleting_a_request_revokes_the_link(): void {
		wp_set_current_user( self::$author_id );

		$upload_request = Upload_Request::create( [] );
		$this->assertInstanceOf( Upload_Request::class, $upload_request );
		$token = $upload_request->get_token();

		$response = rest_get_server()->dispatch(
			new WP_REST_Request( 'DELETE', self::ROUTE . '/' . $token )
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['deleted'] );
		$this->assertNull( Upload_Request::get_by_token( $token ) );
	}

	/**
	 * @covers ::upload_item_permissions_check
	 */
	public function test_uploading_with_an_unknown_token_is_refused(): void {
		wp_set_current_user( 0 );

		$response = rest_get_server()->dispatch(
			new WP_REST_Request( 'POST', self::ROUTE . '/' . str_repeat( 'b', 32 ) . '/media' )
		);

		$this->assertErrorResponse( 'upload_from_phone_invalid_token', $response, 404 );
	}

	/**
	 * @covers ::upload_item_permissions_check
	 */
	public function test_uploading_with_an_expired_token_is_refused(): void {
		wp_set_current_user( self::$admin_id );

		$upload_request = Upload_Request::create( [] );
		$this->assertInstanceOf( Upload_Request::class, $upload_request );

		update_post_meta(
			$upload_request->get_post()->ID,
			Upload_Request::META_EXPIRES_AT,
			time() - 1
		);

		wp_set_current_user( 0 );

		$response = rest_get_server()->dispatch(
			new WP_REST_Request( 'POST', self::ROUTE . '/' . $upload_request->get_token() . '/media' )
		);

		$this->assertErrorResponse( 'upload_from_phone_invalid_token', $response, 404 );
	}

	/**
	 * @covers ::upload_item_permissions_check
	 */
	public function test_uploading_to_a_full_request_is_refused(): void {
		wp_set_current_user( self::$admin_id );

		$upload_request = Upload_Request::create( [ 'multiple' => false ] );
		$this->assertInstanceOf( Upload_Request::class, $upload_request );
		$upload_request->add_attachment( 42 );

		wp_set_current_user( 0 );

		$response = rest_get_server()->dispatch(
			new WP_REST_Request( 'POST', self::ROUTE . '/' . $upload_request->get_token() . '/media' )
		);

		$this->assertErrorResponse( 'upload_from_phone_request_complete', $response, 409 );
	}

	/**
	 * The link is only as good as the account behind it. If the person who
	 * created it loses the ability to upload, so does the link.
	 *
	 * @covers ::upload_item_permissions_check
	 */
	public function test_uploading_is_refused_once_the_author_loses_permission(): void {
		wp_set_current_user( self::$author_id );

		$upload_request = Upload_Request::create( [] );
		$this->assertInstanceOf( Upload_Request::class, $upload_request );

		$user = get_userdata( self::$author_id );
		$user->set_role( 'subscriber' );

		wp_set_current_user( 0 );

		$response = rest_get_server()->dispatch(
			new WP_REST_Request( 'POST', self::ROUTE . '/' . $upload_request->get_token() . '/media' )
		);

		$this->assertErrorResponse( 'upload_from_phone_cannot_upload', $response, 403 );

		$user->set_role( 'author' );
	}

	/**
	 * @covers ::upload_item
	 */
	public function test_uploading_without_a_file_is_refused(): void {
		wp_set_current_user( self::$admin_id );

		$upload_request = Upload_Request::create( [] );
		$this->assertInstanceOf( Upload_Request::class, $upload_request );

		wp_set_current_user( 0 );

		$request = new WP_REST_Request( 'POST', self::ROUTE . '/' . $upload_request->get_token() . '/media' );
		$request->set_param( 'filename', 'canola.jpg' );
		$request->set_header( 'content_type', 'image/jpeg' );

		$response = rest_get_server()->dispatch( $request );

		$this->assertErrorResponse( 'upload_from_phone_no_file', $response, 400 );
	}

	/**
	 * @covers ::upload_item
	 */
	public function test_uploading_without_a_filename_is_refused(): void {
		wp_set_current_user( self::$admin_id );

		$upload_request = Upload_Request::create( [] );
		$this->assertInstanceOf( Upload_Request::class, $upload_request );

		wp_set_current_user( 0 );

		$request = new WP_REST_Request( 'POST', self::ROUTE . '/' . $upload_request->get_token() . '/media' );
		$request->set_header( 'content_type', 'image/jpeg' );
		$request->set_body( 'not empty' );

		$response = rest_get_server()->dispatch( $request );

		$this->assertErrorResponse( 'rest_missing_callback_param', $response, 400 );
	}

	/**
	 * @covers ::upload_item
	 */
	public function test_uploading_a_file_over_the_size_limit_is_refused(): void {
		wp_set_current_user( self::$admin_id );

		$upload_request = Upload_Request::create( [] );
		$this->assertInstanceOf( Upload_Request::class, $upload_request );

		wp_set_current_user( 0 );

		add_filter( 'upload_size_limit', static fn () => 8 );

		$response = $this->upload_test_image( $upload_request->get_token() );

		remove_all_filters( 'upload_size_limit' );

		$this->assertErrorResponse( 'upload_from_phone_file_too_large', $response, 413 );
	}

	/**
	 * @covers ::upload_item
	 */
	public function test_uploaded_file_is_attached_to_the_post_and_the_request(): void {
		wp_set_current_user( self::$admin_id );

		$post_id = self::factory()->post->create( [ 'post_author' => self::$admin_id ] );

		$upload_request = Upload_Request::create( [ 'post' => $post_id ] );
		$this->assertInstanceOf( Upload_Request::class, $upload_request );

		wp_set_current_user( 0 );

		$response = $this->upload_test_image( $upload_request->get_token() );

		$this->assertSame( 201, $response->get_status(), wp_json_encode( $response->get_data() ) );

		$attachment_id = $response->get_data()['id'];
		$attachment    = get_post( $attachment_id );

		$this->assertNotNull( $attachment );
		$this->assertSame( $post_id, $attachment->post_parent );
		// Attributed to whoever asked for the upload, not to nobody.
		$this->assertSame( self::$admin_id, (int) $attachment->post_author );

		$fresh = Upload_Request::get_by_token( $upload_request->get_token() );
		$this->assertNotNull( $fresh );
		$this->assertSame( [ $attachment_id ], $fresh->get_attachment_ids() );
		$this->assertTrue( $fresh->is_complete() );

		wp_delete_attachment( $attachment_id, true );
	}

	/**
	 * @covers ::upload_item
	 */
	public function test_uploaded_file_must_match_the_allowed_types(): void {
		wp_set_current_user( self::$admin_id );

		$upload_request = Upload_Request::create( [ 'allowed_types' => [ 'video' ] ] );
		$this->assertInstanceOf( Upload_Request::class, $upload_request );

		wp_set_current_user( 0 );

		$response = $this->upload_test_image( $upload_request->get_token() );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'upload_from_phone_upload_error', $response->get_data()['code'] );
	}

	/**
	 * Dispatches an upload of the test suite's sample image.
	 *
	 * @param string $token    Upload request token.
	 * @param string $filename Optional. File name to upload as.
	 * @return \WP_REST_Response Response object.
	 */
	private function upload_test_image( string $token, string $filename = 'canola.jpg' ) {
		$request = new WP_REST_Request( 'POST', self::ROUTE . '/' . $token . '/media' );
		$request->set_param( 'filename', $filename );
		$request->set_header( 'content_type', 'image/jpeg' );
		$request->set_body( file_get_contents( DIR_TESTDATA . '/images/canola.jpg' ) );

		return rest_get_server()->dispatch( $request );
	}
}
