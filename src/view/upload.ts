/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { UploadResponse } from './types';

const NETWORK_ERROR = __(
	'The file could not be uploaded. Please check your connection and try again.',
	'upload-from-phone'
);

/**
 * Extracts a human-readable message from a REST API error response.
 *
 * A REST error arrives as a plain object rather than an Error, and is missing
 * entirely when something upstream of WordPress produced the response.
 *
 * @param response Parsed response body, whatever it turned out to be.
 */
function getErrorMessage( response: unknown ): string {
	if (
		response &&
		typeof response === 'object' &&
		'message' in response &&
		typeof ( response as { message: unknown } ).message === 'string'
	) {
		return ( response as { message: string } ).message;
	}

	return NETWORK_ERROR;
}

/**
 * Uploads a single file to the upload request endpoint.
 *
 * Uses XMLHttpRequest rather than fetch() so that upload progress can be
 * reported — on a phone over mobile data a file can take a while, and a button
 * that just sits there looks broken.
 *
 * No nonce is sent, on purpose. The REST API treats a nonce-less request as
 * logged out, which is exactly the permission model this endpoint wants: the
 * token in the URL is the only credential that counts.
 *
 * @param file       The file to upload.
 * @param restUrl    Endpoint to upload to.
 * @param onProgress Called with a fraction between 0 and 1 as the upload proceeds.
 * @param signal     Optional abort signal.
 */
export function uploadFile(
	file: File,
	restUrl: string,
	onProgress?: ( progress: number ) => void,
	signal?: AbortSignal
): Promise< UploadResponse > {
	return new Promise( ( resolve, reject ) => {
		const filename = file.name || file.type.replace( '/', '.' );

		/*
		 * The file goes up as the raw request body with its name in the query
		 * string, rather than as multipart form data. It is the same shape the
		 * core media endpoint accepts, it skips a layer of encoding on a
		 * connection that may be slow, and it keeps non-ASCII file names out of
		 * request headers, where they cannot be represented.
		 */
		const url = new URL( restUrl );
		url.searchParams.set( 'filename', filename );

		const request = new XMLHttpRequest();

		request.open( 'POST', url.toString(), true );
		request.responseType = 'json';
		request.setRequestHeader(
			'Content-Type',
			file.type || 'application/octet-stream'
		);

		request.upload.addEventListener( 'progress', ( event ) => {
			if ( event.lengthComputable && event.total > 0 ) {
				onProgress?.( event.loaded / event.total );
			}
		} );

		request.addEventListener( 'load', () => {
			if ( request.status >= 200 && request.status < 300 ) {
				onProgress?.( 1 );
				resolve( request.response as UploadResponse );
				return;
			}

			reject( new Error( getErrorMessage( request.response ) ) );
		} );

		request.addEventListener( 'error', () =>
			reject( new Error( NETWORK_ERROR ) )
		);

		request.addEventListener( 'abort', () =>
			reject( new DOMException( 'Upload aborted', 'AbortError' ) )
		);

		signal?.addEventListener( 'abort', () => request.abort(), {
			once: true,
		} );

		request.send( file );
	} );
}
