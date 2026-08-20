/**
 * External dependencies
 */
import { transformAttachment } from '@wordpress/media-utils';
import type {
	Attachment,
	RestAttachment,
	SubSizeData,
} from '@wordpress/media-utils';

/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { settings } from './settings';

const NETWORK_ERROR = __(
	'The file could not be uploaded. Please check your connection and try again.',
	'upload-from-phone'
);

/**
 * Extracts a human-readable message from whatever a request rejected with.
 *
 * A REST error arrives as a plain object rather than an Error, and is missing
 * entirely when something upstream of WordPress produced the response.
 *
 * @param value    The rejection value, or a parsed response body.
 * @param fallback Message to use when there is nothing to read.
 */
export function getErrorMessage(
	value: unknown,
	fallback = NETWORK_ERROR
): string {
	if ( value instanceof Error && value.message ) {
		return value.message;
	}

	if (
		value &&
		typeof value === 'object' &&
		'message' in value &&
		typeof ( value as { message: unknown } ).message === 'string' &&
		( value as { message: string } ).message
	) {
		return ( value as { message: string } ).message;
	}

	return fallback;
}

/**
 * Determines whether the passed argument appears to be a plain object.
 *
 * @param data The value to inspect.
 */
function isPlainObject( data: unknown ): data is Record< string, unknown > {
	return (
		data !== null &&
		typeof data === 'object' &&
		Object.getPrototypeOf( data ) === Object.prototype
	);
}

/**
 * Appends a value to form data the way the REST API expects to read it back.
 *
 * Mirrors the encoding `@wordpress/media-utils` uses for the same endpoints,
 * down to arrays being stringified into a comma-separated list, which is what
 * `rest_is_array()` parses on the other side. Reimplemented rather than
 * imported because the package keeps it internal, and the two have to agree.
 *
 * @param formData Form data to append to.
 * @param key      Field name.
 * @param data     Value to append.
 */
function flattenFormData(
	formData: FormData,
	key: string,
	data: unknown
): void {
	if ( isPlainObject( data ) ) {
		for ( const [ name, value ] of Object.entries( data ) ) {
			flattenFormData( formData, `${ key }[${ name }]`, value );
		}
		return;
	}

	if ( undefined !== data && null !== data ) {
		formData.append( key, String( data ) );
	}
}

/**
 * Creates an attachment from a file, reporting progress as it goes.
 *
 * Goes to core's own media endpoint rather than anything belonging to this
 * plugin. That is what makes the rest of the pipeline possible: the sub-sizes
 * the browser cuts have to be sideloaded onto the attachment this call creates,
 * and the metadata committed against it, and those are core endpoints that only
 * know about core attachments.
 *
 * Uses XMLHttpRequest rather than apiFetch so that upload progress can be
 * reported — on a phone over mobile data a photo can take a while, and a page
 * that just sits there looks broken.
 *
 * No nonce is sent, on purpose. The REST API treats a nonce-less request as
 * logged out, which is exactly the permission model this needs: the token is
 * the only credential that counts.
 *
 * @param file           The file to upload.
 * @param additionalData Extra fields to send alongside it.
 * @param onProgress     Called with a fraction between 0 and 1.
 * @param signal         Optional abort signal.
 */
export function createAttachment(
	file: File,
	additionalData: Record< string, unknown > = {},
	onProgress?: ( progress: number ) => void,
	signal?: AbortSignal
): Promise< Attachment > {
	return new Promise( ( resolve, reject ) => {
		const body = new FormData();

		body.append( 'file', file, file.name || file.type.replace( '/', '.' ) );
		body.append( 'upload_request', settings.token );

		for ( const [ key, value ] of Object.entries( additionalData ) ) {
			flattenFormData( body, key, value );
		}

		const request = new XMLHttpRequest();

		request.open( 'POST', settings.mediaUrl, true );
		request.responseType = 'json';

		request.upload.addEventListener( 'progress', ( event ) => {
			if ( event.lengthComputable && event.total > 0 ) {
				onProgress?.( event.loaded / event.total );
			}
		} );

		request.addEventListener( 'load', () => {
			if ( request.status >= 200 && request.status < 300 ) {
				onProgress?.( 1 );
				resolve(
					transformAttachment( request.response as RestAttachment )
				);
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

		request.send( body );
	} );
}

/**
 * Adds one browser-generated sub-size to an attachment.
 *
 * @param file           The sub-size file.
 * @param attachmentId   Attachment the sub-size belongs to.
 * @param additionalData Extra fields, notably which size this is.
 * @param signal         Optional abort signal.
 */
export function sideloadToAttachment(
	file: File,
	attachmentId: number,
	additionalData: Record< string, unknown > = {},
	signal?: AbortSignal
): Promise< SubSizeData > {
	const body = new FormData();

	body.append( 'file', file, file.name || file.type.replace( '/', '.' ) );
	body.append( 'upload_request', settings.token );

	for ( const [ key, value ] of Object.entries( additionalData ) ) {
		flattenFormData( body, key, value );
	}

	return apiFetch< SubSizeData >( {
		path: `/wp/v2/media/${ attachmentId }/sideload`,
		method: 'POST',
		body,
		signal,
	} );
}

/**
 * Commits an attachment's metadata once every sub-size has been sideloaded.
 *
 * @param attachmentId Attachment to finalize.
 * @param subSizes     Sub-size data collected from the sideloads.
 */
export async function finalizeAttachment(
	attachmentId: number,
	subSizes: SubSizeData[] = []
): Promise< Partial< Attachment > | undefined > {
	const response = await apiFetch< RestAttachment | null >( {
		path: `/wp/v2/media/${ attachmentId }/finalize`,
		method: 'POST',
		data: {
			sub_sizes: subSizes,
			upload_request: settings.token,
		},
	} );

	// Handing the finalized attachment back lets the queue pick up the URL of
	// the scaled file, which is the one the site will actually serve.
	return response ? transformAttachment( response ) : undefined;
}
