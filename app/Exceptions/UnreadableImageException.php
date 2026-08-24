<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The upload could not be decoded as an image.
 *
 * A domain exception rather than abort(422): the caller is a form handler, and
 * a bad file should read as a validation error on the file field like every
 * other form mistake, not as a raw error page.
 */
class UnreadableImageException extends RuntimeException {}
