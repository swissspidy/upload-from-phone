# Upload from Phone

[![Code Coverage](https://codecov.io/gh/swissspidy/upload-from-phone/branch/main/graph/badge.svg)](https://codecov.io/gh/swissspidy/upload-from-phone)
[![License](https://img.shields.io/github/license/swissspidy/upload-from-phone)](https://github.com/swissspidy/upload-from-phone/blob/main/LICENSE)

Upload photos and videos into a post straight from your phone. Scan a QR code, pick your files, done — no app, and no login on the phone.

This started life as a feature of [Media Experiments](https://github.com/swissspidy/media-experiments) and now stands on its own.

https://github.com/swissspidy/media-experiments/assets/841956/b0b63f19-7f78-4a8d-9255-b59ad996368a

## Quick Start

Install and activate the latest nightly build on your WordPress website, open a post, and click **Upload from phone** on any media block.

[![Download latest nightly build](https://img.shields.io/badge/Download%20latest%20nightly-24282D?style=for-the-badge&logo=Files&logoColor=ffffff)](https://swissspidy.github.io/upload-from-phone/nightly.zip)

Note: Requires **WordPress 7.1+** and **PHP 8.0+**.

### Using WordPress Playground

Use [WordPress Playground](https://wordpress.org/playground/) to try this plugin directly in the browser, without installing it on your site:

[![Test on WordPress Playground](https://img.shields.io/badge/Test%20on%20WordPress%20Playground-3F57E1?style=for-the-badge&logo=WordPress&logoColor=ffffff)](https://playground.wordpress.net/?mode=seamless&blueprint-url=https://raw.githubusercontent.com/swissspidy/upload-from-phone/main/blueprints/playground.json)

**Note:** The upload page in Playground has to be opened by whatever device is running Playground itself — Playground is not reachable from a phone on your local network.

## How it works

While editing a post, **Upload from phone** appears on any media block. Clicking it creates a short-lived upload request and shows its link as a QR code, in place of the block's own placeholder.

An upload request is a post of a private, UI-less post type whose slug is a 128-bit random token. That token is the only credential involved, so it is treated as one:

| | |
|---|---|
| Address | 32 hex characters from `random_bytes()` — not derived from the clock, not sequential |
| Lifetime | 15 minutes, checked on every use rather than trusted to cron |
| Scope | Creating one attachment and finishing it. No reading, no editing anything else, no listing |
| Capacity | One file, or the block's limit for multi-file blocks; refused once full |
| File types | Restricted to what the block asked for, enforced server-side |
| Attribution | Uploads are credited to whoever created the link, and stop working if that account loses `upload_files` |

Unknown, expired, and inaccessible tokens are answered identically, so the endpoints cannot be used to find out which tokens exist.

The editor polls the request until the phone stops sending, hands the media to the block, and revokes the link — including when the editor is closed mid-upload.

Waiting happens inside the block, not in a dialog over the editor. Someone else's phone, on someone else's signal, is not worth holding a post hostage to: the rest of the post stays editable while a link is outstanding, the block keeps its toolbar and inspector, and several blocks can each be waiting on a link of their own. Each block shows what has arrived so far and how much longer its link is good for, and the media drops into that block whenever it lands.

## Architecture notes

**Files go through core's media endpoints, under a narrow exception.** Managing the link — asking for one, polling it, revoking it — lives in this plugin's own `upload-from-phone/v1` namespace, done by the editor as a logged-in user. The files themselves go to `POST /wp/v2/media`, `/sideload`, and `/finalize`, because a client-processed upload is all three working together: the browser creates the attachment, sideloads each image size it cut, and asks the server to commit the metadata. A private endpoint of our own could only ever stand in for the first of them.

`WP_REST_Attachments_Controller` is not subclassed for this — that would put the plugin in conflict with every other plugin that does the same. Instead a token buys a request-scoped exception, installed once the route is matched and taken back as soon as the callback returns. It grants the two capabilities those endpoints ask for and nothing else, `edit_post` only for attachments that very request produced, and refuses any parameter beyond what the upload page needs — so a token cannot name a post to attach to, an author to attribute to, or a URL for the server to go and fetch.

**One editor integration, not one per block.** The `editor.MediaPlaceholder` filter covers Image, Video, Audio, Gallery, Cover, File, and any third-party block built on the same component, without this plugin knowing any of their names. It also lands exactly where the waiting has to be shown, so the QR code can take the placeholder's place without a per-block registry of who is waiting, and without wrapping `BlockEdit` — which would mean replacing the whole block, controls and all, to change the one part of it that is empty.

**Media processing is WordPress's job, and it does the whole job.** The upload page hands every file to `wp-upload-media`, WordPress's client-side pipeline, which converts what the server cannot read, scales anything past the site's big-image threshold, cuts every registered image size, and only then uploads. The phone does the work the server would otherwise do, and the site gets a complete attachment — sizes, `srcset`, and all — without a round trip per size.

That only happens in a cross-origin isolated context, because the pipeline runs on wasm-vips and wasm-vips needs `SharedArrayBuffer`. The upload page sends those headers itself: `Document-Isolation-Policy` on Chromium 137+, matching what WordPress sends in the editor, and the older COOP/COEP pair everywhere else. WordPress skips that fallback in wp-admin, where COEP would break plugins embedding cross-origin iframes; this page is the plugin's alone and loads nothing but its own same-origin assets, so it is safe here and reaches considerably more phones.

Everything the pipeline needs is handed to the page by PHP — the registered image sizes, the big-image threshold, `image_strip_meta`, `image_max_bit_depth` — read through the same filters the server-side image editor reads them through. The editor gets these off the REST index, which is not an option here: that data is only exposed to users who may upload files, and whoever opened the link is not logged in at all. Leaving any of it out would not fail loudly; the pipeline would quietly skip the step it could not perform and upload the file whole.

Where WordPress reports client-side processing unavailable — a site not served over HTTPS, or one that has turned it off — the page uploads files as they are, through the same core endpoint. `upload_from_phone_client_side_processing` turns it off independently.

## Hooks

### Filters

| Filter | Description |
|---|---|
| `upload_from_phone_request_ttl` | How long a link stays valid, in seconds. Default 15 minutes, floor of 1 minute. |
| `upload_from_phone_max_files` | Maximum files per link for multi-file requests. Default 20. |
| `upload_from_phone_rewrite_slug` | URL prefix of the upload page. Default `upload`. |
| `upload_from_phone_template` | Absolute path to the template rendering the upload page. |
| `upload_from_phone_client_side_processing` | Whether the upload page routes files through `wp-upload-media`. Default `true`, where WordPress offers it. |
| `upload_from_phone_cross_origin_isolation` | Whether the upload page sends cross-origin isolation headers. Default `true`. Turning it off leaves the pipeline loaded but unable to touch an image. |
| `upload_from_phone_allowed_media_params` | Parameters an upload request may send to core's media endpoints. |

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
| `POST /wp/v2/media` | The token, as `upload_request` | Create the attachment |
| `POST /wp/v2/media/<id>/sideload` | The token, for an attachment it created | Add one browser-generated image size |
| `POST /wp/v2/media/<id>/finalize` | The token, for an attachment it created | Commit the attachment metadata |

The last three are core's own. The phone sends no nonce, deliberately: the REST API treats a nonce-less request as logged out, which is exactly the permission model wanted here, and the token in the URL is the only credential that counts.

An attachment the browser is still working on is withheld from the editor until `finalize` lands. It exists from the moment its file is uploaded, but its sizes and its final URL only arrive at the end, and a block that saw it early would keep pointing at a file about to be replaced.

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
