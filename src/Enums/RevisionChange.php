<?php

declare(strict_types=1);

namespace LSNepomuceno\Signet\Enums;

/**
 * What a revision appended after a signature touched.
 *
 * Appending to a signed document is legal, and it is how a second signature
 * works: invariant 2 is built on it. It is also how a signed document is made
 * to say something it did not, because the appended bytes lie outside the
 * earlier signature's `/ByteRange` and it still verifies.
 *
 * These name what changed, not whether it was allowed. A seal added by a
 * counter-signer and an annotation laid over the payment terms are the same
 * case here, and telling them apart is the application's policy
 * (docs/decisions/0016-trust-is-the-applications-policy.md,
 * docs/decisions/0110-a-revision-says-what-it-changed.md).
 */
enum RevisionChange: string
{
    /**
     * A signature dictionary was added. The ordinary reason to append.
     */
    case SignatureAdded = 'signature-added';

    /**
     * A document timestamp was added, which is what B-LTA does and what
     * `ArchiveExtender` writes.
     */
    case TimestampAdded = 'timestamp-added';

    /**
     * A Document Security Store was written or replaced, which is B-LT's
     * material rather than a change to the document's content.
     */
    case SecurityStoreWritten = 'security-store-written';

    /**
     * An annotation array was touched.
     *
     * The one to look at hardest. A widget for a new signature is an
     * annotation, and so is a free-text box placed over a number.
     */
    case Annotations = 'annotations';

    /**
     * The interactive form was touched: a field added, removed, or its value
     * changed.
     */
    case FormFields = 'form-fields';

    /**
     * A page object or the page tree was replaced.
     */
    case Pages = 'pages';

    /**
     * The document catalog was replaced for a reason other than the ones
     * above.
     */
    case Catalog = 'catalog';

    /**
     * An action that runs when the document opens, or an additional-action
     * dictionary. Neither is content, and both change what a reader does.
     */
    case Actions = 'actions';

    /**
     * A revision whose objects none of the others describe.
     */
    case Other = 'other';

    /**
     * Whether this is the kind of change a further signature makes by itself.
     *
     * Signing an already-signed document writes a signature dictionary, a
     * widget annotation, the `/AcroForm` holding it, the catalog pointing at
     * the form, and **the page object the widget attaches to**. All five are
     * expected, which is why the two worth looking at hardest are here: their
     * presence is ordinary, and it is their presence *without* a signature that
     * is not.
     *
     * **`Pages` being machinery is a real limit, not an oversight.** A revision
     * that adds a signature and also rewrites a page's content is
     * indistinguishable here from one that adds a signature and attaches its
     * widget, because telling them apart means comparing the page dictionary
     * before and after, and this analysis reads objects rather than the object
     * graph. `Actions` and `Other` stay outside: a further signature has no
     * reason to add an `/OpenAction`
     * (docs/decisions/0110-a-revision-says-what-it-changed.md).
     */
    public function isSigningMachinery(): bool
    {
        return match ($this) {
            self::SignatureAdded, self::TimestampAdded, self::SecurityStoreWritten,
            self::Annotations, self::FormFields, self::Catalog, self::Pages => true,
            self::Actions, self::Other => false,
        };
    }
}
