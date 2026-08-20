/**
 * External dependencies
 */
import { join } from 'node:path';
import type { Page, Request } from '@playwright/test';

/**
 * WordPress dependencies
 */
import type { Editor } from '@wordpress/e2e-test-utils-playwright';

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

/** A second, deliberately tiny image, for tests that just need two files. */
const SECOND_TEST_IMAGE = join( __dirname, '..', 'assets', 'solid-64x64.png' );

/** Matches core's endpoint for creating an attachment. */
const CREATE_ENDPOINT = /\/wp\/v2\/media(\?|$)/;

/** Matches core's endpoint for adding a generated size to an attachment. */
const SIDELOAD_ENDPOINT = /\/wp\/v2\/media\/\d+\/sideload/;

/** Matches core's endpoint for committing an attachment's metadata. */
const FINALIZE_ENDPOINT = /\/wp\/v2\/media\/\d+\/finalize/;

/**
 * Opens an upload request for a fresh media block and returns its link.
 *
 * @param editor       The editor utility.
 * @param [blockName]  Block to insert. Defaults to the Image block.
 * @param [blockLabel] Accessible name of that block. Defaults to `Image`.
 */
async function requestUpload(
	editor: Editor,
	blockName = 'core/image',
	blockLabel = 'Image'
) {
	await editor.insertBlock( { name: blockName } );

	const imageBlock = editor.canvas.locator(
		`role=document[name="Block: ${ blockLabel }"i]`
	);

	await imageBlock
		.getByRole( 'button', { name: 'Upload from phone' } )
		.click();

	const panel = imageBlock.locator( '.upload-from-phone-panel' );

	return {
		imageBlock,
		panel,
		uploadUrl: await panel.getByLabel( 'Upload link' ).inputValue(),
	};
}

/**
 * Reads the settings PHP handed to the upload page.
 *
 * @param page The upload page.
 */
async function getPageSettings( page: Page ): Promise< Record< string, any > > {
	return page.evaluate(
		() =>
			(
				window as unknown as {
					uploadFromPhone: Record< string, any >;
				}
			 ).uploadFromPhone
	);
}

