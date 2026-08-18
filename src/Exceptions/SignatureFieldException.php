<?php

declare(strict_types=1);

namespace LSNepomuceno\Signet\Exceptions;

use Exception;
use Stringable;

/**
 * A signature field that cannot be signed into.
 *
 * Each of these is deliberately an error rather than a fallback to appending a
 * new field beside the one asked for. That fallback is exactly the failure
 * intoField() exists to prevent, and it would happen quietly: a document with a
 * valid signature in the wrong place and the template's own field still empty.
 *
 * See docs/decisions/0013-signing-into-an-existing-field.md.
 */
class SignatureFieldException extends Exception implements SignetException, Stringable
{
    /**
     * @param  list<string>  $available  Named so the caller can see the spelling
     *                                   they meant, which is the usual cause.
     */
    public static function missing(string $name, array $available): self
    {
        $names = $available === [] ? 'it carries none' : 'it carries ' . implode(', ', $available);

        return new self("the document has no signature field named \"{$name}\": {$names}");
    }

    public static function alreadySigned(string $name): self
    {
        return new self(
            "the signature field \"{$name}\" is already signed; filling it again would replace that signature rather than add one",
        );
    }

    /**
     * A field carries its own rectangle, so a placement passed alongside it is
     * a contradiction. One would have to win, and resolving it by precedence
     * would silently move the seal off the box the template drew.
     */
    public static function placementConflict(string $name): self
    {
        return new self(
            "a seal placement cannot be given with intoField(\"{$name}\"): the field already has a rectangle, and the seal is drawn into it",
        );
    }

    /**
     * Two fields sharing a name is a form readers disagree about, and the
     * second one silently shadowing the first is the failure this prevents.
     */
    public static function alreadyExists(string $name): self
    {
        return new self(
            "the document already has a signature field named \"{$name}\"; two fields with one name is a form readers disagree about",
        );
    }

    public static function needsName(): self
    {
        return new self('a signature field needs a name: it is how the field is addressed when it is filled');
    }

    /**
     * A seal derives a missing height from its image's aspect ratio. An empty
     * field has no image, so there is nothing to derive it from and a guessed
     * box would be a box nobody chose.
     */
    public static function needsSize(string $name): self
    {
        return new self(
            "the signature field \"{$name}\" was given a placement with no width or no height; pass both, or no placement at all for an invisible field",
        );
    }

    public function __toString(): string
    {
        return __CLASS__ . ": [{$this->getCode()}]: {$this->getMessage()}\n";
    }
}
