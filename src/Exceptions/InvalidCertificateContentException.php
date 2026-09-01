<?php

declare(strict_types=1);

namespace LSNepomuceno\Signet\Exceptions;

use Exception;
use Stringable;

class InvalidCertificateContentException extends Exception implements SignetException, Stringable
{
    /**
     * @param  string  $reason  Detail from the reader, e.g. the OpenSSL error
     *                          that explains why the bundle could not be read.
     */
    public function __construct(string $reason = '', int $code = 0, ?Exception $previous = null)
    {
        $message = 'Invalid file content, accept only valid OpenSSLCertificate.';

        if ($reason !== '') {
            $message .= " {$reason}";
        }

        parent::__construct($message, $code, $previous);
    }

    /**
     * The bundle uses algorithms OpenSSL 3.x refuses by default.
     *
     * ext-openssl exposes no equivalent of the CLI's `-legacy` flag, so the
     * remedy is configuration rather than code, and the message says which
     * (docs/decisions/0001-openssl-native-with-cli-fallback.md). It is named
     * here rather than passed through as the raw OpenSSL string, because this
     * package knows exactly what that string means and ships the fix.
     *
     * @param  string  $error  What OpenSSL said, kept so a reader who knows
     *                         the code still sees it.
     */
    public static function legacyAlgorithms(string $error): self
    {
        return new self(
            'The PKCS#12 bundle uses algorithms OpenSSL 3.x refuses by default, '
            . 'and ext-openssl exposes no equivalent of the -legacy flag. Read it '
            . 'with the CLI reader, through new CertificateConfig(legacy: true) or '
            . "the --legacy option of the signet command. OpenSSL said: {$error}",
        );
    }

    public function __toString(): string
    {
        return __CLASS__ . ": [{$this->getCode()}]: {$this->getMessage()}\n";
    }
}
