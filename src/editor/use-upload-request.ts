/**
 * External dependencies
 */
import { transformAttachment } from '@wordpress/media-utils';
import type { Attachment } from '@wordpress/media-utils';

/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';
import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { useDispatch, useSelect } from '@wordpress/data';
import { store as editorStore } from '@wordpress/editor';
import { store as noticesStore } from '@wordpress/notices';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import type { CreateUploadRequestArgs, UploadRequest } from './types';

const REST_BASE = '/upload-from-phone/v1/upload-requests';

/** How often to ask the server whether anything has arrived, in milliseconds. */
const POLL_INTERVAL = 3000;

/**
 * Turns an unknown rejection into something worth showing a person.
 *
 * A rejected `apiFetch` is not always an Error: a REST error arrives as a plain
 * object, and a custom fetch handler can reject with anything at all.
 *
 * @param error    The rejection value.
 * @param fallback Message to use when the rejection carries none.
 */
function getErrorMessage( error: unknown, fallback: string ): string {
	if ( error instanceof Error && error.message ) {
		return error.message;
	}

	if (
		error &&
		typeof error === 'object' &&
		'message' in error &&
		typeof ( error as { message: unknown } ).message === 'string' &&
		( error as { message: string } ).message
	) {
		return ( error as { message: string } ).message;
	}

	return fallback;
}

interface UseUploadRequestArgs {
	/** Media types the upload request should accept. */
	allowedTypes?: string[];
	/** Unique file type specifiers for the file picker. */
	accept?: string[];
	/** Whether more than one file may be uploaded. */
	multiple?: boolean;
	/** Called with the media once the phone is done uploading. */
	onSelect: ( media: Attachment[] ) => void;
}

/**
 * Manages the lifecycle of a single upload request.
 *
 * Creates the request, watches for what the phone sends back, hands the media
 * to the block, and makes sure the link is revoked afterwards — including when
 * the editor is closed mid-upload.
 *
 * @param args              Hook arguments.
 * @param args.allowedTypes Media types the upload request should accept.
 * @param args.accept       Unique file type specifiers for the file picker.
 * @param args.multiple     Whether more than one file may be uploaded.
 * @param args.onSelect     Called with the media once the phone is done uploading.
 */
export function useUploadRequest( {
	allowedTypes,
	accept,
	multiple,
	onSelect,
}: UseUploadRequestArgs ) {
	const [ uploadRequest, setUploadRequest ] =
		useState< UploadRequest | null >( null );
	const [ isCreating, setIsCreating ] = useState( false );

	const { createErrorNotice, createSuccessNotice } =
		useDispatch( noticesStore );

	/*
	 * The post the media should end up attached to. Absent in the widget editor,
	 * and a string such as `theme//home` in the site editor — neither of which is
	 * an attachment parent, so both are treated as "no post".
	 */
	const postId = useSelect( ( select ) => {
		const id = select( editorStore )?.getCurrentPostId?.();

		return typeof id === 'number' && id > 0 ? id : null;
	}, [] );

	const token = uploadRequest?.token ?? null;

	// Kept in a ref so that the unmount cleanup can revoke the link without
	// re-running every time the request object changes.
	const tokenRef = useRef< string | null >( null );
	tokenRef.current = token;

	const onSelectRef = useRef( onSelect );
	onSelectRef.current = onSelect;

	const revoke = useCallback( async ( revokedToken: string | null ) => {
		if ( ! revokedToken ) {
			return;
		}

		try {
			await apiFetch( {
				path: `${ REST_BASE }/${ revokedToken }`,
				method: 'DELETE',
			} );
		} catch {
			// The link expires on its own soon enough; nothing useful to say here.
		}
	}, [] );

	const cancel = useCallback( () => {
		void revoke( tokenRef.current );
		setUploadRequest( null );
	}, [ revoke ] );

	const create = useCallback( async () => {
		setIsCreating( true );

		try {
			const data: CreateUploadRequestArgs = {
				allowed_types: allowedTypes,
				accept,
				multiple: Boolean( multiple ),
			};

			if ( postId ) {
				data.post = postId;
			}

			setUploadRequest(
				await apiFetch< UploadRequest >( {
					path: REST_BASE,
					method: 'POST',
					data,
				} )
			);
		} catch ( error ) {
			void createErrorNotice(
				getErrorMessage(
					error,
					__(
						'The upload link could not be created. Please try again.',
						'upload-from-phone'
					)
				),
				{ type: 'snackbar' }
			);
		} finally {
			setIsCreating( false );
		}
	}, [ accept, allowedTypes, createErrorNotice, multiple, postId ] );

	// Watch for uploads, and for the link going stale.
	useEffect( () => {
		if ( ! token ) {
			return;
		}

		let previousCount = 0;
		let cancelled = false;

		const interval = setInterval( async () => {
			let current: UploadRequest;

			try {
				current = await apiFetch< UploadRequest >( {
					path: `${ REST_BASE }/${ token }`,
				} );
			} catch {
				// A 404 means the link expired or was cleaned up server-side.
				if ( ! cancelled ) {
					setUploadRequest( null );
					void createErrorNotice(
						__( 'The upload link expired.', 'upload-from-phone' ),
						{ type: 'snackbar' }
					);
				}
				return;
			}

			if ( cancelled ) {
				return;
			}

			setUploadRequest( current );

			const count = current.attachments.length;

			/*
			 * Wait for the phone to be finished rather than grabbing the first
			 * file that lands: someone sending five photos should get five
			 * photos, not one. A request that has hit its own limit is finished
			 * by definition; otherwise a poll that adds nothing new means the
			 * phone has stopped.
			 */
			const isFinished =
				count > 0 && ( current.complete || count === previousCount );

			previousCount = count;

			if ( ! isFinished ) {
				return;
			}

			cancelled = true;

			onSelectRef.current(
				current.attachments.map( transformAttachment )
			);

			void createSuccessNotice(
				__( 'Media added from your phone.', 'upload-from-phone' ),
				{ type: 'snackbar' }
			);

			void revoke( token );
			setUploadRequest( null );
		}, POLL_INTERVAL );

		return () => {
			cancelled = true;
			clearInterval( interval );
		};
	}, [ token, createErrorNotice, createSuccessNotice, revoke ] );

	// Expire the link in the UI at the moment the server would refuse it.
	useEffect( () => {
		if ( ! uploadRequest ) {
			return;
		}

		const remaining = uploadRequest.expires_at * 1000 - Date.now();

		const timeout = setTimeout(
			() => {
				setUploadRequest( null );
				void createErrorNotice(
					__( 'The upload link expired.', 'upload-from-phone' ),
					{ type: 'snackbar' }
				);
			},
			Math.max( 0, remaining )
		);

		return () => clearTimeout( timeout );
	}, [ uploadRequest, createErrorNotice ] );

	// Never leave a working link behind when the editor moves on.
	useEffect(
		() => () => {
			void revoke( tokenRef.current );
		},
		[ revoke ]
	);

	return { uploadRequest, isCreating, create, cancel };
}
