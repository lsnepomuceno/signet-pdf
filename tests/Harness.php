<?php

declare(strict_types=1);

namespace LSNepomuceno\Signet\Tests;

use LSNepomuceno\Signet\Certificates\CertificateParser;
use LSNepomuceno\Signet\Certificates\ReaderFactory;
use LSNepomuceno\Signet\Config\CertificateConfig;
use LSNepomuceno\Signet\Config\LtvConfig;
use LSNepomuceno\Signet\Config\SealConfig;
use LSNepomuceno\Signet\Config\SignetConfig;
use LSNepomuceno\Signet\Config\SigningConfig;
use LSNepomuceno\Signet\Config\TimestampConfig;
use LSNepomuceno\Signet\Contracts\CertificateReader;
use LSNepomuceno\Signet\Contracts\PdfSigner;
use LSNepomuceno\Signet\Contracts\ProcessRunner;
use LSNepomuceno\Signet\Contracts\SealRenderer;
use LSNepomuceno\Signet\Contracts\SignatureTransport;
use LSNepomuceno\Signet\Contracts\SignatureValidator;
use LSNepomuceno\Signet\Contracts\SignatureVerifier;
use LSNepomuceno\Signet\Enums\DigestAlgorithm;
use LSNepomuceno\Signet\Enums\FontSize;
use LSNepomuceno\Signet\Enums\ImageDriver;
use LSNepomuceno\Signet\Enums\SignatureProfile;
use LSNepomuceno\Signet\Seal\InterventionSealRenderer;
use LSNepomuceno\Signet\Signing\Cades\HttpTransport;
use LSNepomuceno\Signet\Signing\IncrementalSigner;
use LSNepomuceno\Signet\Support\SymfonyProcessRunner;
use LSNepomuceno\Signet\Support\TempDirectory;
use LSNepomuceno\Signet\Validation\OpenSslCliSignatureVerifier;
use LSNepomuceno\Signet\Validation\PdfSignatureValidator;
use LSNepomuceno\Signet\Validation\TrustVerifier;
use ReflectionClass;
use ReflectionNamedType;
use RuntimeException;

/**
 * The container the suite no longer has.
 *
 * The Laravel package resolved every collaborator through the framework's
 * container, and `tests/TestCase.php` booted Testbench to provide one. Neither
 * exists here, and the suite still needs three things a container was giving
 * it: autowiring, so a test can ask for a nine-object graph in one line;
 * rebinding, so `SignatureTransport` can become a local authority; and mutable
 * configuration, so a test can set a TSA URL for one case.
 *
 * This provides those three and nothing else. It is deliberately in `tests/`
 * and not in `src/`: shipping it would put a service locator back in a package
 * whose first boundary rule is that it does not have one
 * (docs/decisions/0100-the-core-is-framework-agnostic.md). `Signet` is the
 * production entry point, and it wires the same graph by hand.
 *
 * **The bindings below mirror the deleted service provider one for one.** When
 * a behaviour differs between this suite and the Laravel package, that map is
 * the first place to look.
 */
final class Harness
{
    private static ?self $instance = null;

    private SignetConfig $config;

    /** @var array<string, callable(self): object> */
    private array $bindings = [];

    /** @var array<string, object> */
    private array $resolved = [];

    private function __construct()
    {
        $this->config = new SignetConfig();
        $this->bindings = $this->defaults();
    }

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    /**
     * Drops every resolved instance, binding and configuration override.
     *
     * Called before each test. Without it a `bind()` in one test leaks into
     * every test that runs after it in the same process, which under
     * `--parallel` makes failures depend on scheduling.
     */
    public static function reset(): void
    {
        self::$instance = null;
    }

    public function config(): SignetConfig
    {
        return $this->config;
    }

