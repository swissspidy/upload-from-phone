<?php
/**
 * Plugin Name:       Upload from Phone
 * Plugin URI:        https://github.com/swissspidy/upload-from-phone
 * Description:       Upload photos and videos into a post straight from your phone. Scan a QR code in the editor, pick your files, done — no app and no login required.
 * Version:           0.1.0
 * Author:            Pascal Birchler
 * Author URI:        https://pascalbirchler.com
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/old-licenses/gpl-2.0.html
 * Text Domain:       upload-from-phone
 * Requires at least: 7.1
 * Requires PHP:      8.0
 *
 * @package UploadFromPhone
 */

declare(strict_types = 1);

namespace UploadFromPhone;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const VERSION = '0.1.0';

define( 'UPLOAD_FROM_PHONE_FILE', __FILE__ );
define( 'UPLOAD_FROM_PHONE_DIR', __DIR__ );

require_once __DIR__ . '/inc/class-upload-request.php';
require_once __DIR__ . '/inc/class-rest-upload-requests-controller.php';
require_once __DIR__ . '/inc/functions.php';
require_once __DIR__ . '/inc/default-filters.php';

register_activation_hook( __FILE__, __NAMESPACE__ . '\activate_plugin' );
register_deactivation_hook( __FILE__, __NAMESPACE__ . '\deactivate_plugin' );