test.describe( 'Client-side media processing', () => {
	test.beforeEach( async ( { admin, requestUtils } ) => {
		await requestUtils.deleteAllMedia();
		await admin.createNewPost();
	} );

	/**
	 * The image pipeline runs on wasm-vips, which needs `SharedArrayBuffer`,
	 * which browsers only hand to a cross-origin isolated document. Without
	 * these headers everything still loads and every image operation silently
	 * reports itself unsupported, so this is the load-bearing assertion for
	 * the whole feature.
	 */
	test( 'the upload page is cross-origin isolated', async ( {
		secondPage,
		editor,
	} ) => {
		const { uploadUrl } = await requestUpload( editor );

		const response = await secondPage.goto( uploadUrl );

		expect( response ).not.toBeNull();

		const headers = response!.headers();

		// Playwright drives Chromium, which is offered the newer header.
		expect( headers[ 'document-isolation-policy' ] ).toBe(
			'isolate-and-credentialless'
		);

		await expect
			.poll( () =>
				secondPage.evaluate( () => window.crossOriginIsolated )
			)
			.toBe( true );
	} );

	/**
	 * The queue imports its image and video workers on demand, as script
	 * modules. A bare specifier only resolves through an import map, which
	 * WordPress prints from `wp_head()` — which this page deliberately does not
	 * call. Without the map the page looks perfectly healthy right up until a
	 * file needs vips, and then simply stops.
	 */
	test( "the upload page can resolve the queue's on-demand modules", async ( {
		secondPage,
		editor,
	} ) => {
		const { uploadUrl } = await requestUpload( editor );

		await secondPage.goto( uploadUrl );

		const importMap = await secondPage
			.locator( 'script[type="importmap"]' )
			.textContent();

		expect( importMap ).toBeTruthy();

		const imports = JSON.parse( importMap ?? '{}' ).imports ?? {};

		expect( Object.keys( imports ) ).toContain( '@wordpress/vips/worker' );
	} );

	test( 'the upload page is told what the site does with images', async ( {
		secondPage,
		editor,
	} ) => {
		const { uploadUrl } = await requestUpload( editor );

		await secondPage.goto( uploadUrl );

		const settings = await getPageSettings( secondPage );

		expect( settings.clientSide ).toBe( true );

		// Without the registered sizes the queue cannot cut any of them, and
		// would upload the file whole while looking like it had succeeded.
		expect( settings.allImageSizes ).toHaveProperty( 'thumbnail' );
		expect( settings.bigImageSizeThreshold ).toBeGreaterThan( 0 );
		expect( settings.mediaUrl ).toContain( 'wp/v2/media' );
	} );

	/**
	 * The point of the whole exercise: the file goes up through core's media
	 * endpoints, and the browser — not the server — produces the image sizes.
	 * Watching the requests is what tells the difference, since a file that was
	 * processed entirely server-side arrives looking much the same.
	 */
	test( 'the phone uploads, sideloads and finalizes through core', async ( {
		secondPage,
		editor,
	} ) => {
		const { imageBlock, panel, uploadUrl } = await requestUpload( editor );

		const requests: string[] = [];

		secondPage.on( 'request', ( request: Request ) => {
			if ( 'POST' === request.method() ) {
				requests.push( request.url() );
			}
		} );

		await secondPage.goto( uploadUrl );
		await secondPage
			.locator( '#upload-from-phone-input' )
			.setInputFiles( TEST_IMAGE );

		await expect(
			secondPage.getByText( 'All done. You can close this page.' )
		).toBeVisible( { timeout: 30_000 } );

		// The attachment itself.
		expect(
			requests.filter( ( url ) => CREATE_ENDPOINT.test( url ) )
		).toHaveLength( 1 );

		// One request per image size the browser cut.
		expect(
			requests.filter( ( url ) => SIDELOAD_ENDPOINT.test( url ) ).length
		).toBeGreaterThan( 0 );

		// And the metadata committed at the end.
		expect(
			requests.filter( ( url ) => FINALIZE_ENDPOINT.test( url ) )
		).toHaveLength( 1 );

		await expect( panel ).toBeHidden( { timeout: 15_000 } );
		await expect( imageBlock.locator( 'img' ) ).toBeVisible();
	} );

	/**
	 * A block whose image has no registered sizes cannot build a `srcset`, so
	 * an upload that skipped the cropping would quietly cost the site its
	 * responsive images.
	 */
	test( 'the uploaded image ends up with its registered sizes', async ( {
		secondPage,
		editor,
		requestUtils,
	} ) => {
		const { panel, uploadUrl } = await requestUpload( editor );

		await secondPage.goto( uploadUrl );
		await secondPage
			.locator( '#upload-from-phone-input' )
			.setInputFiles( TEST_IMAGE );

		await expect( panel ).toBeHidden( { timeout: 30_000 } );

		const media = await requestUtils.rest< Array< Record< string, any > > >(
			{ path: '/wp/v2/media', params: { per_page: 1 } }
		);

		expect( media ).toHaveLength( 1 );

		const sizes = media[ 0 ].media_details?.sizes ?? {};

		expect( Object.keys( sizes ) ).toContain( 'thumbnail' );
		expect( sizes.thumbnail.width ).toBeGreaterThan( 0 );
	} );

	/**
	 * The editor must not pick up a file the browser is still working on: the
	 * attachment exists from the moment its file lands, but its sizes and its
	 * final URL only arrive at the end, and a block that saw it early would
	 * keep the URL it saw first.
	 */
	test( 'the editor waits for processing to finish', async ( {
		secondPage,
		editor,
		requestUtils,
	} ) => {
		const { imageBlock, panel, uploadUrl } = await requestUpload( editor );

		await secondPage.goto( uploadUrl );
		await secondPage
			.locator( '#upload-from-phone-input' )
			.setInputFiles( TEST_IMAGE );

		await expect( panel ).toBeHidden( { timeout: 30_000 } );

		const image = imageBlock.locator( 'img' );
		await expect( image ).toBeVisible();

		const media = await requestUtils.rest< Array< Record< string, any > > >(
			{ path: '/wp/v2/media', params: { per_page: 1 } }
		);

		/*
		 * The URL the block settled on is the one the site will serve — not an
		 * earlier one that the finalize step then replaced.
		 */
		await expect( image ).toHaveAttribute( 'src', media[ 0 ].source_url );
	} );

	/**
	 * A file the request has no room for used to be sliced off the batch with
	 * nothing to show for it, which reads as the page losing track of it.
	 */
	test( 'files the link has no room for are reported, not dropped', async ( {
		page,
		secondPage,
		editor,
		requestUtils,
	} ) => {
		await requestUtils.activatePlugin( 'one-file-per-link' );

		try {
			/*
			 * A Gallery, because the limit only means anything where more than
			 * one file is allowed — an Image block is capped at one whatever
			 * the filter says, and its file input refuses a second file
			 * outright.
			 */
			const { uploadUrl } = await requestUpload(
				editor,
				'core/gallery',
				'Gallery'
			);

			await secondPage.goto( uploadUrl );

			// Two files for a link that takes one.
			await secondPage
				.locator( '#upload-from-phone-input' )
				.setInputFiles( [ TEST_IMAGE, SECOND_TEST_IMAGE ] );

			await expect(
				secondPage.getByText( 'This link has no room for more files.' )
			).toBeVisible( { timeout: 30_000 } );

			// The one it did take still went up.
			await expect(
				secondPage.locator( '[data-status="done"]' )
			).toHaveCount( 1, { timeout: 30_000 } );
		} finally {
			await requestUtils.deactivatePlugin( 'one-file-per-link' );
			await page.reload();
		}
	} );

	test( 'uploading still works with client-side processing turned off', async ( {
		page,
		secondPage,
		editor,
		requestUtils,
	} ) => {
		await requestUtils.activatePlugin( 'no-client-side-processing' );

		try {
			const { imageBlock, panel, uploadUrl } =
				await requestUpload( editor );

			await secondPage.goto( uploadUrl );

			const settings = await getPageSettings( secondPage );

			expect( settings.clientSide ).toBe( false );

			await secondPage
				.locator( '#upload-from-phone-input' )
				.setInputFiles( TEST_IMAGE );

			await expect(
				secondPage.getByText( 'All done. You can close this page.' )
			).toBeVisible( { timeout: 30_000 } );

			await expect( panel ).toBeHidden( { timeout: 15_000 } );
			await expect( imageBlock.locator( 'img' ) ).toBeVisible();
		} finally {
			await requestUtils.deactivatePlugin( 'no-client-side-processing' );
			await page.reload();
		}
	} );
} );
