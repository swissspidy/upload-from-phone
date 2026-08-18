<?php
/**
 * Tests for the Upload_Request class.
 *
 * @package UploadFromPhone
 */

declare(strict_types = 1);

namespace UploadFromPhone\Tests;

use UploadFromPhone\Upload_Request;
use WP_UnitTestCase;

/**
 * @coversDefaultClass \UploadFromPhone\Upload_Request
 */
class Test_Upload_Request extends WP_UnitTestCase {
	/**
	 * Administrator user ID.
	 *
	 * @var int
	 */
	protected static int $admin_id;

	/**
	 * Sets up shared fixtures.
	 *
	 * @param \WP_UnitTest_Factory $factory Factory.
	 * @return void
	 */
	public static function wpSetUpBeforeClass( $factory ): void {
		self::$admin_id = $factory->user->create( [ 'role' => 'administrator' ] );
	}

	/**
	 * Sets up each test.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		wp_set_current_user( self::$admin_id );
	}

	/**
	 * @covers ::create
	 * @covers ::get_token
	 */
	public function test_token_is_32_hex_characters(): void {
		$request = Upload_Request::create( [] );

		$this->assertInstanceOf( Upload_Request::class, $request );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{32}$/', $request->get_token() );
	}

	/**
	 * Two requests created back to back must not share a token, and the token
	 * must not be derived from the clock — that is what `uniqid()` got wrong.
	 *
	 * @covers ::create
	 */
	public function test_tokens_are_unique(): void {
		$tokens = [];

		for ( $i = 0; $i < 10; $i++ ) {
			$request = Upload_Request::create( [] );
			$this->assertInstanceOf( Upload_Request::class, $request );
			$tokens[] = $request->get_token();
		}

		$this->assertSame( $tokens, array_unique( $tokens ) );
	}

	/**
	 * @covers ::get_by_token
	 */
	public function test_get_by_token_returns_request(): void {
		$request = Upload_Request::create( [] );
		$this->assertInstanceOf( Upload_Request::class, $request );

		$found = Upload_Request::get_by_token( $request->get_token() );

		$this->assertInstanceOf( Upload_Request::class, $found );
		$this->assertSame( $request->get_token(), $found->get_token() );
	}

	/**
	 * Expiry has to be enforced when the token is used, not only by cron:
	 * cron does not reliably run on a quiet site.
	 *
	 * @covers ::get_by_token
	 * @covers ::is_expired
	 */
	public function test_get_by_token_ignores_expired_requests(): void {
		$request = Upload_Request::create( [] );
		$this->assertInstanceOf( Upload_Request::class, $request );

		update_post_meta(
			$request->get_post()->ID,
			Upload_Request::META_EXPIRES_AT,
			time() - 1
		);

		$this->assertTrue( $request->is_expired() );
		$this->assertNull( Upload_Request::get_by_token( $request->get_token() ) );
	}

	/**
	 * @covers ::get_by_token
	 */
	public function test_get_by_token_rejects_malformed_tokens(): void {
		$this->assertNull( Upload_Request::get_by_token( '' ) );
		$this->assertNull( Upload_Request::get_by_token( 'not-a-token' ) );
		$this->assertNull( Upload_Request::get_by_token( str_repeat( 'z', 32 ) ) );
	}

	/**
	 * @covers ::get_max_files
	 * @covers ::allows_multiple
	 */
	public function test_single_file_requests_accept_exactly_one_file(): void {
		$request = Upload_Request::create( [ 'multiple' => false ] );
		$this->assertInstanceOf( Upload_Request::class, $request );

		$this->assertFalse( $request->allows_multiple() );
		$this->assertSame( 1, $request->get_max_files() );
	}

	/**
	 * @covers ::is_complete
	 * @covers ::add_attachment
	 */
	public function test_request_is_complete_once_full(): void {
		$request = Upload_Request::create( [ 'multiple' => false ] );
		$this->assertInstanceOf( Upload_Request::class, $request );

		$this->assertFalse( $request->is_complete() );

		$request->add_attachment( 123 );

		$this->assertTrue( $request->is_complete() );
		$this->assertSame( [ 123 ], $request->get_attachment_ids() );
	}

	/**
	 * @covers ::get_ttl
	 */
	public function test_ttl_is_filterable_but_never_shorter_than_a_minute(): void {
		$this->assertSame( Upload_Request::DEFAULT_TTL, Upload_Request::get_ttl() );

		add_filter( 'upload_from_phone_request_ttl', static fn () => 5 * MINUTE_IN_SECONDS );
		$this->assertSame( 5 * MINUTE_IN_SECONDS, Upload_Request::get_ttl() );

		remove_all_filters( 'upload_from_phone_request_ttl' );

		add_filter( 'upload_from_phone_request_ttl', '__return_zero' );
		$this->assertSame( MINUTE_IN_SECONDS, Upload_Request::get_ttl() );
	}

	/**
	 * @covers ::get_parent
	 */
	public function test_request_remembers_its_post(): void {
		$post_id = self::factory()->post->create();

		$request = Upload_Request::create( [ 'post' => $post_id ] );
		$this->assertInstanceOf( Upload_Request::class, $request );

		$parent = $request->get_parent();

		$this->assertNotNull( $parent );
		$this->assertSame( $post_id, $parent->ID );
	}
}
