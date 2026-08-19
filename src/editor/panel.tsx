/**
 * External dependencies
 */
import { QRCodeSVG } from 'qrcode.react';

/**
 * WordPress dependencies
 */
import {
	Button,
	Placeholder,
	TextControl,
	__experimentalText as Text, // eslint-disable-line @wordpress/no-unsafe-wp-apis
} from '@wordpress/components';
import { useCopyToClipboard } from '@wordpress/compose';
import { useDispatch } from '@wordpress/data';
import { useEffect, useState } from '@wordpress/element';
import { store as noticesStore } from '@wordpress/notices';
import { __, _n, sprintf } from '@wordpress/i18n';
import { copy, mobile } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import type { UploadRequest } from './types';
import './editor.css';

/**
 * How often the remaining time is recalculated, in milliseconds.
 *
 * The countdown is shown in whole minutes for all but the last one, so there is
 * nothing to gain from ticking every second — and a good deal to lose, since
 * every tick re-renders the QR code alongside it.
 */
const COUNTDOWN_INTERVAL = 5000;

interface UploadRequestPanelProps {
	/** The upload request being shown. */
	uploadRequest: UploadRequest;
	/** Called when the person abandons the upload. */
	onCancel: () => void;
}

/**
 * Seconds between now and a deadline, floored at zero.
 *
 * @param expiresAt Unix timestamp at which the upload request expires.
 */
function secondsUntil( expiresAt: number ): number {
	return Math.max(
		0,
		Math.round( ( expiresAt * 1000 - Date.now() ) / 1000 )
	);
}

/**
 * Tracks how much longer an upload request is good for.
 *
 * @param expiresAt Unix timestamp at which the upload request expires.
 */
function useSecondsRemaining( expiresAt: number ): number {
	const [ remaining, setRemaining ] = useState( () =>
		secondsUntil( expiresAt )
	);

	useEffect( () => {
		// A new request means a new deadline; don't wait a tick to show it.
		setRemaining( secondsUntil( expiresAt ) );

		const interval = setInterval(
			() => setRemaining( secondsUntil( expiresAt ) ),
			COUNTDOWN_INTERVAL
		);

		return () => clearInterval( interval );
	}, [ expiresAt ] );

	return remaining;
}

/**
 * Shows the QR code and link for an upload request, inside the block itself.
 *
 * This deliberately takes the place of the block's media placeholder rather
 * than opening a dialog over the editor: waiting on someone else's phone can
 * take a while, and there is no reason for the rest of the post to be
 * unreachable in the meantime.
 *
 * @param props               Component props.
 * @param props.uploadRequest The upload request being shown.
 * @param props.onCancel      Called when the person abandons the upload.
 */
export function UploadRequestPanel( {
	uploadRequest,
	onCancel,
}: UploadRequestPanelProps ) {
	const { createNotice } = useDispatch( noticesStore );

	const copyRef = useCopyToClipboard( uploadRequest.url, () => {
		void createNotice(
			'info',
			__( 'Copied link to clipboard.', 'upload-from-phone' ),
			{ isDismissible: true, type: 'snackbar' }
		);
	} );

	const secondsRemaining = useSecondsRemaining( uploadRequest.expires_at );
	const received = uploadRequest.attachment_ids.length;

	const status =
		received > 0
			? sprintf(
					/* translators: %d: Number of files received so far. */
					_n(
						'%d file received. Waiting for the phone to finish…',
						'%d files received. Waiting for the phone to finish…',
						received,
						'upload-from-phone'
					),
					received
			  )
			: __( 'Waiting for your upload…', 'upload-from-phone' );

	const expiry =
		secondsRemaining >= 60
			? sprintf(
					/* translators: %d: Number of minutes the link remains valid. */
					_n(
						'This link expires in %d minute.',
						'This link expires in %d minutes.',
						Math.floor( secondsRemaining / 60 ),
						'upload-from-phone'
					),
					Math.floor( secondsRemaining / 60 )
			  )
			: __(
					'This link expires in less than a minute.',
					'upload-from-phone'
			  );

	return (
		<Placeholder
			icon={ mobile }
			label={ __( 'Upload from phone', 'upload-from-phone' ) }
			instructions={ __(
				'Scan this code with your phone to open the upload page. You can carry on editing the rest of the post while you wait — the media drops into this block as soon as it arrives.',
				'upload-from-phone'
			) }
			className="upload-from-phone-panel"
		>
			<div className="upload-from-phone-panel__body">
				<div className="upload-from-phone-panel__qrcode">
					<QRCodeSVG
						value={ uploadRequest.url }
						title={ __(
							'QR code for the upload page',
							'upload-from-phone'
						) }
						marginSize={ 2 }
					/>
				</div>

				<div className="upload-from-phone-panel__details">
					<div className="upload-from-phone-panel__link">
						<TextControl
							__next40pxDefaultSize
							__nextHasNoMarginBottom
							label={ __( 'Upload link', 'upload-from-phone' ) }
							hideLabelFromVision
							value={ uploadRequest.url }
							readOnly
							onChange={ () => {} }
							onFocus={ ( event ) => event.target.select() }
						/>

						<Button
							__next40pxDefaultSize
							variant="secondary"
							ref={ copyRef }
							icon={ copy }
							showTooltip={ false }
							label={ __( 'Copy link', 'upload-from-phone' ) }
						/>
					</div>

					{ /*
					 * Only the arrival of files is worth interrupting a
					 * screen reader for. The countdown next to it changes
					 * under its own steam every minute, and announcing that
					 * on a loop would drown out the part that matters.
					 */ }
					<p
						className="upload-from-phone-panel__status"
						aria-live="polite"
					>
						<Text variant="muted">{ status }</Text>
					</p>

					<p className="upload-from-phone-panel__expiry">
						<Text variant="muted">{ expiry }</Text>
					</p>

					<div className="upload-from-phone-panel__actions">
						<Button
							__next40pxDefaultSize
							variant="tertiary"
							onClick={ onCancel }
						>
							{ __( 'Cancel', 'upload-from-phone' ) }
						</Button>
					</div>
				</div>
			</div>
		</Placeholder>
	);
}
