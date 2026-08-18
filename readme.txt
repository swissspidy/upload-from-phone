=== Upload from Phone ===

Contributors:      swissspidy
Tags:              media, upload, mobile, images, qr code
Requires at least: 6.8
Tested up to:      7.0
Requires PHP:      8.0
Stable tag:        0.1.0
License:           GPLv2 or later
License URI:       https://www.gnu.org/licenses/old-licenses/gpl-2.0.html

Upload photos and videos into a post straight from your phone. Scan a QR code, pick your files, done.

== Description ==

The photo you want is on your phone. The post you are writing is on your laptop. Getting one to the other usually means emailing yourself a file, or plugging in a cable, or syncing something.

This plugin removes that step. While editing a post, click **Upload from phone** on any media block. A QR code appears. Scan it, choose your photos, and they land in the post you are editing — no app to install, and nobody has to log in on the phone.

It works for anyone you hand the link to, not just your own phone. Send it to a colleague standing at the event and their photos come straight back into your draft.

= How it works =

Each upload gets its own single-use link with a random, unguessable address. The link:

* expires after 15 minutes,
* stops working as soon as the files it was expecting have arrived,
* only accepts the kinds of files the block asked for,
* and grants nothing beyond uploading — no reading, no editing, no browsing the media library.

Nothing is left behind afterwards. Expired links are deleted automatically.

= Works with the media blocks you already use =

Image, Video, Audio, Gallery, Cover, File — and any other block built on the standard WordPress media placeholder, including blocks from other plugins. No configuration required.

= Built to be light =

The upload page is deliberately tiny — it has to open quickly on a phone that may be on a weak mobile connection, and it does not load your theme or any other plugin's assets. Photos from an iPhone are handled without any of that: iOS converts them to a web-friendly format on the way through the file picker.

== Frequently Asked Questions ==

= Does the person uploading need an account? =

No. The link is the only credential, which is why it is random, short-lived, and single-use.

= Where does the media end up? =

Attached to the post being edited, in your media library, credited to whoever created the link.

= How long is a link valid? =

15 minutes by default. Developers can change this with the `upload_from_phone_request_ttl` filter.

= Can I send more than one file? =

Yes, wherever the block accepts more than one — a Gallery, for instance. The limit is 20 files per link by default, adjustable with the `upload_from_phone_max_files` filter.

= Does this work if my site is not reachable from the internet? =

The phone needs to be able to open the link. On a local development site, that means the phone has to be on the same network and able to reach the site's address.

== Screenshots ==

1. The "Upload from phone" button on a media block.
2. The QR code and link shown in the editor.
3. The upload page as it appears on a phone.

== Changelog ==

= 0.1.0 =

* Initial release.