    /**
     * Replaces one configuration value, addressed the way the Laravel config
     * file addressed it.
     *
     * The dot paths are kept because the ported tests use them and because the
     * Laravel package still publishes a file with exactly these keys, so a
     * behaviour described in one repository is searchable in the other.
     * Rebuilding the value objects rather than mutating them is not a
     * preference: they are `final readonly`.
     */
    public function setConfig(string $key, mixed $value): void
    {
        $signing = $this->config->signing;
        $timestamp = $signing->timestamp;
        $ltv = $signing->ltv;
        $seal = $this->config->seal;
        $certificate = $this->config->certificate;

        $this->config = match ($key) {
            'signature.profile' => $this->withSigning(new SigningConfig(
                SignatureProfile::resolve(is_string($value) || $value instanceof SignatureProfile ? $value : null),
                $signing->digest,
                $timestamp,
                $ltv,
            )),
            'signature.digest_algorithm' => $this->withSigning(new SigningConfig(
                $signing->profile,
                DigestAlgorithm::from(self::asString($value) ?? 'sha256'),
                $timestamp,
                $ltv,
            )),
            'signature.timestamp.url' => $this->withTimestamp(new TimestampConfig(
                self::asString($value),
                $timestamp->username,
                $timestamp->password,
                $timestamp->timeout,
                $timestamp->attempts,
                $timestamp->backoff,
            )),
            'signature.timestamp.username' => $this->withTimestamp(new TimestampConfig(
                $timestamp->url,
                self::asString($value),
                $timestamp->password,
                $timestamp->timeout,
                $timestamp->attempts,
                $timestamp->backoff,
            )),
            'signature.timestamp.password' => $this->withTimestamp(new TimestampConfig(
                $timestamp->url,
                $timestamp->username,
                self::asString($value),
                $timestamp->timeout,
                $timestamp->attempts,
                $timestamp->backoff,
            )),
            'signature.timestamp.timeout' => $this->withTimestamp(new TimestampConfig(
                $timestamp->url,
                $timestamp->username,
                $timestamp->password,
                self::asInt($value, $timestamp->timeout),
                $timestamp->attempts,
                $timestamp->backoff,
            )),
            'signature.timestamp.attempts' => $this->withTimestamp(new TimestampConfig(
                $timestamp->url,
                $timestamp->username,
                $timestamp->password,
                $timestamp->timeout,
                self::asInt($value, $timestamp->attempts),
                $timestamp->backoff,
            )),
            'signature.timestamp.backoff' => $this->withTimestamp(new TimestampConfig(
                $timestamp->url,
                $timestamp->username,
                $timestamp->password,
                $timestamp->timeout,
                $timestamp->attempts,
                self::asInt($value, $timestamp->backoff),
            )),
            'signature.ltv.timeout' => $this->withLtv(new LtvConfig(
                self::asInt($value, $ltv->timeout),
                $ltv->attempts,
                $ltv->backoff,
            )),
            'signature.ltv.attempts' => $this->withLtv(new LtvConfig(
                $ltv->timeout,
                self::asInt($value, $ltv->attempts),
                $ltv->backoff,
            )),
            'signature.ltv.backoff' => $this->withLtv(new LtvConfig(
                $ltv->timeout,
                $ltv->attempts,
                self::asInt($value, $ltv->backoff),
            )),
            'seal.transparent' => $this->withSeal(new SealConfig(
                $seal->driver,
                $seal->fontPath,
                $seal->fontSize,
                $seal->fontColor,
                $seal->background,
                (bool) $value,
                $seal->textX,
                $seal->textRows,
            )),
            'seal.driver' => $this->withSeal(new SealConfig(
                ImageDriver::from(self::asString($value) ?? 'gd'),
                $seal->fontPath,
                $seal->fontSize,
                $seal->fontColor,
                $seal->background,
                $seal->transparent,
                $seal->textX,
                $seal->textRows,
            )),
            'seal.font.path' => $this->withSeal(new SealConfig(
                $seal->driver,
                self::asString($value),
                $seal->fontSize,
                $seal->fontColor,
                $seal->background,
                $seal->transparent,
                $seal->textX,
                $seal->textRows,
            )),
            'seal.font.size' => $this->withSeal(new SealConfig(
                $seal->driver,
                $seal->fontPath,
                FontSize::resolve(self::asString($value) ?? 'large'),
                $seal->fontColor,
                $seal->background,
                $seal->transparent,
                $seal->textX,
                $seal->textRows,
            )),
            'seal.font.color' => $this->withSeal(new SealConfig(
                $seal->driver,
                $seal->fontPath,
                $seal->fontSize,
                self::asString($value) ?? '#16A085',
                $seal->background,
                $seal->transparent,
                $seal->textX,
                $seal->textRows,
            )),
            'seal.background' => $this->withSeal(new SealConfig(
                $seal->driver,
                $seal->fontPath,
                $seal->fontSize,
                $seal->fontColor,
                self::asString($value),
                $seal->transparent,
                $seal->textX,
                $seal->textRows,
            )),
            'certificate.legacy' => new SignetConfig(
                $signing,
                new CertificateConfig((bool) $value, $certificate->usePathEnv),
                $seal,
                $this->config->tempPath,
            ),
            'certificate.use_path_env' => new SignetConfig(
                $signing,
                new CertificateConfig($certificate->legacy, (bool) $value),
                $seal,
                $this->config->tempPath,
            ),
            'temp_path' => new SignetConfig($signing, $certificate, $seal, self::asString($value)),
            default => throw new RuntimeException("Unknown configuration key: {$key}"),
        };

        // Anything already built captured the old values, so it has to go.
        //
        // The bindings do not. Every default closure reads `$h->config` when
        // it is called rather than closing over a value, so they already see
        // the new configuration. Rebuilding the map here is not merely
        // redundant, it is wrong: it discarded any binding whose key also
        // appears in the defaults, and `SignatureTransport` is one of those.
        // A test that bound a local authority and then set a TSA URL, which is
        // the order every offline timestamp test uses, silently got the real
        // HTTP transport back and failed on DNS.
        $this->resolved = [];
    }

