/**
 * External dependencies
 */
import { readFileSync, existsSync } from 'node:fs';

import { addCoverageReport } from 'monocart-reporter';
import type { V8CoverageEntry } from 'monocart-coverage-reports';
import type { Page } from '@playwright/test';

/**
 * WordPress dependencies
 */
import { test as base } from '@wordpress/e2e-test-utils-playwright';

type E2EFixture = {
	/**
	 * A second, independent browser context and page — standing in for "the
	 * phone" in tests. Logged out by default, same as the device that would
	 * actually scan the QR code.
	 */
	secondPage: Page;
};

// eslint-disable-next-line @typescript-eslint/no-unused-vars
function getSourceMapForEntry( entry: V8CoverageEntry, index?: number ) {
	if ( entry.sourceMap ) {
		return entry;
	}
	// Read the sourcemap manually for build assets that don't inline one.
	if ( entry.url.includes( 'plugins/upload-from-phone/build/' ) ) {
		let filePath = entry.url;
		// Turn localhost-8889/wp-content/plugins/upload-from-phone/build/editor.js?ver=abc123 into build/editor.js?ver=abc123.
		const i = filePath.indexOf( 'build/' );
		if ( i >= 0 ) {
			filePath = filePath.slice( i );
		}

		// Turn build/editor.js?ver=abc123 into build/editor.js.
		const j = filePath.indexOf( '?' );
		if ( j >= 0 ) {
			filePath = filePath.substring( 0, j );
		}

		if ( ! existsSync( `${ filePath }.map` ) ) {
			return entry;
		}

		entry.sourceMap = JSON.parse(
			readFileSync( `${ filePath }.map` ).toString( 'utf-8' )
		);
	}

	return entry;
}

/**
 * Redacts an upload request token before it reaches a CI log.
 *
 * The token is the entire authorisation for the upload endpoint and the
 * upload page alike, so it must not end up in retained, semi-public logs.
 *
 * @param url The URL to redact.
 */
function redactToken( url: string ): string {
	return url.replace( /[a-f0-9]{32}/g, '<redacted>' );
}

/**
 * Temporarily logs browser console/network activity to help diagnose a
 * WordPress-version-specific e2e failure.
 *
 * TODO: remove once that investigation is over.
 *
 * @param page The page to watch.
 */
function logBrowserDiagnostics( page: Page ): void {
	if ( process.env.E2E_DEBUG_LOG !== 'true' ) {
		return;
	}

	page.on( 'console', ( message ) => {
		if ( message.type() === 'error' || message.type() === 'warning' ) {
			// eslint-disable-next-line no-console
			console.log(
				`[browser ${ message.type() }] ${ redactToken(
					message.text()
				) }`
			);
		}
	} );
	page.on( 'pageerror', ( error ) => {
		// eslint-disable-next-line no-console
		console.log(
			`[browser pageerror] ${ redactToken(
				error.stack ?? error.message
			) }`
		);
	} );
	page.on( 'requestfailed', ( request ) => {
		// eslint-disable-next-line no-console
		console.log(
			`[browser requestfailed] ${ request.method() } ${ redactToken(
				request.url()
			) } — ${ request.failure()?.errorText }`
		);
	} );
	page.on( 'response', ( response ) => {
		if ( response.status() >= 400 ) {
			// eslint-disable-next-line no-console
			console.log(
				`[browser response] ${ response.status() } ${ redactToken(
					response.url()
				) }`
			);
		}
	} );
}

export const test = base.extend< E2EFixture, {} >( {
	page: async ( { page, browserName }, use ) => {
		logBrowserDiagnostics( page );

		if (
			browserName !== 'chromium' ||
			process.env.COLLECT_COVERAGE !== 'true'
		) {
			// This is Playwright's own fixture convention, not a React hook — the
			// callback just happens to be named `use`, which is enough to trip a
			// lint rule that assumes otherwise.
			// eslint-disable-next-line react-hooks/rules-of-hooks
			return use( page );
		}

		await Promise.all( [
			page.coverage.startJSCoverage( {
				resetOnNavigation: false,
			} ),
			page.coverage.startCSSCoverage( {
				resetOnNavigation: false,
			} ),
		] );

		// eslint-disable-next-line react-hooks/rules-of-hooks
		await use( page );

		const [ jsCoverage, cssCoverage ]: [
			V8CoverageEntry[],
			V8CoverageEntry[],
		] = await Promise.all( [
			page.coverage.stopJSCoverage(),
			page.coverage.stopCSSCoverage(),
		] );

		// Manually resolve the source map if it's missing.
		// See https://github.com/cenfun/monocart-coverage-reports#manually-resolve-the-sourcemap.
		jsCoverage.forEach( ( entry: V8CoverageEntry, index: number ) => {
			jsCoverage[ index ] = getSourceMapForEntry( entry );
		} );
		cssCoverage.forEach( ( entry: V8CoverageEntry, index: number ) => {
			cssCoverage[ index ] = getSourceMapForEntry( entry );
		} );

		const coverageList = [ ...jsCoverage, ...cssCoverage ];
		await addCoverageReport( coverageList, test.info() );
	},
	secondPage: async ( { browserName, browser }, use ) => {
		const context = await browser.newContext();
		const secondPage = await context.newPage();

		try {
			logBrowserDiagnostics( secondPage );

			if (
				browserName !== 'chromium' ||
				process.env.COLLECT_COVERAGE !== 'true'
			) {
				// This is Playwright's own fixture convention, not a React hook —
				// the callback just happens to be named `use`, which is enough to
				// trip a lint rule that assumes otherwise.
				// eslint-disable-next-line react-hooks/rules-of-hooks
				await use( secondPage );

				return;
			}

			await Promise.all( [
				secondPage.coverage.startJSCoverage( {
					resetOnNavigation: false,
				} ),
				secondPage.coverage.startCSSCoverage( {
					resetOnNavigation: false,
				} ),
			] );

			// eslint-disable-next-line react-hooks/rules-of-hooks
			await use( secondPage );

			const [ jsCoverage, cssCoverage ]: [
				V8CoverageEntry[],
				V8CoverageEntry[],
			] = await Promise.all( [
				secondPage.coverage.stopJSCoverage(),
				secondPage.coverage.stopCSSCoverage(),
			] );

			// Manually resolve the source map if it's missing.
			// See https://github.com/cenfun/monocart-coverage-reports#manually-resolve-the-sourcemap.
			jsCoverage.forEach( ( entry: V8CoverageEntry, index: number ) => {
				jsCoverage[ index ] = getSourceMapForEntry( entry );
			} );
			cssCoverage.forEach( ( entry: V8CoverageEntry, index: number ) => {
				cssCoverage[ index ] = getSourceMapForEntry( entry );
			} );

			const coverageList = [ ...jsCoverage, ...cssCoverage ];
			await addCoverageReport( coverageList, test.info() );
		} finally {
			await context.close();
		}
	},
} );

export { expect } from '@wordpress/e2e-test-utils-playwright';
