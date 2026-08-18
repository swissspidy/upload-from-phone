export interface UploadFromPhoneData {
	/** REST endpoint that accepts the files for this upload request. */
	restUrl: string;
	/** The upload request token. */
	token: string;
	/** Allowed media types, e.g. `image`. Empty means no restriction. */
	allowedTypes: string[];
	/** Allowed mime types, keyed by file extension regex. */
	allowedMimeTypes: Record< string, string > | null;
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
}

export interface UploadResponse {
	id: number;
	title: string;
	mime_type: string;
	complete: boolean;
}

declare global {
	interface Window {
		uploadFromPhone: UploadFromPhoneData;
	}
}
