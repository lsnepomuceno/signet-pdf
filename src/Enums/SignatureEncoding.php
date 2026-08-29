<?php

declare(strict_types=1);

namespace LSNepomuceno\Signet\Enums;

/**
 * How a raw signature that came from outside this process is encoded.
 *
 * Only ECDSA has two answers, and they carry the same two integers: `openssl`
 * and every PKCS#11 token that follows the CMS rules emit the DER SEQUENCE of
 * r and s (RFC 3279 §2.2.3), while a good many cloud signing APIs return the
 * fixed-width concatenation of IEEE P1363, which is what a raw ECDSA
 * implementation produces before anything wraps it.
 *
 * An RSA signature has one encoding, so both cases mean the same thing for one.
 *
 * The distinction exists because the two are not distinguishable by looking:
 * a P1363 pair read as DER is a parse error most of the time and a wrong
 * signature the rest of it, which verifies against nothing and says nothing
 * about why (docs/decisions/0120-a-key-can-live-outside-the-process.md).
 */
enum SignatureEncoding: string
{
    /**
     * The DER SEQUENCE of two INTEGERs, which is what `openssl_sign()` returns.
     */
    case Der = 'der';

    /**
     * The fixed-width r ‖ s concatenation of IEEE P1363, common in cloud APIs.
     */
    case P1363 = 'p1363';
}
