<?php

declare(strict_types=1);

namespace LSNepomuceno\Signet\Exceptions;

use Exception;

/**
 * The certificate is fine and there is no key to sign with.
 *
 * The two-phase flow exists for keys this process will never hold: on a token,
 * in an HSM, behind a cloud service
 * (docs/decisions/0116-signing-has-two-phases.md). A certificate can therefore
 * legitimately arrive on its own, through `certificatePublic()`, and the
 * builder it produces is for `prepare()`.
 *
 * **The mistake this names is calling the wrong one of those two.** It used to
 * surface as an OpenSSL error string about a key that could not be read, which
 * describes a corrupt key rather than an absent one and sends the reader
 * looking at a file that is not the problem.
 *
 * It comes from `Signing\Cades\CadesBuilder` rather than from the builder,
 * because that is the producer that needs a key. An application that has bound
 * a `Contracts\SignatureProducer` holding the key elsewhere signs from a
 * keyless certificate quite happily, and that is the seam working.
 *
 * **It extends the class it used to arrive as**, so every existing catch keeps
 * matching and this is additive rather than breaking.
 */
final class MissingPrivateKeyException extends InvalidCertificateContentException
{
    public function __construct(int $code = 0, ?Exception $previous = null)
    {
        parent::__construct(
            'this certificate carries no private key, so it can prepare a signature but not produce one. '
            . 'Call prepare() and complete() if the key is held elsewhere, or supply the key through '
            . 'certificate(), certificatePem() or certificateFromPem().',
            $code,
            $previous,
        );
    }
}
