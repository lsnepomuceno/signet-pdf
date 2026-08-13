<?php

declare(strict_types=1);

namespace LSNepomuceno\Signet\Support;

use LSNepomuceno\Signet\Exceptions\FileNotFoundException;
use LSNepomuceno\Signet\Exceptions\ProcessRunTimeException;
use Symfony\Component\Uid\Uuid;
use Throwable;

/**
 * A file that deletes itself.
 *
 * The v1 code this descends from deleted its temporary files with a call
 * placed after the work that might throw, so any failure leaked them,
 * including PEM files holding a private key. Here deletion happens in a
 * finally block, with the destructor as a backstop.
 *
 * Names come from a UUIDv7 rather than a counter or `uniqid()`: it is
 * time-ordered, so a directory of leaked files sorts chronologically, and it
 * carries enough entropy that two concurrent signings cannot collide.
 */
final class TemporaryFile
{
    private bool $deleted = false;

    private function __construct(public readonly string $path) {}

    /**
     * Creates an empty temporary file, optionally seeded with contents.
     *
     * @throws ProcessRunTimeException When the file could not be written.
     */
    public static function create(string $directory, string $extension = '.tmp', string $contents = ''): self
    {
        $directory = rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        Files::makeDirectory($directory);

        $file = new self($directory . Uuid::v7()->toRfc4122() . $extension);

        Files::write($file->path, $contents);

        return $file;
    }

    /**
     * Runs $callback with a temporary file, deleting it however the call ends.
     *
     * @template T
     *
     * @param  callable(self): T  $callback
     * @return T
     *
     * @throws ProcessRunTimeException When the file could not be written.
     * @throws Throwable Whatever the callback raises. Naming only the write
     *          failure here would tell static analysis that nothing else can
     *          come out of this call, and a caller catching its own exception
     *          around it would be reported as dead code.
     */
    public static function with(
        string $directory,
        string $extension,
        string $contents,
        callable $callback,
    ): mixed {
        $file = self::create($directory, $extension, $contents);

        try {
            return $callback($file);
        } finally {
            $file->delete();
        }
    }

    /**
     * @throws FileNotFoundException
     */
    public function contents(): string
    {
        return Files::read($this->path);
    }

    public function exists(): bool
    {
        return Files::exists($this->path);
    }

    public function delete(): void
    {
        if ($this->deleted) {
            return;
        }

        $this->deleted = true;

        Files::delete($this->path);
    }

    public function __destruct()
    {
        $this->delete();
    }
}
