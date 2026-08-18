/**
 * Shims for the WordPress packages that do not ship type declarations.
 *
 * Only the bits this plugin actually touches are described; everything else
 * stays `any` rather than pretending to a fidelity these packages do not have.
 */

declare module '@wordpress/block-editor' {
	import type { ComponentType, ReactNode } from 'react';

	export const MediaUploadCheck: ComponentType< {
		fallback?: ReactNode;
		children?: ReactNode;
	} >;
}

declare module '@wordpress/editor' {
	export const store: import('@wordpress/data').StoreDescriptor & {
		name: string;
	};
}

declare module '@wordpress/notices' {
	export const store: import('@wordpress/data').StoreDescriptor & {
		name: string;
	};
}
