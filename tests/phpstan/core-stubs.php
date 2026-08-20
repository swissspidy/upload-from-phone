<?php
/**
 * PHPStan stubs narrowing WordPress core PHPDoc.
 *
 * Core documents a handful of things this plugin builds on as a bare `array`
 * or as `string[]`, where the shape is in fact known — a JSON Schema object is
 * keyed by keyword, and core's own description of get_allowed_mime_types()
 * says what that one is keyed by. Only the types are read from here; the
 * declarations themselves still come from the WordPress stubs package, which
 * is why this is a `stubFiles` entry and tests/phpstan/stubs.php is not:
 * a stub file overrides the PHPDoc of a symbol that already exists, and
 * declares nothing that does not.
 *
 * Parameters are typed loosely on purpose. A stub file is read without the
 * WordPress stubs alongside it, so naming a class such as WP_REST_Response or
 * WP_User here would only resolve to an unknown one — and it is the return
 * types that these stubs exist for.
 *
 * @package UploadFromPhone
 */

declare(strict_types = 1);

abstract class WP_REST_Controller {
	/**
	 * Cached results of get_item_schema.
	 *
	 * A JSON Schema object: keyed by keyword, as what is handed to
	 * add_additional_fields_schema() and returned from get_item_schema() has
	 * to be for the REST server to read it at all.
	 *
	 * @var array<string, mixed>
	 */
	protected $schema;

	/**
	 * Adds the schema from additional fields to a schema array.
	 *
	 * Additional fields are registered under their own names, so what comes
	 * back is keyed by string exactly as what went in was.
	 *
	 * @param array<string, mixed> $schema Schema array.
	 * @return array<string, mixed> Modified schema array.
	 */
	protected function add_additional_fields_schema( $schema ) {
	}

	/**
	 * Prepares a response for insertion into a collection.
	 *
	 * The response data with its links folded in, so still keyed by the field
	 * names the controller that prepared it used.
	 *
	 * @param object $response Response object.
	 * @return array<string, mixed> Response data, ready for insertion into collection data.
	 */
	public function prepare_response_for_collection( $response ) {
	}
}

/**
 * Retrieves the list of allowed mime types and file extensions.
 *
 * Keyed by the file extension regex the mime type belongs to, which is what
 * core's own description says and what `string[]` has no way to express.
 *
 * @param int|object $user Optional. User to check. Defaults to current user.
 * @return array<string, string> Array of mime types keyed by the file extension regex
 *                               corresponding to those types.
 */
function get_allowed_mime_types( $user = null ) {
}
