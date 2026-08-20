/**
 * External dependencies
 */
import type { ComponentType, ReactNode } from 'react';
import type { store as uploadStore } from '@wordpress/upload-media';

/**
 * Internal dependencies
 */
import type { UploadFromPhoneData } from './types';

/**
 * Everything the page was told about this upload request.
 */
export const settings: UploadFromPhoneData = window.uploadFromPhone;

/**
 * The bits of `@wordpress/upload-media` this page uses.
 *
 * Typed against the real package but never imported from it. WordPress ships
 * the script, but a site can turn client-side processing off, and then PHP does
 * not enqueue it — a page that hard-depended on it would fail to load at all
 * rather than fall back to plain uploads. An `import type` is erased at build
 * time, which keeps the types without creating that dependency.
 */
interface UploadMediaPackage {
	store: typeof uploadStore;
	MediaUploadProvider: ComponentType< {
		settings: Record< string, unknown >;
		children?: ReactNode;
	} >;
}

/**
 * Returns the client-side media processing package, if this site has it.
 *
 * @return The package, or null where media is uploaded as-is.
 */
export function getUploadMedia(): UploadMediaPackage | null {
	if ( ! settings.clientSide ) {
		return null;
	}

	const uploadMedia = ( window as unknown as { wp?: Record< string, any > } )
		.wp?.uploadMedia;

	if ( ! uploadMedia?.store || ! uploadMedia?.MediaUploadProvider ) {
		return null;
	}

	return uploadMedia as UploadMediaPackage;
}

/**
 * The three transports the queue drives the server with.
 */
export interface QueueTransports {
	/** Creates the attachment from the main file. */
	mediaUpload: ( args: any ) => void;
	/** Adds one browser-generated sub-size to that attachment. */
	mediaSideload: ( args: any ) => void;
	/** Commits the attachment metadata once every sub-size is in. */
	mediaFinalize: ( id: number, subSizes: any[] ) => Promise< any >;
}

/**
 * Builds the settings the media processing queue runs on.
 *
 * These are the site's own image settings, handed to the page by PHP. The
 * editor reads the same values off the REST index, which is not an option
 * here: that data is only exposed to users who may upload files, and whoever
 * opened this link is not logged in at all.
 *
 * Everything the queue needs in order to do the whole job is here. Leaving out
 * `allImageSizes`, `mediaSideload` or `mediaFinalize` would not fail loudly —
 * the queue would simply skip the steps it cannot perform and upload the file
 * whole, which looks like it worked.
 *
 * @param transports The transports the queue should use.
 */
export function getQueueSettings(
	transports: QueueTransports
): Record< string, unknown > {
	return {
		...transports,
		allowedMimeTypes: settings.allowedMimeTypes,
		maxUploadFileSize: settings.maxUploadFileSize,
		allImageSizes: settings.allImageSizes,
		bigImageSizeThreshold: settings.bigImageSizeThreshold,
		imageStripMeta: settings.imageStripMeta,
		imageMaxBitDepth: settings.imageMaxBitDepth,

		/*
		 * `mediaDelete` is deliberately absent. The queue uses it to tidy up a
		 * parent attachment whose sub-sizes failed, which would mean letting a
		 * link that travels in a URL delete media. An attachment that is short
		 * of a few sizes is the better outcome; the queue skips the step when
		 * the setting is missing.
		 */
	};
}
