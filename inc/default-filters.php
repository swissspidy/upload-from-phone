<?php
/**
 * Adding actions and filters.
 *
 * @package UploadFromPhone
 */

declare(strict_types = 1);

namespace UploadFromPhone;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'init', __NAMESPACE__ . '\register_post_type' );
add_action( 'init', __NAMESPACE__ . '\register_assets' );
add_action( 'rest_api_init', __NAMESPACE__ . '\register_rest_routes' );

add_action( 'enqueue_block_editor_assets', __NAMESPACE__ . '\enqueue_block_editor_assets' );

add_filter( 'template_include', __NAMESPACE__ . '\filter_template_include' );

add_filter( 'cron_schedules', __NAMESPACE__ . '\filter_cron_schedules' ); // phpcs:ignore WordPress.WP.CronInterval.ChangeDetected
add_action( 'upload_from_phone_cleanup', __NAMESPACE__ . '\delete_expired_requests' );
