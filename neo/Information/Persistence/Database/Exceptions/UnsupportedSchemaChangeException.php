<?php

declare(strict_types=1);

namespace Neo\Information\Persistence\Database\Exceptions;

/** Изменение, которое декларативный поток не умеет применять безопасно. */
class UnsupportedSchemaChangeException extends DatabaseException {}
