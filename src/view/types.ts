/**
 * External dependencies
 */
import type { Attachment } from '@wordpress/media-utils';

/**
 * A registered image size, as the client-side pipeline expects it.
 */
export interface ImageSize {
	name: string;
	width: number;
	height: number;
	crop: boolean | [ string, string ];
}

export interface UploadFromPhoneData {
	/** Absolute URL of core's media endpoint. */
	mediaUrl: string;
	/** The upload request token. */
	token: string;
	/** Allowed media types, e.g. `image`. Empty means no restriction. */
	allowedTypes: string[];
	/** Allowed mime types, keyed by file extension regex. */
	allowedMimeTypes: Record< string, string >;
	/** Unique file type specifiers for the file picker. */
	accept: string[];
	/** Whether more than one file may be uploaded. */
	multiple: boolean;
	/** Maximum number of files this upload request accepts. */
	maxFiles: number;
	/** Maximum upload file size in bytes. */
	maxUploadFileSize: number;
	/** Unix timestamp at which the upload request expires. */
	expiresAt: number;
	/** Whether this site processes media in the browser before uploading. */
	clientSide: boolean;
	/** Every registered image size. Only sent when `clientSide` is true. */
	allImageSizes?: Record< string, ImageSize >;
	/** Width or height above which an image is scaled down. */
	bigImageSizeThreshold?: number;
	/** Whether to strip metadata when encoding. */
	imageStripMeta?: boolean;
	/** Maximum output bit depth for generated images. */
	imageMaxBitDepth?: number;
}

/**
 * How far along a single file is.
 *
 * `processing` covers everything the browser does to a file before it goes
 * anywhere — converting a HEIC, scaling an oversized photo, cutting the
 * registered sizes — which on a phone can take longer than the upload itself
 * and is worth naming rather than hiding behind a spinner.
 */
export type UploadState =
	| 'waiting'
	| 'processing'
	| 'uploading'
	| 'done'
	| 'failed';

/**
 * One line in the file list.
 */
export interface UploadRow {
	/** Stable key for React. */
	key: string;
	/** File name to show. */
	name: string;
	/** What is happening to this file. */
	state: UploadState;
	/** Fraction between 0 and 1, when the upload itself is in progress. */
	progress?: number;
	/** Why it failed. */
	error?: string;
}

/**
 * What the page needs from whichever uploader is in play.
 */
export interface Uploader {
	/** Every file the person has handed over, settled ones first. */
	rows: UploadRow[];
	/** How many files have been uploaded successfully. */
	uploadedCount: number;
	/** Whether anything is still in flight. */
	isBusy: boolean;
	/** Hands more files over. */
	addFiles: ( files: File[] ) => void;
}

declare global {
	interface Window {
		uploadFromPhone: UploadFromPhoneData;
	}
}

export type { Attachment };
