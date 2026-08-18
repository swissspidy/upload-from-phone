# Upload from Phone

[![License](https://img.shields.io/github/license/swissspidy/upload-from-phone)](https://github.com/swissspidy/upload-from-phone/blob/main/LICENSE)

Upload photos and videos into a post straight from your phone. Scan a QR code, pick your files, done — no app, and no login on the phone.

This started life as a feature of [Media Experiments](https://github.com/swissspidy/media-experiments) and now stands on its own.

https://github.com/swissspidy/media-experiments/assets/841956/b0b63f19-7f78-4a8d-9255-b59ad996368a

## Quick Start

Install and activate the latest nightly build on your WordPress website, open a post, and click **Upload from phone** on any media block.

[![Download latest nightly build](https://img.shields.io/badge/Download%20latest%20nightly-24282D?style=for-the-badge&logo=Files&logoColor=ffffff)](https://swissspidy.github.io/upload-from-phone/nightly.zip)

Note: Requires **WordPress 6.8+** and **PHP 8.0+**.

### Using WordPress Playground

Use [WordPress Playground](https://wordpress.org/playground/) to try this plugin directly in the browser, without installing it on your site:

[![Test on WordPress Playground](https://img.shields.io/badge/Test%20on%20WordPress%20Playground-3F57E1?style=for-the-badge&logo=WordPress&logoColor=ffffff)](https://playground.wordpress.net/?mode=seamless&blueprint-url=https://raw.githubusercontent.com/swissspidy/upload-from-phone/main/blueprints/playground.json)

**Note:** The upload page in Playground has to be opened by whatever device is running Playground itself — Playground is not reachable from a phone on your local network.

## How it works

While editing a post, **Upload from phone** appears on any media block. Clicking it creates a short-lived upload request and shows its link as a QR code.

An upload request is a post of a private, UI-less post type whose slug is a 128-bit random token. That token is the only credential involved, so it is treated as one:

| | |
|---|---|
| Address | 32 hex characters from `random_bytes()` — not derived from the clock, not sequential |
| Lifetime | 15 minutes, checked on every use rather than trusted to cron |
| Scope | Uploading only. No reading, no editing, no listing |
| Capacity | One file, or the block's limit for multi-file blocks; refused once full |
| File types | Restricted to what the block asked for, enforced server-side |
| Attribution | Uploads are credited to whoever created the link, and stop working if that account loses `upload_files` |

Unknown, expired, and inaccessible tokens all return the same 404, so the endpoint cannot be used to find out which tokens exist.

The editor polls the request until the phone stops sending, hands the media to the block, and revokes the link — including when the editor is closed mid-upload.

## Architecture notes

**The core attachments controller is left alone.** Everything lives under this plugin's own `upload-from-phone/v1` REST namespace. Subclassing `WP_REST_Attachments_Controller` would put the plugin in conflict with every other plugin that does the same, and would widen a core endpoint's permission model for the sake of one feature.

**One editor integration, not one per block.** The `editor.MediaPlaceholder` filter covers Image, Video, Audio, Gallery, Cover, File, and any third-party block built on the same component, without this plugin knowing any of their names.

**Media processing is WordPress's job, and off by default.** The upload page knows how to route files through `wp-upload-media`, WordPress's client-side pipeline, but does not do so unless asked. That pipeline pulls in `wp-components`, `wp-preferences`, and the image and video conversion scaffolding — a serious download for a page whose entire job is a file picker, on a device very likely to be on mobile data. The case it would help most with, HEIC from an iPhone, is already handled: iOS converts HEIC to JPEG on its way through a file input.

Sites that would rather spend the bytes can turn it on with `upload_from_phone_client_side_processing`. As shipped, the upload page is about 5 KB of JavaScript and depends only on `wp-i18n`.

## Hooks

### Filters

| Filter | Description |
|---|---|
| `upload_from_phone_request_ttl` | How long a link stays valid, in seconds. Default 15 minutes, floor of 1 minute. |
| `upload_from_phone_max_files` | Maximum files per link for multi-file requests. Default 20. |
| `upload_from_phone_rewrite_slug` | URL prefix of the upload page. Default `upload`. |
| `upload_from_phone_template` | Absolute path to the template rendering the upload page. |
| `upload_from_phone_client_side_processing` | Whether the upload page routes files through `wp-upload-media`. Default `false` — see above. |

### Actions

| Action | Description |
|---|---|
| `upload_from_phone_media_uploaded` | Fires after a file arrives, with the attachment ID and the request post. The hook for post-processing — optimisation, format conversion, alt text. |
| `upload_from_phone_request_created` | Fires after a link is created. |
| `upload_from_phone_request_deleted` | Fires before a link is deleted. |

Post-processing example:

```php
add_action(
	'upload_from_phone_media_uploaded',
	static function ( int $attachment_id ): void {
		// Optimise, tag, or notify — the attachment is fully registered by now.
	}
);
```

## REST API

| Endpoint | Auth | Purpose |
|---|---|---|
| `POST /upload-from-phone/v1/upload-requests` | `upload_files`, plus `edit_post` for the target post | Create a link |
| `GET /upload-from-phone/v1/upload-requests/<token>` | Owner, or `edit_others_posts` | Poll for what has arrived |
| `DELETE /upload-from-phone/v1/upload-requests/<token>` | Owner, or `edit_others_posts` | Revoke a link |
| `POST /upload-from-phone/v1/upload-requests/<token>/media` | The token | Upload a file |

The upload endpoint takes the file as the raw request body with `?filename=` in the query string — the same shape the core media endpoint accepts, minus a layer of encoding on a connection that may be slow, and with no non-ASCII file names stuffed into request headers.

## Development

```bash
npm install
composer install

npm run build       # Build the assets
npm start           # Build and watch
npm run lint:js     # Lint JavaScript
npm run lint:css    # Lint styles
npm run typecheck   # Type check
composer lint       # Lint PHP
composer phpstan    # Static analysis

npx playwright install chromium   # First time only — Playwright manages its own browser binaries
npm run wp-env start               # Start a local WordPress
npm run test:e2e                   # Run the end-to-end tests against it
```

## License

GPL-2.0-or-later