    /**
     * @param  class-string  $abstract
     * @param  class-string|(callable(self): object)|object  $concrete
     */
    public function bind(string $abstract, string|callable|object $concrete): void
    {
        unset($this->resolved[$abstract]);

        if (is_string($concrete)) {
            /** @var class-string $concrete */
            $this->bindings[$abstract] = static fn(self $harness): object => $harness->make($concrete);

            return;
        }

        if (is_callable($concrete)) {
            $callable = $concrete(...);

            $this->bindings[$abstract] = static function (self $harness) use ($callable): object {
                $made = $callable($harness);

                if (! is_object($made)) {
                    throw new RuntimeException('A binding must produce an object.');
                }

                return $made;
            };

            return;
        }

        $this->bindings[$abstract] = static fn(): object => $concrete;
    }

    /**
     * @template T of object
     *
     * @param  class-string<T>  $class
     * @return T
     */
    public function make(string $class): object
    {
        if (isset($this->resolved[$class])) {
            /** @var T */
            return $this->resolved[$class];
        }

        $instance = isset($this->bindings[$class])
            ? ($this->bindings[$class])($this)
            : $this->autowire($class);

        if (! $instance instanceof $class) {
            throw new RuntimeException("Binding for {$class} produced " . $instance::class);
        }

        $this->resolved[$class] = $instance;

        /** @var T */
        return $instance;
    }

    /**
     * @param  class-string  $class
     */
    private function autowire(string $class): object
    {
        $reflection = new ReflectionClass($class);

        if (! $reflection->isInstantiable()) {
            throw new RuntimeException("Cannot autowire {$class}: it is not instantiable and nothing binds it.");
        }

        $constructor = $reflection->getConstructor();

        if ($constructor === null) {
            return $reflection->newInstance();
        }

        $arguments = [];

        foreach ($constructor->getParameters() as $parameter) {
            $type = $parameter->getType();
            $name = $type instanceof ReflectionNamedType && ! $type->isBuiltin() ? $type->getName() : null;

            // An explicit binding always wins, including over a default. That
            // is what makes `?TrustVerifier $trust = null` resolve rather than
            // stay null, which the service provider achieved by binding the
            // class to itself for exactly this reason.
            if ($name !== null && isset($this->bindings[$name])) {
                /** @var class-string $name */
                $arguments[] = $this->make($name);

                continue;
            }

            // A default is the author's own answer, so it is preferred over
            // anything reflection could invent. This is what keeps
            // `Signer $signer = new Signer()` out of the resolver's way.
            if ($parameter->isDefaultValueAvailable()) {
                $arguments[] = $parameter->getDefaultValue();

                continue;
            }

            if ($name !== null && class_exists($name)) {
                /** @var class-string $name */
                $arguments[] = $this->make($name);

                continue;
            }

            throw new RuntimeException("Cannot autowire \${$parameter->getName()} of {$class}.");
        }

        return $reflection->newInstanceArgs($arguments);
    }

