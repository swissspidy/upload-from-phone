<?php
/**
 * Fires on uninstall, cleaning up what deactivation deliberately leaves behind.
 *
 * @package UploadFromPhone
 */

// If uninstall.php is not called by WordPress, exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once __DIR__ . '/inc/class-upload-request.php';

use UploadFromPhone\Upload_Request;

/*
 * Deactivation (see deactivate_plugin() in inc/functions.php) only clears
 * *expired* upload requests, so it can run repeatedly without discarding a
 * request that's still valid — someone may reactivate a moment later mid
 * upload. Uninstalling is the one-way trip: nothing is coming back to use
 * a request afterwards, so this removes all of them, not just the expired
 * ones.
 *
 * The uploaded attachments themselves are deliberately left alone — by the
 * time a file is uploaded, it belongs to the post being edited, not to the
 * request that brought it in.
 */
$ufph_upload_requests = get_posts(
	[
		'post_type'      => Upload_Request::POST_TYPE,
		// Not 'any': it excludes trash and auto-draft (both have
		// exclude_from_search set), which would leave exactly the kind of
		// leftover posts this file exists to clean up.
		'post_status'    => get_post_stati(),
		'posts_per_page' => -1,
		'fields'         => 'ids',
	]
);

foreach ( $ufph_upload_requests as $ufph_post_id ) {
	wp_delete_post( $ufph_post_id, true );
}

// Belt and suspenders: deactivation already does this, but nothing
// guarantees deactivation ran before whatever triggered this uninstall.
wp_clear_scheduled_hook( 'upload_from_phone_cleanup' );
