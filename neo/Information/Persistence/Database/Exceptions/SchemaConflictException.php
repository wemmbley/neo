<?php

declare(strict_types=1);

namespace Neo\Information\Persistence\Database\Exceptions;

/** Две декларации спорят за одну таблицу, колонку или индекс, либо нарушено правило владения. */
class SchemaConflictException extends DatabaseException {}
