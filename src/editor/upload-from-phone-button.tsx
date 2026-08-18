/**
 * External dependencies
 */
import type { Attachment } from '@wordpress/media-utils';

/**
 * WordPress dependencies
 */
import { Button } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { mobile } from '@wordpress/icons';

/**
 * Internal dependencies
 */
import { UploadRequestModal } from './modal';
import { useUploadRequest } from './use-upload-request';

interface UploadFromPhoneButtonProps {
	/** Media types the block accepts. */
	allowedTypes?: string[];
	/** Unique file type specifiers for the file picker. */
	accept?: string | string[];
	/** Whether the block accepts more than one file. */
	multiple?: boolean;
	/** Called with the media once the phone is done uploading. */
	onSelect: ( media: Attachment[] ) => void;
}

/**
 * Normalises the `accept` prop, which blocks pass as either a string or an array.
 *
 * @param accept The value to normalise.
 */
function toArray( accept?: string | string[] ): string[] | undefined {
	if ( ! accept ) {
		return undefined;
	}

	return Array.isArray( accept )
		? accept
		: accept.split( ',' ).map( ( value ) => value.trim() );
}

/**
 * The button that starts an upload from another device.
 *
 * @param props              Component props.
 * @param props.allowedTypes Media types the block accepts.
 * @param props.accept       Unique file type specifiers for the file picker.
 * @param props.multiple     Whether the block accepts more than one file.
 * @param props.onSelect     Called with the media once the phone is done uploading.
 */
export function UploadFromPhoneButton( {
	allowedTypes,
	accept,
	multiple,
	onSelect,
}: UploadFromPhoneButtonProps ) {
	const { uploadRequest, isCreating, create, cancel } = useUploadRequest( {
		allowedTypes,
		accept: toArray( accept ),
		multiple,
		onSelect,
	} );

	return (
		<>
			<Button
				__next40pxDefaultSize
				variant="secondary"
				icon={ mobile }
				onClick={ create }
				isBusy={ isCreating }
				disabled={ isCreating }
				accessibleWhenDisabled
				className="upload-from-phone-button"
			>
				{ __( 'Upload from phone', 'upload-from-phone' ) }
			</Button>

			{ uploadRequest && (
				<UploadRequestModal
					uploadRequest={ uploadRequest }
					onRequestClose={ cancel }
				/>
			) }
		</>
	);
}
