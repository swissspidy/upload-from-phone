/**
 * External dependencies
 */
import type {
	CoverageReportOptions,
	V8CoverageEntry,
} from 'monocart-coverage-reports';
import { defineConfig, type ReporterDescription } from '@playwright/test';

/**
 * WordPress dependencies
 */
import baseConfig from '@wordpress/scripts/config/playwright.config';

const coverageReporter: ReporterDescription = [
	'monocart-reporter',
	{
		outputFile: './artifacts/e2e-coverage/report.html',
		coverage: {
			logging: 'debug',
			reports: [ [ 'codecov' ], [ 'v8' ], [ 'console-summary' ] ],
			entryFilter: ( entry: V8CoverageEntry ) => {
				return (
					entry.url.startsWith( 'blob:' ) ||
					entry.url.includes( 'plugins/upload-from-phone/build/' )
				);
			},
			sourceFilter: ( sourcePath: string ) => {
				return (
					sourcePath.startsWith( 'src/' ) &&
					! sourcePath.includes( 'node_modules/' )
				);
			},
			sourcePath: ( filePath: string ) => {
				// Remove project folder.
				return filePath.replace( 'upload-from-phone/', '' );
			},
		} as CoverageReportOptions,
	},
];

export default defineConfig( {
	...baseConfig,
	reporter: [
		// The base config always sets this to an array of reporter tuples; the
		// wider type is just `defineConfig()` losing the literal.
		...( baseConfig.reporter as ReporterDescription[] ),
		...( process.env.COLLECT_COVERAGE === 'true'
			? [ coverageReporter ]
			: [] ),
	],
} );
