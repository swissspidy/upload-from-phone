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

/** Matches the upload page URL, whose last segment is the request token. */
const UPLOAD_URL = /\/upload\/[a-f0-9]{32}\/?$/;

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

		// The link lives in the block itself, not in a dialog over the editor.
		const panel = imageBlock.locator( '.upload-from-phone-panel' );
		await expect( panel ).toBeVisible();
		await expect(
			page.getByRole( 'dialog', { name: 'Upload from phone' } )
		).toHaveCount( 0 );

		// The same link the QR code encodes, without needing to decode an image.
		const uploadUrl = await panel.getByLabel( 'Upload link' ).inputValue();
		expect( uploadUrl ).toMatch( UPLOAD_URL );

		// "The phone": a separate, logged-out context following the link.
		await secondPage.goto( uploadUrl );
		await secondPage
			.locator( '#upload-from-phone-input' )
			.setInputFiles( TEST_IMAGE );

		await expect(
			secondPage.getByText( 'All done. You can close this page.' )
		).toBeVisible();

		// The editor polls for the upload on its own; no action needed here.
		await expect( panel ).toBeHidden( { timeout: 10_000 } );
		await expect( imageBlock.locator( 'img' ) ).toBeVisible();
	} );

	test( 'leaves the rest of the post editable while waiting', async ( {
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

		const panel = imageBlock.locator( '.upload-from-phone-panel' );
		const uploadUrl = await panel.getByLabel( 'Upload link' ).inputValue();

		/*
		 * The whole point of showing the link in the block rather than in a
		 * dialog. Both halves matter: the click has to reach the canvas at all
		 * — a modal would swallow it — and the typing has to land in the block
		 * that click selected.
		 */
		await editor.insertBlock( { name: 'core/paragraph' } );
		await editor.canvas
			.getByRole( 'document', { name: 'Empty block' } )
			.click();
		await page.keyboard.type( 'Written while the phone was busy.' );

		await expect(
			editor.canvas.getByText( 'Written while the phone was busy.' )
		).toBeVisible();

		// The link is still good after all that, and still belongs to the block.
		await secondPage.goto( uploadUrl );
		await secondPage
			.locator( '#upload-from-phone-input' )
			.setInputFiles( TEST_IMAGE );

		await expect( panel ).toBeHidden( { timeout: 10_000 } );
		await expect( imageBlock.locator( 'img' ) ).toBeVisible();

		// The media arriving must not have cost the writing done in the meantime.
		await expect(
			editor.canvas.getByText( 'Written while the phone was busy.' )
		).toBeVisible();
	} );

	test( 'gives each block its own link, resolved independently', async ( {
		secondPage,
		editor,
	} ) => {
		await editor.insertBlock( { name: 'core/image' } );
		await editor.insertBlock( { name: 'core/image' } );

		const imageBlocks = editor.canvas.locator(
			'role=document[name="Block: Image"i]'
		);
		await expect( imageBlocks ).toHaveCount( 2 );

		const first = imageBlocks.nth( 0 );
		const second = imageBlocks.nth( 1 );

		await first
			.getByRole( 'button', { name: 'Upload from phone' } )
			.click();
		await second
			.getByRole( 'button', { name: 'Upload from phone' } )
			.click();

		const firstPanel = first.locator( '.upload-from-phone-panel' );
		const secondPanel = second.locator( '.upload-from-phone-panel' );

		const firstUrl = await firstPanel
			.getByLabel( 'Upload link' )
			.inputValue();
		const secondUrl = await secondPanel
			.getByLabel( 'Upload link' )
			.inputValue();

		expect( firstUrl ).toMatch( UPLOAD_URL );
		expect( secondUrl ).toMatch( UPLOAD_URL );
		expect( firstUrl ).not.toBe( secondUrl );

		// Fulfil the second block's request only.
		await secondPage.goto( secondUrl );
		await secondPage
			.locator( '#upload-from-phone-input' )
			.setInputFiles( TEST_IMAGE );

		await expect( second.locator( 'img' ) ).toBeVisible( {
			timeout: 10_000,
		} );

		// The first block is untouched, and still waiting on its own link.
		await expect( firstPanel ).toBeVisible();
		await expect( first.locator( 'img' ) ).toHaveCount( 0 );
	} );

	test( 'revokes the link server-side when cancelled, not just in the UI', async ( {
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

		const panel = imageBlock.locator( '.upload-from-phone-panel' );
		const uploadUrl = await panel.getByLabel( 'Upload link' ).inputValue();

		await panel.getByRole( 'button', { name: 'Cancel' } ).click();

		// Cancelling puts the block's own placeholder back.
		await expect( panel ).toBeHidden();
		await expect(
			imageBlock.getByRole( 'button', { name: 'Upload from phone' } )
		).toBeVisible();

		/*
		 * Cancelling deletes the upload request outright, rather than merely
		 * marking it expired — the two look different to a visitor. A request
		 * that is merely expired still exists as a post, so it renders this
		 * plugin's own "no longer valid" copy; a deleted one leaves nothing
		 * for WordPress to match against the token in the URL, so the request
		 * falls through to a genuine, theme-rendered 404. Assert on the
		 * status code precisely because of that: it's the one thing common to
		 * both codepaths, and the one a visitor's browser can't be tricked by.
		 *
		 * The click above doesn't wait for the DELETE request to land before
		 * dismissing the panel (by design — see cancel() in
		 * use-upload-request.ts), so this polls rather than asserting on a
		 * single navigation: the first attempt may still catch the request
		 * mid-flight.
		 */
		await expect
			.poll(
				async () => {
					const response = await secondPage.goto( uploadUrl );
					return response?.status();
				},
				{ timeout: 10_000 }
			)
			.toBe( 404 );
	} );
} );
