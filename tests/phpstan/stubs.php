<?php
/**
 * Stubs for WordPress functions newer than the pinned stubs package.
 *
 * `php-stubs/wordpress-stubs` is pinned to ^6.8 (see the note in
 * upload-from-phone.php), so functions added in the WordPress version this
 * plugin actually requires are unknown to static analysis. Declaring them here
 * keeps the analysis honest about their signatures; delete this file once the
 * stubs package catches up.
 *
 * @package UploadFromPhone
 */

declare(strict_types = 1);

/**
 * Determines whether client-side media processing is enabled.
 *
 * @since WP 7.1.0
 *
 * @return bool Whether client-side media processing is enabled.
 */
function wp_is_client_side_media_processing_enabled(): bool {
	return true;
}
