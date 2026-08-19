/**
 * WordPress dependencies
 */
import { Button } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { mobile } from '@wordpress/icons';

interface UploadFromPhoneButtonProps {
	/** Whether the upload link is currently being created. */
	isBusy?: boolean;
	/** Called when the person asks for a link. */
	onClick: () => void;
}

/**
 * The button that starts an upload from another device.
 *
 * @param props         Component props.
 * @param props.isBusy  Whether the upload link is currently being created.
 * @param props.onClick Called when the person asks for a link.
 */
export function UploadFromPhoneButton( {
	isBusy,
	onClick,
}: UploadFromPhoneButtonProps ) {
	return (
		<Button
			__next40pxDefaultSize
			variant="secondary"
			icon={ mobile }
			onClick={ onClick }
			isBusy={ isBusy }
			disabled={ isBusy }
			accessibleWhenDisabled
			className="upload-from-phone-button"
		>
			{ __( 'Upload from phone', 'upload-from-phone' ) }
		</Button>
	);
}
