/**
 * External dependencies
 */
import {
	validateFileSize,
	validateMimeType,
	validateMimeTypeForUser,
} from '@wordpress/media-utils';
import type { store as uploadStore } from '@wordpress/upload-media';

/**
 * WordPress dependencies
 */
import {
	useCallback,
	useEffect,
	useMemo,
	useRef,
	useState,
} from '@wordpress/element';
import { useDispatch, useSelect } from '@wordpress/data';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { createAttachment, getErrorMessage } from './media';
import { settings } from './settings';
import type { Attachment, UploadRow, UploadState, Uploader } from './types';

/**
 * One item in the media processing queue, as far as this page is concerned.
 */
interface QueueItem {
	id: string;
	file: File;
	status?: string;
	currentOperation?: string;
	error?: { message?: string };
}

/**
 * Item statuses and operation names from the media processing queue.
 *
 * Spelled out rather than imported: the enums live in a package this page only
 * borrows from the global at runtime, so there is nothing to import them from.
 */
const STATUS_QUEUED = 'QUEUED';
const STATUS_ERROR = 'ERROR';
const OPERATION_UPLOAD = 'UPLOAD';

/**
 * How far along each file's upload is.
 *
 * Kept outside React because the transport that reports it is a plain function
 * handed to the queue, which has no way to reach a component. Files are the key
 * because a file is the one thing both ends of that handover hold.
 */
const progressByFile = new WeakMap< File, number >();

const progressListeners = new Set< () => void >();

/**
 * Records how far along a file is, and tells anyone rendering about it.
 *
 * @param file     The file being uploaded.
 * @param progress Fraction between 0 and 1.
 */
export function reportProgress( file: File, progress: number ): void {
	progressByFile.set( file, progress );

	for ( const listener of progressListeners ) {
		listener();
	}
}

/**
 * Re-renders the caller whenever any file's upload progresses.
 *
 * Returns a counter rather than the progress itself, so that anything derived
 * from the queue can depend on it. Progress lives in a plain map that React
 * cannot see into, and a memo keyed only on the queue's own state would go on
 * handing back the percentage it first computed.
 *
 * @return A value that changes every time progress is reported.
 */
function useProgressUpdates(): number {
	const [ tick, setTick ] = useState( 0 );

	useEffect( () => {
		const listener = () => setTick( ( current ) => current + 1 );

		progressListeners.add( listener );

		return () => {
			progressListeners.delete( listener );
		};
	}, [] );

	return tick;
}

/**
 * Keeps the list of files that are no longer in flight.
 *
 * The queue drops an item the moment it succeeds, which is the right thing for
 * a queue and the wrong thing for a list someone is watching, so what happened
 * has to be remembered here.
 */
function useSettledRows() {
	const [ rows, setRows ] = useState< UploadRow[] >( [] );
	const nextKey = useRef( 0 );

	const settle = useCallback( ( row: Omit< UploadRow, 'key' > ) => {
		nextKey.current += 1;

		setRows( ( previous ) => [
			...previous,
			{ ...row, key: `settled-${ nextKey.current }` },
		] );
	}, [] );

	const uploadedCount = useMemo(
		() => rows.filter( ( row ) => 'done' === row.state ).length,
		[ rows ]
	);

	return { settledRows: rows, settle, uploadedCount };
}

/**
 * Names a file the way it ended up on the server.
 *
 * Client-side processing renames as it converts — a HEIC arrives as a JPEG —
 * and the name that matters afterwards is the one the site will serve.
 *
 * @param attachment The attachment that was created.
 */
function getAttachmentName( attachment?: Partial< Attachment > ): string {
	const name = attachment?.filename ?? attachment?.title;

	return typeof name === 'string' && '' !== name
		? name
		: __( 'Uploaded file', 'upload-from-phone' );
}

/**
 * Works out what a queue item is currently doing.
 *
 * @param item A queue item.
 */
function getItemState( item: QueueItem ): UploadState {
	if ( STATUS_ERROR === item.status ) {
		return 'failed';
	}

	if ( STATUS_QUEUED === item.status ) {
		return 'waiting';
	}

	return OPERATION_UPLOAD === item.currentOperation
		? 'uploading'
		: 'processing';
}

/**
 * Drives uploads through WordPress's client-side media processing queue.
 *
 * The queue owns the work: it converts what the server cannot read, scales
 * anything past the site's threshold, cuts every registered image size, and
 * only then hands each file to the transport. This hook is the part that feeds
 * it files and reads back what it is doing.
 *
 * Only ever called from inside a `MediaUploadProvider`, which puts the queue in
 * a registry of its own — so the store has to be the one that provider was
 * given, not a module-level import.
 *
 * @param store The media processing queue's store.
 */
