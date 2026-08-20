/**
 * WordPress dependencies
 */
import { useCallback, useRef, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { settings } from './settings';
import type { UploadRow, Uploader } from './types';
import './view.css';

const DROPZONE_ACTIVE_CLASS = 'upload-from-phone__root--dragover';

/**
 * Whether a drag event is carrying files, as opposed to text or some other
 * draggable.
 *
 * `dataTransfer.items` and `.files` are only populated on drop, for security
 * reasons — `dragenter`/`dragover` can only see the drag's `types`, which is
 * enough to tell files apart from everything else a user might drag.
 *
 * @param event The drag event.
 */
function isDraggingFiles( event: React.DragEvent | DragEvent ): boolean {
	return Array.from( event.dataTransfer?.types ?? [] ).includes( 'Files' );
}

/**
 * Extracts the files from a drop event's data transfer.
 *
 * Prefers `items` over `files` so anything that is not a plain file — a
 * dragged folder, for instance — can be filtered out instead of reaching the
 * uploader as a `File` with nothing readable in it.
 *
 * @param dataTransfer The drop event's data transfer.
 */
function getDroppedFiles( dataTransfer: DataTransfer ): File[] {
	if ( dataTransfer.items.length ) {
		return Array.from( dataTransfer.items )
			.filter( ( item ) => 'file' === item.kind )
			.map( ( item ) => item.getAsFile() )
			.filter( ( file ): file is File => null !== file );
	}

	return Array.from( dataTransfer.files );
}

/**
 * Describes what is happening to one file, in words.
 *
 * @param row The file's row.
 */
function getRowState( row: UploadRow ): string {
	switch ( row.state ) {
		case 'processing':
			return __( 'Preparing…', 'upload-from-phone' );

		case 'uploading':
			return undefined === row.progress
				? __( 'Uploading…', 'upload-from-phone' )
				: sprintf(
						/* translators: %d: Upload progress as a percentage. */
						__( '%d%%', 'upload-from-phone' ),
						Math.round( row.progress * 100 )
				  );

		case 'done':
			return __( 'Uploaded', 'upload-from-phone' );

		case 'failed':
			return row.error ?? __( 'Failed', 'upload-from-phone' );

		default:
			return __( 'Waiting', 'upload-from-phone' );
	}
}

/**
 * One line in the file list.
 *
 * @param props     Component props.
 * @param props.row The file's row.
 */
function FileRow( { row }: { row: UploadRow } ) {
	return (
		<li className="upload-from-phone__item" data-status={ row.state }>
			<span className="upload-from-phone__item-name">{ row.name }</span>
			<span className="upload-from-phone__item-state">
				{ getRowState( row ) }
			</span>
		</li>
	);
}

/**
 * The summary line beneath the file list.
 *
 * @param props           Component props.
 * @param props.uploader  The uploader driving the page.
 * @param props.remaining How many more files may be uploaded.
 */
function Status( {
	uploader,
	remaining,
}: {
	uploader: Uploader;
	remaining: number;
} ) {
	let message = '';

	if ( uploader.isBusy ) {
		message = __( 'Uploading…', 'upload-from-phone' );
	} else if ( remaining <= 0 ) {
		message = __(
			'All done. You can close this page.',
			'upload-from-phone'
		);
	} else if ( uploader.rows.some( ( row ) => 'failed' === row.state ) ) {
		message = __(
			'Some files could not be uploaded. You can try again.',
			'upload-from-phone'
		);
	} else if ( uploader.uploadedCount > 0 ) {
		message = sprintf(
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

	return (
		<p
			className="upload-from-phone__status"
			role="status"
			aria-live="polite"
		>
			{ message }
		</p>
	);
}

/**
 * The upload page.
 *
 * Purely presentational: whichever uploader is in play decides what actually
 * happens to a file, which is what lets the same page serve a site that
 * processes media in the browser and one that does not.
 *
 * @param props          Component props.
 * @param props.uploader The uploader driving the page.
 */
export function App( { uploader }: { uploader: Uploader } ) {
	const [ isDragging, setIsDragging ] = useState( false );

	/*
	 * `dragenter`/`dragleave` fire on every element the pointer crosses,
	 * including children of the dropzone, so a depth counter is used rather
	 * than a plain boolean — otherwise dragging over the file list or the
	 * button would flicker the dropzone state off and back on.
	 */
	const depth = useRef( 0 );

	const inputRef = useRef< HTMLInputElement >( null );

	const remaining = settings.maxFiles - uploader.uploadedCount;
	const isFinished = remaining <= 0;

	const { addFiles } = uploader;

	const onDrop = useCallback(
		( event: React.DragEvent ) => {
			if ( ! isDraggingFiles( event ) ) {
				return;
			}

			event.preventDefault();
			depth.current = 0;
			setIsDragging( false );

			if ( isFinished || ! event.dataTransfer ) {
				return;
			}

			const files = getDroppedFiles( event.dataTransfer );

			addFiles( settings.multiple ? files : files.slice( 0, 1 ) );
		},
		[ addFiles, isFinished ]
	);

	const onDragEnter = useCallback(
		( event: React.DragEvent ) => {
			if ( isFinished || ! isDraggingFiles( event ) ) {
				return;
			}

			event.preventDefault();
			depth.current += 1;
			setIsDragging( true );
		},
		[ isFinished ]
	);

	const onDragOver = useCallback(
		( event: React.DragEvent ) => {
			if ( isFinished || ! isDraggingFiles( event ) ) {
				return;
			}

			// Required for the element to be treated as a drop target at all.
			event.preventDefault();

			if ( event.dataTransfer ) {
				event.dataTransfer.dropEffect = 'copy';
			}
		},
		[ isFinished ]
	);

	const onDragLeave = useCallback( ( event: React.DragEvent ) => {
		if ( ! isDraggingFiles( event ) ) {
			return;
		}

		depth.current = Math.max( 0, depth.current - 1 );

		if ( 0 === depth.current ) {
			setIsDragging( false );
		}
	}, [] );

	const onChange = useCallback(
		( event: React.ChangeEvent< HTMLInputElement > ) => {
			const files = event.target.files
				? Array.from( event.target.files )
				: [];

			// Reset so picking the same file twice still fires a change event.
			event.target.value = '';

			if ( files.length ) {
				addFiles( files );
			}
		},
		[ addFiles ]
	);

	return (
		<div
			className={
				isDragging
					? `upload-from-phone__root ${ DROPZONE_ACTIVE_CLASS }`
					: 'upload-from-phone__root'
			}
			onDragEnter={ onDragEnter }
			onDragOver={ onDragOver }
			onDragLeave={ onDragLeave }
			onDrop={ onDrop }
		>
			<input
				ref={ inputRef }
				type="file"
				className="upload-from-phone__input"
				id="upload-from-phone-input"
				multiple={ settings.multiple }
				accept={
					settings.accept.length ? settings.accept.join( ',' ) : '*'
				}
				disabled={ isFinished }
				onChange={ onChange }
			/>

			{ ! isFinished && (
				<>
					<label
						className="upload-from-phone__button"
						htmlFor="upload-from-phone-input"
						aria-disabled={ uploader.isBusy || undefined }
					>
						{ settings.multiple
							? __( 'Choose files', 'upload-from-phone' )
							: __( 'Choose a file', 'upload-from-phone' ) }
					</label>

					<p className="upload-from-phone__dropzone-hint">
						{ __(
							'Or drag and drop files here.',
							'upload-from-phone'
						) }
					</p>
				</>
			) }

			<ul className="upload-from-phone__list">
				{ uploader.rows.map( ( row ) => (
					<FileRow key={ row.key } row={ row } />
				) ) }
			</ul>

			<Status uploader={ uploader } remaining={ remaining } />
		</div>
	);
}
