/**
 * External dependencies
 */
import { basename, join } from 'node:path';
import { readFile } from 'node:fs/promises';
import type { Locator } from '@playwright/test';

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

/**
 * Drops a file onto an element.
 *
 * Playwright has no way to drive a real OS-level drag, so this builds the
 * `DataTransfer` a browser would hand to the drop handler in-page and
 * dispatches the same events dragging a file in from the OS would fire.
 *
 * @param target   The element to drop the file onto.
 * @param filePath Path of the file to drop.
 */
async function dropFile( target: Locator, filePath: string ) {
	const contents = await readFile( filePath );

	const dataTransfer = await target.page().evaluateHandle(
		( { bytes, name } ) => {
			const transfer = new DataTransfer();
			transfer.items.add(
				new File( [ new Uint8Array( bytes ) ], name, {
					type: 'image/png',
				} )
			);
			return transfer;
		},
		{ bytes: Array.from( contents ), name: basename( filePath ) }
	);

	await target.dispatchEvent( 'dragenter', { dataTransfer } );
	await target.dispatchEvent( 'drop', { dataTransfer } );
}

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

	test( 'uploads media dropped onto the upload page into an Image block', async ( {
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

		const uploadUrl = await modal.getByLabel( 'Upload link' ).inputValue();

		await secondPage.goto( uploadUrl );

		const dropzone = secondPage.locator( '#upload-from-phone-root' );

		await dropFile( dropzone, TEST_IMAGE );

		await expect(
			secondPage.getByText( 'All done. You can close this page.' )
		).toBeVisible();

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

		await modal.getByRole( 'button', { name: 'Cancel' } ).click();
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
		 *
		 * The click above doesn't wait for the DELETE request to land before
		 * closing the modal (by design — see cancel() in
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
