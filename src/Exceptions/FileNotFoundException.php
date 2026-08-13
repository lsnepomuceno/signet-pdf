<?php

declare(strict_types=1);

/**
 * __toString
 *
 * @return string
 */

namespace LSNepomuceno\Signet\Exceptions;

use Exception;
use Stringable;

class FileNotFoundException extends Exception implements SignetException, Stringable
{
    public function __construct(string $currentFile, int $code = 0, ?Exception $previous = null)
    {
        $message = "File not found. Current file: {$currentFile}.";
        parent::__construct($message, $code, $previous);
    }

    public function __toString(): string
    {
        return __CLASS__ . ": [{$this->getCode()}]: {$this->getMessage()}\n";
    }
}
