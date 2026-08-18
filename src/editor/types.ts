/**
 * External dependencies
 */
import type { RestAttachment } from '@wordpress/media-utils';

export interface UploadRequest {
	/** Unique token identifying the upload request. */
	token: string;
	/** URL of the upload page. */
	url: string;
	/** Unix timestamp at which the upload request expires. */
	expires_at: number;
	/** Whether more than one file may be uploaded. */
	multiple: boolean;
	/** Maximum number of files the upload request accepts. */
	max_files: number;
	/** Whether the upload request has received all the files it accepts. */
	complete: boolean;
	/** IDs of the attachments uploaded so far. */
	attachment_ids: number[];
	/** The attachments uploaded so far. */
	attachments: RestAttachment[];
}

export interface CreateUploadRequestArgs {
	/** ID of the post the uploaded media should be attached to. */
	post?: number;
	/** Media types the upload request accepts. */
	allowed_types?: string[];
	/** Unique file type specifiers for the file picker. */
	accept?: string[];
	/** Whether more than one file may be uploaded. */
	multiple?: boolean;
}
