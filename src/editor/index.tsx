/**
 * WordPress dependencies
 */
import { addFilter } from '@wordpress/hooks';
import { MediaUploadCheck } from '@wordpress/block-editor';
import type { ComponentType, ReactElement, ReactNode } from 'react';

/**
 * Internal dependencies
 */
import { UploadFromPhoneButton } from './upload-from-phone-button';
import { UploadRequestPanel } from './panel';
import { useUploadRequest } from './use-upload-request';

interface MediaPlaceholderProps {
	allowedTypes?: string[];
	accept?: string | string[];
	multiple?: boolean | string;
	disableMediaButtons?: boolean;
	onSelect?: ( media: unknown ) => void;
	placeholder?: ( content: ReactNode ) => ReactElement;
	children?: ReactNode;
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

	if ( Array.isArray( accept ) ) {
		return accept;
	}

	// A trailing or doubled comma would otherwise reach the server as an
	// empty file type specifier.
	return accept
		.split( ',' )
		.map( ( value ) => value.trim() )
		.filter( Boolean );
}

/**
 * Adds an "Upload from phone" button to every media placeholder in the editor.
 *
 * Hooking the shared placeholder rather than individual blocks means image,
 * video, audio, gallery, cover, and any third-party block built on the same
 * component all get the feature without this plugin knowing about them.
 *
 * It also puts this component exactly where the waiting has to be shown, which
 * is what lets the QR code live inside the block instead of in a dialog over
 * the editor. Each placeholder owns its own request, so several blocks can be
 * waiting on different phones at once without knowing about each other.
 */
addFilter(
	'editor.MediaPlaceholder',
	'upload-from-phone/media-placeholder',
	( MediaPlaceholder: ComponentType< MediaPlaceholderProps > ) =>
		function UploadFromPhoneMediaPlaceholder(
			props: MediaPlaceholderProps
		) {
			const {
				allowedTypes,
				accept,
				multiple,
				disableMediaButtons,
				onSelect,
				placeholder,
				children,
			} = props;

			const isMultiple = Boolean( multiple );

			const { uploadRequest, isCreating, create, cancel } =
				useUploadRequest( {
					allowedTypes,
					accept: toArray( accept ),
					multiple: isMultiple,
					onSelect: ( media ) =>
						onSelect?.( isMultiple ? media : media[ 0 ] ),
				} );

			// Drop zones and other button-less placeholders have nothing to add to.
			if ( disableMediaButtons || ! onSelect ) {
				return <MediaPlaceholder { ...props } />;
			}

			/*
			 * While a phone is on its way, the panel stands in for the
			 * placeholder. Replacing it rather than rendering alongside it
			 * keeps the block from offering two ways to fill itself at once,
			 * and leaves the block toolbar and inspector untouched — the rest
			 * of the post stays as editable as it was.
			 */
			if ( uploadRequest ) {
				return (
					<UploadRequestPanel
						uploadRequest={ uploadRequest }
						onCancel={ cancel }
					/>
				);
			}

			const button = (
				<MediaUploadCheck key="upload-from-phone">
					<UploadFromPhoneButton
						isBusy={ isCreating }
						onClick={ create }
					/>
				</MediaUploadCheck>
			);

			/*
			 * Blocks such as image and video render their own placeholder and
			 * only pass `content` through, ignoring `children`. Wrapping their
			 * renderer puts the button in with the other buttons either way.
			 */
			if ( placeholder ) {
				return (
					<MediaPlaceholder
						{ ...props }
						placeholder={ ( content: ReactNode ) =>
							placeholder(
								<>
									{ content }
									{ button }
								</>
							)
						}
					/>
				);
			}

			return (
				<MediaPlaceholder { ...props }>
					{ children }
					{ button }
				</MediaPlaceholder>
			);
		}
);
