<?php

/**
 * Raised when Cloudflare Access returns a login page to the Weblate API client.
 *
 * @package PublishPress\Translations
 */

namespace PublishPress\Translations;

use RuntimeException;

class CloudflareAccessException extends RuntimeException
{
}
