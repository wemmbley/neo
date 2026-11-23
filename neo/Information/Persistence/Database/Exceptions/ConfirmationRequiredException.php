<?php

declare(strict_types=1);

namespace Neo\Information\Persistence\Database\Exceptions;

/** Для применения нужно подтверждение, а получить его нельзя (нет терминала и не передан --yes). */
class ConfirmationRequiredException extends DatabaseException {}
