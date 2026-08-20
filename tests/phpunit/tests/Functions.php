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
use function UploadFromPhone\get_chromium_major_version;
use function UploadFromPhone\get_upload_page_data;
use function UploadFromPhone\has_client_side_processing;
use function UploadFromPhone\print_upload_page_assets;
use function UploadFromPhone\register_assets;
use function UploadFromPhone\send_cross_origin_isolation_headers;

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
	 * Whether enable_client_side_processing() registered `wp-upload-media`
	 * itself, as opposed to it already being registered (e.g. by WordPress
	 * core, which bundles it as of WP 7.1) before the test ran.
	 *
	 * @var bool
	 */
	private bool $registered_wp_upload_media_script = false;

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
	 * The image settings are only there for the client-side processing queue,
	 * so a page that is not running that queue has no reason to carry them.
	 *
	 * @covers \UploadFromPhone\get_upload_page_data
	 */
	public function test_upload_page_data_omits_image_settings_by_default(): void {
		$upload_request = Upload_Request::create( [ 'allowed_types' => [ 'image' ] ] );
		$this->assertInstanceOf( Upload_Request::class, $upload_request );

		$data = get_upload_page_data( $upload_request );

		$this->assertFalse( $data['clientSide'] );
		$this->assertArrayNotHasKey( 'allImageSizes', $data );
		$this->assertArrayNotHasKey( 'bigImageSizeThreshold', $data );

		$this->assertSame( [ 'image' ], $data['allowedTypes'] );
		$this->assertNotEmpty( $data['allowedMimeTypes'] );
		$this->assertStringContainsString( 'wp/v2/media', $data['mediaUrl'] );
	}

	/**
	 * Without the registered sizes the queue would upload the file whole and
	 * silently skip the cropping, so they have to travel with the page.
	 *
	 * @covers \UploadFromPhone\get_upload_page_data
	 * @covers \UploadFromPhone\get_all_image_sizes
	 */
	public function test_upload_page_data_carries_the_image_settings_for_the_queue(): void {
		$this->enable_client_side_processing();

		$upload_request = Upload_Request::create( [] );
		$this->assertInstanceOf( Upload_Request::class, $upload_request );

		$data = get_upload_page_data( $upload_request );

		$this->assertTrue( $data['clientSide'] );
		$this->assertNotEmpty( $data['allImageSizes'] );
		$this->assertIsInt( $data['bigImageSizeThreshold'] );
		$this->assertIsBool( $data['imageStripMeta'] );
		$this->assertIsInt( $data['imageMaxBitDepth'] );

		$this->assertArrayHasKey( 'thumbnail', $data['allImageSizes'] );
		$this->assertSame( 'thumbnail', $data['allImageSizes']['thumbnail']['name'] );
		$this->assertIsInt( $data['allImageSizes']['thumbnail']['width'] );
		$this->assertIsInt( $data['allImageSizes']['thumbnail']['height'] );
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
	 * The upload page follows WordPress. Where core reports client-side media
	 * processing available, the phone gets it too, without anyone opting in.
	 *
	 * @covers \UploadFromPhone\has_client_side_processing
	 */
	public function test_client_side_processing_is_on_where_wordpress_offers_it(): void {
		$this->enable_client_side_processing();

		remove_filter( 'upload_from_phone_client_side_processing', '__return_true' );

		$this->assertTrue( has_client_side_processing() );
	}

	/**
	 * Core reports the pipeline unavailable outside a secure context, because
	 * `SharedArrayBuffer` is not handed out there. The upload page has no
	 * business overriding that.
	 *
	 * @covers \UploadFromPhone\has_client_side_processing
	 */
	public function test_client_side_processing_follows_wordpress_when_it_says_no(): void {
		$this->enable_client_side_processing();

		add_filter( 'wp_client_side_media_processing_enabled', '__return_false', 20 );

		try {
			$this->assertFalse( has_client_side_processing() );
		} finally {
			remove_filter( 'wp_client_side_media_processing_enabled', '__return_false', 20 );
		}
	}

	/**
	 * @covers \UploadFromPhone\has_client_side_processing
	 */
	public function test_client_side_processing_can_be_turned_off(): void {
		$this->enable_client_side_processing();

		remove_filter( 'upload_from_phone_client_side_processing', '__return_true' );
		add_filter( 'upload_from_phone_client_side_processing', '__return_false' );

		try {
			$this->assertFalse( has_client_side_processing() );
		} finally {
			remove_filter( 'upload_from_phone_client_side_processing', '__return_false' );
		}
	}

	/**
	 * The filter alone must not be enough — the queue script has to actually be
	 * registered, or turning on the filter would enqueue a dependency on nothing.
	 *
	 * @covers \UploadFromPhone\has_client_side_processing
	 */
	public function test_client_side_processing_requires_the_script(): void {
		// WordPress bundles wp-upload-media as a core script as of WP 7.1, so it
		// may already be registered here — deregister it to actually exercise
		// the "filter without the script" branch, and restore the original
		// registration afterwards rather than guess at its parameters.
		$original_registration = wp_scripts()->registered['wp-upload-media'] ?? null;

		if ( $original_registration ) {
			wp_deregister_script( 'wp-upload-media' );
		}

		add_filter( 'wp_client_side_media_processing_enabled', '__return_true' );
		add_filter( 'upload_from_phone_client_side_processing', '__return_true' );

		try {
			$this->assertFalse( has_client_side_processing() );
		} finally {
			remove_filter( 'wp_client_side_media_processing_enabled', '__return_true' );
			remove_filter( 'upload_from_phone_client_side_processing', '__return_true' );

			if ( $original_registration ) {
				wp_scripts()->registered['wp-upload-media'] = $original_registration;
			}
		}
	}

	/**
	 * Cross-origin isolation is what makes `SharedArrayBuffer` available, and
	 * without it the pipeline loads but quietly does no image work at all.
	 *
	 * @dataProvider data_chromium_user_agents
	 * @covers \UploadFromPhone\get_chromium_major_version
	 *
	 * @param string   $user_agent User agent string.
	 * @param int|null $expected   Expected major version.
	 * @return void
	 */
	public function test_chromium_version_is_read_from_the_user_agent( string $user_agent, ?int $expected ): void {
		$original = $_SERVER['HTTP_USER_AGENT'] ?? null;

		$_SERVER['HTTP_USER_AGENT'] = $user_agent;

		try {
			$this->assertSame( $expected, get_chromium_major_version() );
		} finally {
			if ( null === $original ) {
				unset( $_SERVER['HTTP_USER_AGENT'] );
			} else {
				$_SERVER['HTTP_USER_AGENT'] = $original;
			}
		}
	}

	/**
	 * Data provider.
	 *
	 * @return array<string, array{0: string, 1: int|null}>
	 */
	public function data_chromium_user_agents(): array {
		return [
			'Chrome on Android'  => [
				'Mozilla/5.0 (Linux; Android 14) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Mobile Safari/537.36',
				140,
			],
			'Chrome before DIP'  => [
				'Mozilla/5.0 (Linux; Android 12) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Mobile Safari/537.36',
				120,
			],
			'Safari on iOS'      => [
				'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1',
				null,
			],
			'nothing at all'     => [ '', null ],
		];
	}

	/**
	 * @covers \UploadFromPhone\send_cross_origin_isolation_headers
	 */
	public function test_no_isolation_headers_without_client_side_processing(): void {
		// Nothing to isolate for: the page is not going to process anything.
		$this->assertFalse( has_client_side_processing() );

		send_cross_origin_isolation_headers();

		$this->assertSame( [], $this->get_isolation_headers() );
	}

	/**
	 * @covers \UploadFromPhone\send_cross_origin_isolation_headers
	 */
	public function test_isolation_headers_can_be_turned_off(): void {
		$this->enable_client_side_processing();

		add_filter( 'upload_from_phone_cross_origin_isolation', '__return_false' );

		try {
			send_cross_origin_isolation_headers();

			$this->assertSame( [], $this->get_isolation_headers() );
		} finally {
			remove_filter( 'upload_from_phone_cross_origin_isolation', '__return_false' );
		}
	}

	/**
	 * Returns the cross-origin isolation headers sent so far.
	 *
	 * PHPUnit runs without a web server, so `header()` records rather than
	 * sends and `headers_list()` is empty; the real assertions about which
	 * header goes to which browser live in the end-to-end tests, which can see
	 * a response. What can be checked here is that nothing is sent when
	 * nothing should be.
	 *
	 * @return string[] Header lines.
	 */
	private function get_isolation_headers(): array {
		if ( ! function_exists( 'xdebug_get_headers' ) ) {
			$this->markTestSkipped( 'Requires Xdebug to inspect sent headers.' );
		}

		return array_values(
			array_filter(
				xdebug_get_headers(),
				static function ( string $header ): bool {
					return (bool) preg_match( '#^(Document-Isolation-Policy|Cross-Origin-)#i', $header );
				}
			)
		);
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
		// wp_register_script() is a no-op for an already-registered handle, so a
		// pre-existing registration (WordPress core bundles this script as of
		// WP 7.1) survives this call untouched — and tear_down() must leave it
		// alone rather than deregister the real thing.
		$this->registered_wp_upload_media_script = ! wp_script_is( 'wp-upload-media', 'registered' );

		wp_register_script( 'wp-upload-media', 'https://example.org/upload-media.js', [], '1.0', true );

		/*
		 * Core only reports client-side processing available in a secure
		 * context, and the test install is served over plain HTTP.
		 */
		add_filter( 'wp_client_side_media_processing_enabled', '__return_true' );
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
			remove_filter( 'wp_client_side_media_processing_enabled', '__return_true' );
			remove_filter( 'upload_from_phone_client_side_processing', '__return_true' );

			if ( $this->registered_wp_upload_media_script ) {
				wp_deregister_script( 'wp-upload-media' );
			}

			// register_assets() may have registered upload-from-phone-view with a
			// dependency on wp-upload-media while the filter was active. WordPress
			// doesn't reset $wp_scripts between tests, so left alone that dangling
			// dependency would carry over to every test that runs afterwards.
			wp_deregister_script( 'upload-from-phone-view' );
			register_assets();

			$this->reset_client_side_processing = false;
		}

		parent::tear_down();
	}
}
