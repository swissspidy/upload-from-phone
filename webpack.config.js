/**
 * External dependencies
 */
const { resolve } = require( 'node:path' );

/**
 * WordPress dependencies
 */
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );

module.exports = {
	...defaultConfig,
	entry: {
		editor: resolve( __dirname, 'src/editor/index.tsx' ),
		view: resolve( __dirname, 'src/view/index.ts' ),
	},
	output: {
		...defaultConfig.output,
		filename: '[name].js',
		path: resolve( __dirname, 'build' ),
	},
	resolve: {
		...defaultConfig.resolve,
		extensions: [ '.ts', '.tsx', '...' ],
	},
};
