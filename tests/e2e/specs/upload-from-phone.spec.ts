/**
 * External dependencies
 */
import { join } from 'node:path';

/**
 * Internal dependencies
 */
import { expect, test } from '../fixtures';

const TEST_IMAGE = join(
	__dirname,
	'..',
	'assets',
	'wordpress-logo-512x512.png'
);

test.describe( 'Upload from phone', () => {
	test.beforeEach( async ( { admin, requestUtils } ) => {
		await requestUtils.deleteAllMedia();
		await admin.createNewPost();
	} );

	test( 'uploads media from a second device into an Image block', async ( {
		page,
		secondPage,
		editor,
	} ) => {
		await editor.insertBlock( { name: 'core/image' } );

		const imageBlock = editor.canvas.locator(
			'role=document[name="Block: Image"i]'
		);

		await imageBlock
			.getByRole( 'button', { name: 'Upload from phone' } )
			.click();

		const modal = page.getByRole( 'dialog', {
			name: 'Upload from phone',
		} );
		await expect( modal ).toBeVisible();

		// The same link the QR code encodes, without needing to decode an image.
		const uploadUrl = await modal.getByLabel( 'Upload link' ).inputValue();
		expect( uploadUrl ).toMatch( /\/upload\/[a-f0-9]{32}\/?$/ );

		// "The phone": a separate, logged-out context following the link.
		await secondPage.goto( uploadUrl );
		await secondPage
			.locator( '#upload-from-phone-input' )
			.setInputFiles( TEST_IMAGE );

		await expect(
			secondPage.getByText( 'All done. You can close this page.' )
		).toBeVisible();

		// The editor polls for the upload on its own; no action needed here.
		await expect( modal ).toBeHidden( { timeout: 10_000 } );
		await expect( imageBlock.locator( 'img' ) ).toBeVisible();
	} );

	test( 'reports an expired link instead of accepting a stale one', async ( {
		page,
		secondPage,
		editor,
	} ) => {
		await editor.insertBlock( { name: 'core/image' } );

		const imageBlock = editor.canvas.locator(
			'role=document[name="Block: Image"i]'
		);

		await imageBlock
			.getByRole( 'button', { name: 'Upload from phone' } )
			.click();

		const modal = page.getByRole( 'dialog', {
			name: 'Upload from phone',
		} );
		const uploadUrl = await modal.getByLabel( 'Upload link' ).inputValue();

		// Cancelling revokes the link server-side immediately.
		await modal.getByRole( 'button', { name: 'Cancel' } ).click();
		await expect( modal ).toBeHidden();

		await secondPage.goto( uploadUrl );
		await expect(
			secondPage.getByText( 'This upload link is no longer valid.' )
		).toBeVisible();
	} );
} );
