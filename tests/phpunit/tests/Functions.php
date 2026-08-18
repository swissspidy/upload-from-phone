<?php
/**
 * Tests for the plugin functions.
 *
 * @package UploadFromPhone
 */

declare(strict_types = 1);

namespace UploadFromPhone\Tests;

use UploadFromPhone\Upload_Request;
use WP_UnitTestCase;

use function UploadFromPhone\delete_expired_requests;
use function UploadFromPhone\filter_cron_schedules;
use function UploadFromPhone\get_upload_page_data;

/**
 * Tests for the plugin's functions.
 */
class Test_Functions extends WP_UnitTestCase {
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
	 * @covers \UploadFromPhone\register_post_type
	 */
	public function test_post_type_is_registered_but_hidden(): void {
		$post_type = get_post_type_object( Upload_Request::POST_TYPE );

		$this->assertNotNull( $post_type );
		$this->assertFalse( $post_type->public );
		$this->assertFalse( $post_type->show_ui );
		$this->assertFalse( $post_type->show_in_rest );
		// The upload page has to be reachable by URL for the link to work at all.
		$this->assertTrue( $post_type->publicly_queryable );
		$this->assertTrue( $post_type->exclude_from_search );
	}

	/**
	 * @covers \UploadFromPhone\delete_expired_requests
	 */
	public function test_cleanup_deletes_only_expired_requests(): void {
		$fresh = Upload_Request::create( [] );
		$stale = Upload_Request::create( [] );

		$this->assertInstanceOf( Upload_Request::class, $fresh );
		$this->assertInstanceOf( Upload_Request::class, $stale );

		update_post_meta(
			$stale->get_post()->ID,
			Upload_Request::META_EXPIRES_AT,
			time() - HOUR_IN_SECONDS
		);

		delete_expired_requests();

		$this->assertNotNull( get_post( $fresh->get_post()->ID ) );
		$this->assertNull( get_post( $stale->get_post()->ID ) );
	}

	/**
	 * Media that has already arrived belongs to the post, not to the link, and
	 * must survive the link being cleaned up.
	 *
	 * @covers \UploadFromPhone\delete_expired_requests
	 */
	public function test_cleanup_leaves_uploaded_media_alone(): void {
		$attachment_id = self::factory()->attachment->create_object(
			[
				'file'           => 'image.jpg',
				'post_mime_type' => 'image/jpeg',
			]
		);

		$upload_request = Upload_Request::create( [] );
		$this->assertInstanceOf( Upload_Request::class, $upload_request );
		$upload_request->add_attachment( $attachment_id );

		update_post_meta(
			$upload_request->get_post()->ID,
			Upload_Request::META_EXPIRES_AT,
			time() - HOUR_IN_SECONDS
		);

		delete_expired_requests();

		$this->assertNull( get_post( $upload_request->get_post()->ID ) );
		$this->assertNotNull( get_post( $attachment_id ) );
	}

	/**
	 * @covers \UploadFromPhone\filter_cron_schedules
	 */
	public function test_cron_schedule_is_added(): void {
		$schedules = filter_cron_schedules( [] );

		$this->assertArrayHasKey( 'upload_from_phone_quarter_hourly', $schedules );
		$this->assertSame( 15 * MINUTE_IN_SECONDS, $schedules['upload_from_phone_quarter_hourly']['interval'] );
	}

	/**
	 * The upload page must not offer file types the request does not accept.
	 *
	 * @covers \UploadFromPhone\get_upload_page_data
	 */
	public function test_upload_page_data_is_limited_to_the_allowed_types(): void {
		$upload_request = Upload_Request::create( [ 'allowed_types' => [ 'image' ] ] );
		$this->assertInstanceOf( Upload_Request::class, $upload_request );

		$data = get_upload_page_data( $upload_request );

		$this->assertNotEmpty( $data['allowedMimeTypes'] );

		foreach ( $data['allowedMimeTypes'] as $mime_type ) {
			$this->assertStringStartsWith( 'image/', $mime_type );
		}

		$this->assertSame( [ 'image' ], $data['allowedTypes'] );
		$this->assertStringContainsString( $upload_request->get_token(), $data['restUrl'] );
	}

	/**
	 * @covers \UploadFromPhone\get_upload_page_data
	 */
	public function test_upload_page_data_allows_everything_when_unrestricted(): void {
		$upload_request = Upload_Request::create( [] );
		$this->assertInstanceOf( Upload_Request::class, $upload_request );

		$data = get_upload_page_data( $upload_request );

		$this->assertSame( get_allowed_mime_types( self::$admin_id ), $data['allowedMimeTypes'] );
	}
}
