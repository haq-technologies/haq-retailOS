<?php

declare(strict_types=1);

namespace App\Platform\Logging;

use Exception;
use Illuminate\Database\QueryException;
use PDOException;
use ReflectionProperty;

/**
 * A failed query as the log may keep it (finding LG1). The connections already leave the values out of the
 * SQL (mask_bindings_in_exception_messages in config/database.php). PostgreSQL's own DETAIL line can still
 * hold them: "Failing row contains (…)" holds a whole row, a PIN's hash included, and "Key (code)=(T01)
 * already exists" a key. So the DETAIL line is left out too. The first line stays, with the SQLSTATE and the
 * constraint's name, and so does the stack trace: the message is rewritten on the same exception.
 */
final class QueryErrors
{
    private const DETAIL = '/\R+DETAIL:[^\r\n]*/';

    private const LEFT_OUT = "\nDETAIL: left out of the log, as it can hold values.";

    public static function forLog(QueryException $e): QueryException
    {
        $previous = $e->getPrevious();
        if (! $previous instanceof PDOException || preg_match(self::DETAIL, $previous->getMessage()) !== 1) {
            return $e;
        }

        // The query's message starts with PostgreSQL's (QueryException::formatMessage): swap that part only,
        // so the connection and the SQL after it stay.
        $original = $previous->getMessage();
        $message = $e->getMessage();
        self::setMessage($e, str_starts_with($message, $original)
            ? self::strip($original).substr($message, strlen($original))
            : self::strip($message));
        self::setMessage($previous, self::strip($original));
        foreach ([$e, $previous] as $error) {
            if (is_array($error->errorInfo) && is_string($error->errorInfo[2] ?? null)) {
                $error->errorInfo[2] = self::strip($error->errorInfo[2]);
            }
        }

        return $e;
    }

    public static function strip(string $message): string
    {
        return (string) preg_replace(self::DETAIL, self::LEFT_OUT, $message);
    }

    private static function setMessage(Exception $e, string $message): void
    {
        (new ReflectionProperty(Exception::class, 'message'))->setValue($e, $message);
    }
}
