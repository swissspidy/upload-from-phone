<?php
/**
 * Plugin Name: One file per link
 * Description: Caps every upload request at a single file, so the "no room" path can be tested without uploading twenty photos.
 * Version:     1.0.0
 * License:     GPL-2.0-or-later
 *
 * @package UploadFromPhone
 */

declare(strict_types = 1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_filter( 'upload_from_phone_max_files', static fn (): int => 1 );
