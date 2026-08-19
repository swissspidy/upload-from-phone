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
use function UploadFromPhone\enqueue_block_editor_assets;
use function UploadFromPhone\filter_cron_schedules;
use function UploadFromPhone\filter_template_include;
use function UploadFromPhone\get_asset_meta;
use function UploadFromPhone\get_upload_page_data;
use function UploadFromPhone\has_client_side_processing;
use function UploadFromPhone\print_upload_page_assets;
use function UploadFromPhone\register_assets;

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
	 * Whether the client-side processing path needs tearing down.
	 *
	 * @var bool
	 */
	private bool $reset_client_side_processing = false;

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
	 * The mime type list is only there for the client-side processing queue, so
	 * a page that is not running that queue has no reason to be handed one.
	 *
	 * @covers \UploadFromPhone\get_upload_page_data
	 */
	public function test_upload_page_data_omits_mime_types_by_default(): void {
		$upload_request = Upload_Request::create( [ 'allowed_types' => [ 'image' ] ] );
		$this->assertInstanceOf( Upload_Request::class, $upload_request );

		$data = get_upload_page_data( $upload_request );

		$this->assertNull( $data['allowedMimeTypes'] );
		$this->assertSame( [ 'image' ], $data['allowedTypes'] );
		$this->assertStringContainsString( $upload_request->get_token(), $data['restUrl'] );
	}

	/**
	 * The upload page must not offer file types the request does not accept.
	 *
	 * @covers \UploadFromPhone\get_upload_page_data
	 */
	public function test_upload_page_data_is_limited_to_the_allowed_types(): void {
		$this->enable_client_side_processing();

		$upload_request = Upload_Request::create( [ 'allowed_types' => [ 'image' ] ] );
		$this->assertInstanceOf( Upload_Request::class, $upload_request );

		$data = get_upload_page_data( $upload_request );

		$this->assertNotEmpty( $data['allowedMimeTypes'] );

		foreach ( $data['allowedMimeTypes'] as $mime_type ) {
			$this->assertStringStartsWith( 'image/', $mime_type );
		}
	}

	/**
	 * @covers \UploadFromPhone\get_upload_page_data
	 */
	public function test_upload_page_data_allows_everything_when_unrestricted(): void {
		$this->enable_client_side_processing();

		$upload_request = Upload_Request::create( [] );
		$this->assertInstanceOf( Upload_Request::class, $upload_request );

		$data = get_upload_page_data( $upload_request );

		$this->assertSame( get_allowed_mime_types( self::$admin_id ), $data['allowedMimeTypes'] );
	}

	/**
	 * @covers \UploadFromPhone\register_assets
	 * @covers \UploadFromPhone\get_asset_meta
	 */
	public function test_register_assets_registers_scripts_and_styles(): void {
		// register_assets() already ran once via the `init` hook fired during
		// bootstrap — wp_register_script()/wp_register_style() are no-ops for an
		// already-registered handle, so this would pass on bootstrap state alone
		// without deregistering first.
		wp_deregister_script( 'upload-from-phone-editor' );
		wp_deregister_script( 'upload-from-phone-view' );
		wp_deregister_style( 'upload-from-phone-editor' );
		wp_deregister_style( 'upload-from-phone-view' );

		register_assets();

		$this->assertTrue( wp_script_is( 'upload-from-phone-editor', 'registered' ) );
		$this->assertTrue( wp_script_is( 'upload-from-phone-view', 'registered' ) );
		$this->assertTrue( wp_style_is( 'upload-from-phone-editor', 'registered' ) );
		$this->assertTrue( wp_style_is( 'upload-from-phone-view', 'registered' ) );
	}

	/**
	 * The upload page routes its media queue through the global rather than an
	 * import, so the dependency has to be added by hand whenever the queue is
	 * actually in play.
	 *
	 * @covers \UploadFromPhone\register_assets
	 */
	public function test_register_assets_adds_upload_media_dependency_when_client_side_processing_is_on(): void {
		$this->enable_client_side_processing();

		// register_assets() already ran once via the `init` hook fired during
		// bootstrap, before the filter above existed — wp_register_script() is
		// a no-op for an already-registered handle, so the handle has to be
		// deregistered first or this would still see the original, filterless
		// dependency list.
		wp_deregister_script( 'upload-from-phone-view' );

		register_assets();

		$view = wp_scripts()->registered['upload-from-phone-view'];

		$this->assertContains( 'wp-data', $view->deps );
		$this->assertContains( 'wp-upload-media', $view->deps );
	}

	/**
	 * @covers \UploadFromPhone\get_asset_meta
	 */
	public function test_get_asset_meta_falls_back_when_the_build_file_is_missing(): void {
		$meta = get_asset_meta( 'does-not-exist' );

		$this->assertSame( [], $meta['dependencies'] );
		$this->assertSame( \UploadFromPhone\VERSION, $meta['version'] );
	}

	/**
	 * @covers \UploadFromPhone\has_client_side_processing
	 */
	public function test_client_side_processing_is_off_by_default(): void {
		$this->assertFalse( has_client_side_processing() );
	}

	/**
	 * The filter alone must not be enough — the queue script has to actually be
	 * registered, or turning on the filter would enqueue a dependency on nothing.
	 *
	 * @covers \UploadFromPhone\has_client_side_processing
	 */
	public function test_client_side_processing_requires_both_the_filter_and_the_script(): void {
		add_filter( 'upload_from_phone_client_side_processing', '__return_true' );

		try {
			$this->assertFalse( has_client_side_processing() );
		} finally {
			remove_filter( 'upload_from_phone_client_side_processing', '__return_true' );
		}

		$this->enable_client_side_processing();
		$this->assertTrue( has_client_side_processing() );
	}

	/**
	 * @covers \UploadFromPhone\enqueue_block_editor_assets
	 */
	public function test_block_editor_assets_require_upload_permission(): void {
		register_assets();

		$subscriber_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $subscriber_id );

		enqueue_block_editor_assets();

		$this->assertFalse( wp_script_is( 'upload-from-phone-editor', 'enqueued' ) );
		$this->assertFalse( wp_style_is( 'upload-from-phone-editor', 'enqueued' ) );

		wp_set_current_user( self::$admin_id );

		enqueue_block_editor_assets();

		$this->assertTrue( wp_script_is( 'upload-from-phone-editor', 'enqueued' ) );
		$this->assertTrue( wp_style_is( 'upload-from-phone-editor', 'enqueued' ) );

		wp_dequeue_script( 'upload-from-phone-editor' );
		wp_dequeue_style( 'upload-from-phone-editor' );
	}

	/**
	 * @covers \UploadFromPhone\print_upload_page_assets
	 */
	public function test_upload_page_assets_are_only_printed_once_registered(): void {
		wp_deregister_script( 'upload-from-phone-view' );

		ob_start();
		print_upload_page_assets();
		$this->assertSame( '', ob_get_clean() );

		register_assets();

		ob_start();
		print_upload_page_assets();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'upload-from-phone-view', $output );
	}

	/**
	 * A request reached through its own link resolves to the upload page.
	 *
	 * @covers \UploadFromPhone\filter_template_include
	 */
	public function test_template_include_serves_the_upload_page_for_a_matching_token(): void {
		$upload_request = Upload_Request::create( [] );
		$this->assertInstanceOf( Upload_Request::class, $upload_request );

		$this->go_to( $upload_request->get_url() );

		$template = filter_template_include( 'placeholder.php' );

		$this->assertStringContainsString( 'templates/upload-page.php', $template );
		$this->assertFalse( is_404() );
	}

	/**
	 * WordPress will resolve any publicly queryable post by ID. Reaching a live
	 * upload request that way — rather than by following its link — must 404,
	 * or the token stops being the thing that grants access.
	 *
	 * @covers \UploadFromPhone\filter_template_include
	 */
	public function test_template_include_404s_when_the_post_is_reached_by_id(): void {
		$upload_request = Upload_Request::create( [] );
		$this->assertInstanceOf( Upload_Request::class, $upload_request );

		$this->go_to(
			add_query_arg(
				[
					'p'         => $upload_request->get_post()->ID,
					'post_type' => Upload_Request::POST_TYPE,
				],
				home_url( '/' )
			)
		);

		$template = filter_template_include( 'placeholder.php' );

		$this->assertSame( get_404_template(), $template );
		$this->assertTrue( is_404() );
	}

	/**
	 * @covers \UploadFromPhone\filter_template_include
	 */
	public function test_template_include_leaves_unrelated_requests_alone(): void {
		$this->go_to( home_url( '/' ) );

		$this->assertSame( 'placeholder.php', filter_template_include( 'placeholder.php' ) );
	}

	/**
	 * Turns on the client-side processing path for the duration of a test.
	 *
	 * The filter alone is not enough: the queue is only used where WordPress
	 * actually registers it, which a bare test install does not.
	 *
	 * @return void
	 */
	private function enable_client_side_processing(): void {
		wp_register_script( 'wp-upload-media', 'https://example.org/upload-media.js', [], '1.0', true );

		add_filter( 'upload_from_phone_client_side_processing', '__return_true' );

		$this->reset_client_side_processing = true;
	}

	/**
	 * Tears down each test.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		if ( $this->reset_client_side_processing ) {
			remove_filter( 'upload_from_phone_client_side_processing', '__return_true' );
			wp_deregister_script( 'wp-upload-media' );

			$this->reset_client_side_processing = false;
		}

		parent::tear_down();
	}
}
