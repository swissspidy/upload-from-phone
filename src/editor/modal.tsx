/**
 * External dependencies
 */
import { QRCodeSVG } from 'qrcode.react';

/**
 * WordPress dependencies
 */
import {
	Button,
	Modal,
	TextControl,
	__experimentalText as Text, // eslint-disable-line @wordpress/no-unsafe-wp-apis
} from '@wordpress/components';
import { useCopyToClipboard } from '@wordpress/compose';
import { useDispatch } from '@wordpress/data';
import { store as noticesStore } from '@wordpress/notices';
import { __, _n, sprintf } from '@wordpress/i18n';
import { copy } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import type { UploadRequest } from './types';
import './editor.css';

interface UploadRequestModalProps {
	/** The upload request being shown. */
	uploadRequest: UploadRequest;
	/** Called when the person closes the dialog. */
	onRequestClose: () => void;
}

/**
 * Shows the QR code and link for an upload request.
 *
 * @param props                Component props.
 * @param props.uploadRequest  The upload request being shown.
 * @param props.onRequestClose Called when the person closes the dialog.
 */
export function UploadRequestModal( {
	uploadRequest,
	onRequestClose,
}: UploadRequestModalProps ) {
	const { createNotice } = useDispatch( noticesStore );

	const copyRef = useCopyToClipboard( uploadRequest.url, () => {
		void createNotice(
			'info',
			__( 'Copied link to clipboard.', 'upload-from-phone' ),
			{ isDismissible: true, type: 'snackbar' }
		);
	} );

	const received = uploadRequest.attachment_ids.length;

	return (
		<Modal
			title={ __( 'Upload from phone', 'upload-from-phone' ) }
			onRequestClose={ onRequestClose }
			className="upload-from-phone-modal"
		>
			<p>
				<Text>
					{ __(
						'Scan this code with your phone to open the upload page.',
						'upload-from-phone'
					) }
				</Text>
			</p>

			<div className="upload-from-phone-modal__qrcode">
				<QRCodeSVG
					value={ uploadRequest.url }
					title={ __(
						'QR code for the upload page',
						'upload-from-phone'
					) }
					marginSize={ 2 }
				/>
			</div>

			<p>
				<Text>
					{ __(
						'Or open this link on the other device:',
						'upload-from-phone'
					) }
				</Text>
			</p>

			<div className="upload-from-phone-modal__link">
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

			<p className="upload-from-phone-modal__status">
				<Text variant="muted">
					{ received > 0
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
						: __(
								'Waiting for your upload. This window closes on its own once the media arrives.',
								'upload-from-phone'
						  ) }
				</Text>
			</p>

			<div className="upload-from-phone-modal__actions">
				<Button
					__next40pxDefaultSize
					variant="tertiary"
					onClick={ onRequestClose }
				>
					{ __( 'Cancel', 'upload-from-phone' ) }
				</Button>
			</div>
		</Modal>
	);
}