    /**
     * The service provider's register(), transcribed.
     *
     * @return array<string, callable(self): object>
     */
    private function defaults(): array
    {
        return [
            SignetConfig::class => static fn(self $h): object => $h->config,
            SigningConfig::class => static fn(self $h): object => $h->config->signing,
            CertificateConfig::class => static fn(self $h): object => $h->config->certificate,
            SealConfig::class => static fn(self $h): object => $h->config->seal,
            TimestampConfig::class => static fn(self $h): object => $h->config->signing->timestamp,
            LtvConfig::class => static fn(self $h): object => $h->config->signing->ltv,

            TempDirectory::class => static fn(self $h): object => new TempDirectory($h->config->tempPath),

            ProcessRunner::class => static fn(): object => new SymfonyProcessRunner(),

            // The incremental signer is the default: it preserves the original
            // bytes and lets a document carry more than one signature.
            PdfSigner::class => static fn(self $h): object => $h->make(IncrementalSigner::class),
            SealRenderer::class => static fn(self $h): object => $h->make(InterventionSealRenderer::class),
            SignatureValidator::class => static fn(self $h): object => $h->make(PdfSignatureValidator::class),

            // The default is the one that shells out, which is what the
            // package ships and what every test that does not say otherwise
            // should be measuring. `NativeSignatureVerifier` is bound in the
            // differential test, and only there
            // (docs/decisions/0114-verification-has-two-implementations.md).
            SignatureVerifier::class => static fn(self $h): object => $h->make(OpenSslCliSignatureVerifier::class),

            // The seam invariant 9 is built on: everything the profiles above
            // pades-b-b add rides through here, and a test that can replace it
            // turns them from reported into gated
            // (docs/decisions/0027-the-transport-is-a-seam.md).
            SignatureTransport::class => static fn(self $h): object => $h->make(HttpTransport::class),

            // Bound to itself on purpose, and it must stay bound.
            // PdfSignatureValidator takes it as an optional parameter so its
            // arity does not move, and a defaulted class-typed parameter is
            // only filled when something binds the class: without this line the
            // validator silently gets null and every signature reports trust as
            // unknown.
            TrustVerifier::class => static fn(self $h): object => new TrustVerifier($h->make(TempDirectory::class)),

            CertificateReader::class => static fn(self $h): object => new ReaderFactory(
                $h->make(CertificateParser::class),
                $h->make(ProcessRunner::class),
                $h->config->certificate,
                $h->make(TempDirectory::class),
            )->make(),
        ];
    }

    private function withSigning(SigningConfig $signing): SignetConfig
    {
        return new SignetConfig($signing, $this->config->certificate, $this->config->seal, $this->config->tempPath);
    }

    private function withTimestamp(TimestampConfig $timestamp): SignetConfig
    {
        $signing = $this->config->signing;

        return $this->withSigning(new SigningConfig($signing->profile, $signing->digest, $timestamp, $signing->ltv));
    }

    private function withLtv(LtvConfig $ltv): SignetConfig
    {
        $signing = $this->config->signing;

        return $this->withSigning(new SigningConfig($signing->profile, $signing->digest, $signing->timestamp, $ltv));
    }

    private function withSeal(SealConfig $seal): SignetConfig
    {
        return new SignetConfig($this->config->signing, $this->config->certificate, $seal, $this->config->tempPath);
    }

    private static function asString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function asInt(mixed $value, int $default): int
    {
        return is_numeric($value) ? (int) $value : $default;
    }
}
