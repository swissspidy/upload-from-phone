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

	test( 'revokes the link server-side when cancelled, not just in the UI', async ( {
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

		// The app fires this DELETE without awaiting it — closing the modal
		// doesn't wait for the server to catch up, so neither should this
		// test rely on that timing. Wait for the response itself instead.
		const revoked = page.waitForResponse(
			( response ) =>
				response.request().method() === 'DELETE' &&
				response
					.url()
					.includes( '/upload-from-phone/v1/upload-requests/' )
		);

		await modal.getByRole( 'button', { name: 'Cancel' } ).click();
		await revoked;
		await expect( modal ).toBeHidden();

		/*
		 * Cancelling deletes the upload request outright, rather than merely
		 * marking it expired — the two look different to a visitor. A request
		 * that is merely expired still exists as a post, so it renders this
		 * plugin's own "no longer valid" copy; a deleted one leaves nothing
		 * for WordPress to match against the token in the URL, so the request
		 * falls through to a genuine, theme-rendered 404. Assert on the
		 * status code precisely because of that: it's the one thing common to
		 * both codepaths, and the one a visitor's browser can't be tricked by.
		 */
		const response = await secondPage.goto( uploadUrl );
		expect( response?.status() ).toBe( 404 );
	} );
} );
