<?php

declare(strict_types=1);

namespace LSNepomuceno\Signet\Data;

/**
 * Base for the package's value objects.
 *
 * It implemented the host framework's array-castable marker interface before
 * the split. That interface is not reintroduced here under a different name:
 * it existed so the framework could recognise these objects when serialising a
 * response, and outside a framework nothing consumes it. `toArray()` stays
 * because callers
 * use it directly; the marker does not, because an interface with one
 * implementation and no consumer is a shape rather than an abstraction
 * (docs/spec/conventions.md).
 */
abstract readonly class BaseData
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        /** @var array<string, mixed> */
        return get_object_vars($this);
    }
}
