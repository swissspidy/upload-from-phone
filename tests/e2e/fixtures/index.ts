/**
 * External dependencies
 */
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

export const test = base.extend< E2EFixture, {} >( {
	secondPage: async ( { browser }, use ) => {
		const context = await browser.newContext();
		const secondPage = await context.newPage();

		// This is Playwright's own fixture convention, not a React hook — the
		// callback just happens to be named `use`, which is enough to trip a
		// lint rule that assumes otherwise.
		// eslint-disable-next-line react-hooks/rules-of-hooks
		await use( secondPage );

		await context.close();
	},
} );

export { expect } from '@wordpress/e2e-test-utils-playwright';
