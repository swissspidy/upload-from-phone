<?php
/**
 * Plugin Name: No client-side processing
 * Description: Turns off client-side media processing on the upload page, so the plain upload path can be tested.
 * Version:     1.0.0
 * License:     GPL-2.0-or-later
 *
 * @package UploadFromPhone
 */

declare(strict_types = 1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_filter( 'upload_from_phone_client_side_processing', '__return_false' );
