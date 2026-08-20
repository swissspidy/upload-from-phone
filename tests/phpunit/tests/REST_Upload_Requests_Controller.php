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

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Firing a core hook, not declaring one.
		do_action( 'rest_api_init' );
	}

	/**
	 * @covers ::register_routes
	 */
	public function test_routes_are_registered(): void {
		$routes = rest_get_server()->get_routes();

		$this->assertArrayHasKey( self::ROUTE, $routes );
		$this->assertArrayHasKey( self::ROUTE . '/(?P<token>[a-f0-9]{32})', $routes );

		// Files go to core's media endpoints, not to one of ours.
		$this->assertArrayNotHasKey( self::ROUTE . '/(?P<token>[a-f0-9]{32})/media', $routes );
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

}
