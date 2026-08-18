<?php
/**
 * PHPStan bootstrap file.
 *
 * Defines the constants the plugin sets at runtime, without running the plugin
 * file itself — loading that would call WordPress functions that do not exist
 * during analysis.
 *
 * @package UploadFromPhone
 */

declare(strict_types = 1);

define( 'UPLOAD_FROM_PHONE_FILE', dirname( __DIR__, 2 ) . '/upload-from-phone.php' );
define( 'UPLOAD_FROM_PHONE_DIR', dirname( __DIR__, 2 ) );
