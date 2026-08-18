/**
 * WordPress dependencies
 */
import { __, _n, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { uploadFile } from './upload';
import type { UploadFromPhoneData } from './types';
import './view.css';

type FileStatus = 'pending' | 'uploading' | 'done' | 'failed';

interface FileRow {
	name: string;
	status: FileStatus;
	progress: number;
	error?: string;
	settled: boolean;
	element: HTMLLIElement;
}

interface Elements {
	input: HTMLInputElement;
	label: HTMLLabelElement;
	list: HTMLUListElement;
	status: HTMLParagraphElement;
}

const settings: UploadFromPhoneData = window.uploadFromPhone;

const rows: FileRow[] = [];

let uploaded = 0;

/**
 * Returns WordPress's client-side media processing queue, if this site has one.
 *
 * WordPress ships the whole pipeline — HEIC conversion, resizing, compression —
 * as `wp-upload-media`. Where it exists, files go through it before being
 * uploaded, which matters most for exactly the case this plugin is named after:
 * an iPhone handing over a HEIC the server may not be able to read.
 *
 * Read off the global rather than imported, so the page still works on sites
 * where the package is not registered.
 */
function getUploadQueue() {
	const wp = ( window as unknown as { wp?: Record< string, any > } ).wp;
	const store = wp?.uploadMedia?.store;
	const data = wp?.data;

	return store && data ? { store, data } : null;
}

/**
 * Adds a row to the file list and returns its state.
 *
 * @param name File name.
 * @param list The list element.
 */
function addRow( name: string, list: HTMLUListElement ): FileRow {
	const element = document.createElement( 'li' );
	element.className = 'upload-from-phone__item';

	const row: FileRow = {
		name,
		status: 'pending',
		progress: 0,
		settled: false,
		element,
	};

	rows.push( row );
	list.append( element );
	paintRow( row );

	return row;
}

/**
 * Repaints a single file row.
 *
 * @param row The row to paint.
 */
function paintRow( row: FileRow ) {
	row.element.dataset.status = row.status;
	row.element.textContent = '';

	const name = document.createElement( 'span' );
	name.className = 'upload-from-phone__item-name';
	name.textContent = row.name;

	const state = document.createElement( 'span' );
	state.className = 'upload-from-phone__item-state';

	switch ( row.status ) {
		case 'uploading':
			state.textContent = sprintf(
				/* translators: %d: Upload progress as a percentage. */
				__( '%d%%', 'upload-from-phone' ),
				Math.round( row.progress * 100 )
			);
			break;
		case 'done':
			state.textContent = __( 'Uploaded', 'upload-from-phone' );
			break;
		case 'failed':
			state.textContent =
				row.error ?? __( 'Failed', 'upload-from-phone' );
			break;
		default:
			state.textContent = __( 'Waiting', 'upload-from-phone' );
	}

	row.element.append( name, state );
}

/**
 * Updates the summary line beneath the file list.
 *
 * @param elements  The page elements.
 * @param isRunning Whether an upload is currently in progress.
 */
function paintStatus( elements: Elements, isRunning: boolean ) {
	if ( isRunning ) {
		elements.status.textContent = __( 'Uploading…', 'upload-from-phone' );
		return;
	}

	const failed = rows.filter( ( row ) => row.status === 'failed' ).length;
	const remaining = settings.maxFiles - uploaded;

	if ( remaining <= 0 ) {
		elements.status.textContent = __(
			'All done. You can close this page.',
			'upload-from-phone'
		);
		elements.label.hidden = true;
		elements.input.disabled = true;
		return;
	}

	if ( failed > 0 ) {
		elements.status.textContent = __(
			'Some files could not be uploaded. You can try again.',
			'upload-from-phone'
		);
		return;
	}

	if ( uploaded > 0 ) {
		elements.status.textContent = sprintf(
			/* translators: %d: Number of files that may still be uploaded. */
			_n(
				'Uploaded. You may add %d more file.',
				'Uploaded. You may add %d more files.',
				remaining,
				'upload-from-phone'
			),
			remaining
		);
	}
}

/**
 * Marks a row as finished, whatever the outcome, exactly once.
 *
 * The queue can report the same file through more than one channel, so this
 * has to be idempotent for the outstanding count to stay honest.
 *
 * @param row      The row to settle.
 * @param status   Final status.
 * @param error    Optional error message.
 * @param onSettle Called the first time this row settles.
 */
function settleRow(
	row: FileRow,
	status: Extract< FileStatus, 'done' | 'failed' >,
	error: string | undefined,
	onSettle: () => void
) {
	if ( row.settled ) {
		return;
	}

	row.settled = true;
	row.status = status;
	row.error = error;

	if ( 'done' === status ) {
		uploaded++;
	}

	paintRow( row );
	onSettle();
}

/**
 * Uploads the chosen files.
 *
 * @param files    The files to upload.
 * @param elements The page elements.
 */
async function start( files: File[], elements: Elements ) {
	const accepted = files.slice( 0, settings.maxFiles - uploaded );

	if ( ! accepted.length ) {
		paintStatus( elements, false );
		return;
	}

	elements.label.setAttribute( 'aria-disabled', 'true' );
	paintStatus( elements, true );

	// Rows go up before the first byte moves, so the page reacts immediately.
	const batch = accepted.map( ( file ) =>
		addRow( file.name, elements.list )
	);

	const queue = getUploadQueue();

	if ( queue ) {
		await uploadViaQueue( accepted, batch, queue );
	} else {
		await uploadDirectly( accepted, batch );
	}

	elements.label.removeAttribute( 'aria-disabled' );
	paintStatus( elements, false );
}

/**
 * Uploads files straight to the server, without client-side processing.
 *
 * Sequential on purpose: a phone on mobile data uploads a single large file
 * faster than several at once, and a serial queue keeps progress honest.
 *
 * @param files The files to upload.
 * @param batch The rows representing them.
 */
async function uploadDirectly( files: File[], batch: FileRow[] ) {
	for ( const [ index, file ] of files.entries() ) {
		const row = batch[ index ];

		row.status = 'uploading';
		paintRow( row );

		try {
			await uploadFile( file, settings.restUrl, ( progress ) => {
				row.progress = progress;
				paintRow( row );
			} );

			settleRow( row, 'done', undefined, () => {} );
		} catch ( error ) {
			settleRow(
				row,
				'failed',
				error instanceof Error ? error.message : undefined,
				() => {}
			);
		}
	}
}

/**
 * Uploads files through WordPress's client-side media processing queue.
 *
 * The queue owns conversion and resizing; the actual transport is still this
 * plugin's own endpoint, injected as the queue's `mediaUpload` setting.
 *
 * @param files The files to upload.
 * @param batch The rows representing them.
 * @param queue The upload queue store and data registry.
 */
function uploadViaQueue(
	files: File[],
	batch: FileRow[],
	queue: NonNullable< ReturnType< typeof getUploadQueue > >
): Promise< void > {
	const { store, data } = queue;

	return new Promise( ( resolve ) => {
		let outstanding = batch.length;

		const settled = () => {
			if ( --outstanding <= 0 ) {
				resolve();
			}
		};

		/**
		 * Returns the row a queue callback is talking about.
		 *
		 * Client-side processing renames files — a HEIC comes out the other end
		 * as a JPEG — so rows are claimed in order rather than matched by name.
		 */
		const claimRow = (): FileRow =>
			batch.find(
				( row ) => ! row.settled && 'pending' === row.status
			) ?? batch[ batch.length - 1 ];

		data.dispatch( store ).updateSettings( {
			allowedMimeTypes: settings.allowedMimeTypes,
			maxUploadFileSize: settings.maxUploadFileSize,
			mediaUpload: ( {
				filesList,
				signal,
				onFileChange,
				onSuccess,
				onError,
			}: {
				filesList: File[];
				signal?: AbortSignal;
				onFileChange?: ( attachments: unknown[] ) => void;
				onSuccess?: ( attachments: unknown[] ) => void;
				onError?: ( error: Error ) => void;
			} ) => {
				const [ file ] = filesList;
				const row = claimRow();

				row.name = file.name;
				row.status = 'uploading';
				paintRow( row );

				uploadFile(
					file,
					settings.restUrl,
					( progress ) => {
						row.progress = progress;
						paintRow( row );
					},
					signal
				)
					.then( ( attachment ) => {
						settleRow( row, 'done', undefined, settled );

						onFileChange?.( [ attachment ] );
						onSuccess?.( [ attachment ] );
					} )
					.catch( ( error: Error ) => {
						settleRow( row, 'failed', error.message, settled );

						onError?.( error );
					} );
			},
		} );

		data.dispatch( store ).addItems( {
			files,
			allowedTypes: settings.allowedTypes.length
				? settings.allowedTypes
				: undefined,
			// Fires for files the queue rejects before they ever reach the
			// transport, and for failures during processing.
			onError: ( error: Error ) =>
				settleRow( claimRow(), 'failed', error.message, settled ),
		} );
	} );
}

/**
 * Renders the upload page UI into its container.
 *
 * @param container The container element.
 */
function render( container: HTMLElement ) {
	const input = document.createElement( 'input' );
	input.type = 'file';
	input.className = 'upload-from-phone__input';
	input.id = 'upload-from-phone-input';
	input.multiple = settings.multiple;
	input.accept = settings.accept.length ? settings.accept.join( ',' ) : '*';

	const label = document.createElement( 'label' );
	label.className = 'upload-from-phone__button';
	label.htmlFor = input.id;
	label.textContent = settings.multiple
		? __( 'Choose files', 'upload-from-phone' )
		: __( 'Choose a file', 'upload-from-phone' );

	const list = document.createElement( 'ul' );
	list.className = 'upload-from-phone__list';

	const status = document.createElement( 'p' );
	status.className = 'upload-from-phone__status';
	status.setAttribute( 'role', 'status' );
	status.setAttribute( 'aria-live', 'polite' );

	container.append( input, label, list, status );

	const elements: Elements = { input, label, list, status };

	input.addEventListener( 'change', () => {
		const files = input.files ? Array.from( input.files ) : [];

		// Reset the input so picking the same file twice still fires a change event.
		input.value = '';

		if ( files.length ) {
			void start( files, elements );
		}
	} );
}

const container = document.getElementById( 'upload-from-phone-root' );

if ( container && settings ) {
	render( container );
}
