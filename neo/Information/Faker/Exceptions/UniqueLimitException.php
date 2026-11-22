<?php

declare(strict_types=1);

namespace Neo\Information\Faker\Exceptions;

/**
 * Thrown when unique() cannot find a new value within the retry limit.
 */
final class UniqueLimitException extends FakerException {}
