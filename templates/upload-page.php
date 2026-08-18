<?php
/**
 * The upload page.
 *
 * This is what opens on the phone. It is deliberately not a theme template:
 * whoever follows the link is not there to look at the site, and the page has
 * to work the same way on every theme.
 *
 * @package UploadFromPhone
 */

declare(strict_types = 1);

namespace UploadFromPhone;

use WP_Post;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$ufph_post = get_post();

if ( ! $ufph_post instanceof WP_Post ) {
	exit;
}

$ufph_request = Upload_Request::from_post( $ufph_post );

if ( ! $ufph_request instanceof Upload_Request ) {
	exit;
}

$ufph_expired = $ufph_request->is_expired() || $ufph_request->is_complete();

$ufph_author      = get_userdata( $ufph_request->get_author_id() );
$ufph_author_name = $ufph_author ? $ufph_author->display_name : '';

$ufph_parent = $ufph_request->get_parent();

// Only name the post if the visitor would be allowed to see it anyway.
$ufph_parent_visible = $ufph_parent instanceof WP_Post
	&& ( is_post_publicly_viewable( $ufph_parent ) || current_user_can( 'read_post', $ufph_parent->ID ) );

$ufph_parent_title = $ufph_parent_visible ? get_the_title( $ufph_parent ) : '';

if ( $ufph_parent_visible && '' === $ufph_parent_title ) {
	$ufph_parent_title = __( '(no title)', 'upload-from-phone' );
}

if ( ! $ufph_expired ) {
	wp_add_inline_script(
		'upload-from-phone-view',
		sprintf(
			'window.uploadFromPhone = %s;',
			wp_json_encode( get_upload_page_data( $ufph_request ) )
		),
		'before'
	);
}

?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex, nofollow">
	<title><?php esc_html_e( 'Upload media', 'upload-from-phone' ); ?></title>
	<?php
	if ( ! $ufph_expired ) {
		print_upload_page_assets();
	}
	?>
</head>
<body class="upload-from-phone no-js">
<?php wp_print_inline_script_tag( "document.body.classList.replace('no-js','js');" ); ?>

<main class="upload-from-phone__wrap">
	<h1 class="upload-from-phone__title"><?php echo esc_html( get_bloginfo( 'name' ) ); ?></h1>

	<?php if ( $ufph_expired ) : ?>

		<div class="upload-from-phone__panel">
			<p class="upload-from-phone__lead">
				<?php esc_html_e( 'This upload link is no longer valid.', 'upload-from-phone' ); ?>
			</p>
			<p class="upload-from-phone__hint">
				<?php esc_html_e( 'Upload links expire after a short while, and stop working once they have been used. Ask for a new one to try again.', 'upload-from-phone' ); ?>
			</p>
		</div>

	<?php else : ?>

		<div class="upload-from-phone__panel">
			<p class="upload-from-phone__lead">
				<?php
				if ( '' !== $ufph_parent_title ) {
					printf(
						/* translators: 1: Name of the person who created the upload link. 2: Post title. */
						esc_html__( '%1$s would like you to add media to “%2$s”.', 'upload-from-phone' ),
						esc_html( $ufph_author_name ),
						esc_html( $ufph_parent_title )
					);
				} else {
					printf(
						/* translators: %s: Name of the person who created the upload link. */
						esc_html__( '%s would like you to add media to their site.', 'upload-from-phone' ),
						esc_html( $ufph_author_name )
					);
				}
				?>
			</p>

			<div class="hide-if-no-js" id="upload-from-phone-root"></div>

			<p class="hide-if-js upload-from-phone__hint">
				<?php esc_html_e( 'Uploading requires JavaScript. Please enable it in your browser settings.', 'upload-from-phone' ); ?>
			</p>
		</div>

	<?php endif; ?>

	<p class="upload-from-phone__footer">
		<a href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php echo esc_html( get_bloginfo( 'name' ) ); ?></a>
	</p>
</main>

</body>
</html>