export function useQueueUploader( store: typeof uploadStore ): Uploader {
	const { settledRows, settle, uploadedCount } = useSettledRows();

	const progressTick = useProgressUpdates();

	const { addItems } = useDispatch( store );

	const items = useSelect(
		( select ) => select( store ).getItems(),
		[ store ]
	) as QueueItem[];

	const addFiles = useCallback(
		( files: File[] ) => {
			const accepted = files.slice(
				0,
				settings.maxFiles - uploadedCount - items.length
			);

			if ( ! accepted.length ) {
				return;
			}

			void addItems( {
				files: accepted,
				allowedTypes: settings.allowedTypes.length
					? settings.allowedTypes
					: undefined,
				onSuccess: ( attachments: Array< Partial< Attachment > > ) =>
					settle( {
						name: getAttachmentName( attachments?.[ 0 ] ),
						state: 'done',
					} ),
				/*
				 * Fires both for files the queue turns away before they reach
				 * the transport — wrong type, too large — and for anything
				 * that goes wrong while processing or uploading.
				 */
				onError: ( error: Error & { file?: File } ) =>
					settle( {
						name:
							error?.file?.name ??
							__( 'File', 'upload-from-phone' ),
						state: 'failed',
						error: getErrorMessage( error ),
					} ),
			} );
		},
		[ addItems, items.length, settle, uploadedCount ]
	);

	const rows = useMemo(
		() => [
			...settledRows,
			...items.map( ( item ) => ( {
				key: item.id,
				name: item.file.name,
				state: getItemState( item ),
				progress: progressByFile.get( item.file ),
				error: item.error?.message,
			} ) ),
		],
		// eslint-disable-next-line react-hooks/exhaustive-deps -- progressTick is what makes a progress report visible; see useProgressUpdates().
		[ items, settledRows, progressTick ]
	);

	return {
		rows,
		uploadedCount,
		isBusy: items.length > 0,
		addFiles,
	};
}

/**
 * Uploads files as they are, for sites without client-side media processing.
 *
 * Sequential on purpose: a phone on mobile data uploads a single large file
 * faster than several at once, and a serial queue keeps progress honest.
 */
export function useDirectUploader(): Uploader {
	const [ activeRows, setActiveRows ] = useState< UploadRow[] >( [] );

	const { settledRows, settle, uploadedCount } = useSettledRows();

	const nextKey = useRef( 0 );
	const isRunning = useRef( false );

	const update = useCallback(
		( key: string, patch: Partial< UploadRow > ) => {
			setActiveRows( ( previous ) =>
				previous.map( ( row ) =>
					row.key === key ? { ...row, ...patch } : row
				)
			);
		},
		[]
	);

	const addFiles = useCallback(
		( files: File[] ) => {
			const accepted = files.slice(
				0,
				settings.maxFiles - uploadedCount - activeRows.length
			);

			if ( ! accepted.length ) {
				return;
			}

			const queued = accepted.map( ( file ) => {
				nextKey.current += 1;

				return {
					file,
					row: {
						key: `file-${ nextKey.current }`,
						name: file.name,
						state: 'waiting' as UploadState,
					},
				};
			} );

			setActiveRows( ( previous ) => [
				...previous,
				...queued.map( ( { row } ) => row ),
			] );

			void ( async () => {
				/*
				 * A second batch dropped mid-upload joins the end of the list
				 * rather than starting a race for the connection.
				 */
				while ( isRunning.current ) {
					await new Promise( ( resolve ) =>
						setTimeout( resolve, 100 )
					);
				}

				isRunning.current = true;

				for ( const { file, row } of queued ) {
					update( row.key, { state: 'uploading', progress: 0 } );

					try {
						/*
						 * The same three checks the queue runs before it takes
						 * a file on, so that a file the server would refuse is
						 * refused here too, with something worth reading.
						 */
						validateMimeTypeForUser(
							file,
							settings.allowedMimeTypes
						);
						validateMimeType(
							file,
							settings.allowedTypes.length
								? settings.allowedTypes
								: undefined
						);
						validateFileSize( file, settings.maxUploadFileSize );

						const attachment = await createAttachment(
							file,
							{},
							( progress ) => update( row.key, { progress } )
						);

						settle( {
							name: getAttachmentName( attachment ),
							state: 'done',
						} );
					} catch ( error ) {
						settle( {
							name: file.name,
							state: 'failed',
							error: getErrorMessage( error ),
						} );
					}

					setActiveRows( ( previous ) =>
						previous.filter( ( entry ) => entry.key !== row.key )
					);
				}

				isRunning.current = false;
			} )();
		},
		[ activeRows.length, settle, update, uploadedCount ]
	);

	return {
		rows: [ ...settledRows, ...activeRows ],
		uploadedCount,
		isBusy: activeRows.length > 0,
		addFiles,
	};
}
