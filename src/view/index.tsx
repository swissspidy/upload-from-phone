/**
 * External dependencies
 */
import type { SubSizeData } from '@wordpress/media-utils';

/**
 * WordPress dependencies
 */
import { createRoot, StrictMode } from '@wordpress/element';

/**
 * Internal dependencies
 */
import { App } from './app';
import {
	createAttachment,
	finalizeAttachment,
	getErrorMessage,
	sideloadToAttachment,
} from './media';
import { getQueueSettings, getUploadMedia, settings } from './settings';
import {
	reportProgress,
	useDirectUploader,
	useQueueUploader,
} from './use-uploads';
import type { Attachment } from './types';

/**
 * Turns whatever a request rejected with into something the queue can handle.
 *
 * The queue cancels an item by calling `onError` with an Error and reading its
 * message; a REST rejection is a plain object, and would surface as
 * "[object Object]" if passed straight through.
 *
 * @param error The rejection value.
 */
function toError( error: unknown ): Error {
	return error instanceof Error
		? error
		: new Error( getErrorMessage( error ) );
}

/**
 * Creates the attachment, and reports how far along it is while doing so.
 *
 * @param args                Arguments from the queue.
 * @param args.filesList      The file to upload, on its own.
 * @param args.additionalData Fields the queue wants sent with it.
 * @param args.signal         Abort signal.
 * @param args.onFileChange   Called as soon as the attachment exists.
 * @param args.onSuccess      Called once the upload is done.
 * @param args.onError        Called if it fails.
 */
function mediaUpload( {
	filesList,
	additionalData,
	signal,
	onFileChange,
	onSuccess,
	onError,
}: {
	filesList: File[];
	additionalData?: Record< string, unknown >;
	signal?: AbortSignal;
	onFileChange?: ( attachments: Array< Partial< Attachment > > ) => void;
	onSuccess?: ( attachments: Array< Partial< Attachment > > ) => void;
	onError?: ( error: Error ) => void;
} ) {
	const [ file ] = filesList;

	createAttachment(
		file,
		additionalData,
		( progress ) => reportProgress( file, progress ),
		signal
	)
		.then( ( attachment ) => {
			onFileChange?.( [ attachment ] );
			onSuccess?.( [ attachment ] );
		} )
		.catch( ( error: unknown ) => onError?.( toError( error ) ) );
}

/**
 * Sends one image size the browser generated up to its attachment.
 *
 * @param args                Arguments from the queue.
 * @param args.file           The generated file.
 * @param args.attachmentId   Attachment it belongs to.
 * @param args.additionalData Fields the queue wants sent with it.
 * @param args.signal         Abort signal.
 * @param args.onSuccess      Called with the sub-size data the server recorded.
 * @param args.onError        Called if it fails.
 */
function mediaSideload( {
	file,
	attachmentId,
	additionalData,
	signal,
	onSuccess,
	onError,
}: {
	file: File;
	attachmentId: number;
	additionalData?: Record< string, unknown >;
	signal?: AbortSignal;
	onSuccess?: ( subSize: SubSizeData ) => void;
	onError?: ( error: Error ) => void;
} ) {
	sideloadToAttachment( file, attachmentId, additionalData, signal )
		.then( ( subSize ) => onSuccess?.( subSize ) )
		.catch( ( error: unknown ) => onError?.( toError( error ) ) );
}

/**
 * The page, backed by the client-side media processing queue.
 *
 * @param props       Component props.
 * @param props.store The queue's store, from the provider that owns it.
 */
function ProcessedUploadPage( {
	store,
}: {
	store: NonNullable< ReturnType< typeof getUploadMedia > >[ 'store' ];
} ) {
	return <App uploader={ useQueueUploader( store ) } />;
}

/**
 * The page, uploading files exactly as they were picked.
 */
function PlainUploadPage() {
	return <App uploader={ useDirectUploader() } />;
}

const container = document.getElementById( 'upload-from-phone-root' );

if ( container && settings ) {
	const uploadMediaPackage = getUploadMedia();
	const root = createRoot( container );

	if ( uploadMediaPackage ) {
		const { MediaUploadProvider, store } = uploadMediaPackage;

		root.render(
			<StrictMode>
				<MediaUploadProvider
					settings={ getQueueSettings( {
						mediaUpload,
						mediaSideload,
						mediaFinalize: finalizeAttachment,
					} ) }
				>
					<ProcessedUploadPage store={ store } />
				</MediaUploadProvider>
			</StrictMode>
		);
	} else {
		root.render(
			<StrictMode>
				<PlainUploadPage />
			</StrictMode>
		);
	}

	/*
	 * A file dropped anywhere else on the page — the panel padding, the
	 * heading, the footer link — would otherwise make the browser navigate to
	 * it, replacing the page mid-upload.
	 */
	const isDraggingFiles = ( event: DragEvent ): boolean =>
		Array.from( event.dataTransfer?.types ?? [] ).includes( 'Files' );

	document.addEventListener( 'dragover', ( event ) => {
		if ( isDraggingFiles( event ) ) {
			event.preventDefault();
		}
	} );

	document.addEventListener( 'drop', ( event ) => {
		if ( isDraggingFiles( event ) ) {
			event.preventDefault();
		}
	} );
}
