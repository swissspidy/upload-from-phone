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
 * Adds an "Upload from phone" button to every media placeholder in the editor.
 *
 * Hooking the shared placeholder rather than individual blocks means image,
 * video, audio, gallery, cover, and any third-party block built on the same
 * component all get the feature without this plugin knowing about them.
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

			// Drop zones and other button-less placeholders have nothing to add to.
			if ( disableMediaButtons || ! onSelect ) {
				return <MediaPlaceholder { ...props } />;
			}

			const isMultiple = Boolean( multiple );

			const button = (
				<MediaUploadCheck key="upload-from-phone">
					<UploadFromPhoneButton
						allowedTypes={ allowedTypes }
						accept={ accept }
						multiple={ isMultiple }
						onSelect={ ( media ) =>
							onSelect( isMultiple ? media : media[ 0 ] )
						}
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
