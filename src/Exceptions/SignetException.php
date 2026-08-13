<?php

declare(strict_types=1);

namespace LSNepomuceno\Signet\Exceptions;

use Throwable;

/**
 * Every failure this package raises.
 *
 * The classes are granular on purpose, one per failure mode
 * (docs/decisions/0008-exceptions-name-the-real-fault.md), and that left a
 * consumer with no way to catch them as a group: the choices were naming
 * sixteen classes or catching \Exception and swallowing everything the
 * framework throws with them.
 *
 * It matters most where an application registers reporting or rendering by
 * class, which is the usual shape of a framework's error handler:
 *
 * ```php
 * try {
 *     $signet->newSignature()->certificate($pfx, $password)->pdf($path)->sign();
 * } catch (SignetException $e) {
 *     // Everything this package can raise, and nothing else.
 * }
 * ```
 *
 * An interface rather than a base class: several of these may want to extend a
 * framework or SPL type later, and a base class forecloses that. Adding it to
 * existing classes is backward compatible, since every current catch keeps
 * matching.
 */
interface SignetException extends Throwable {}
