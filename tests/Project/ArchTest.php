<?php

declare(strict_types=1);

/**
 * Architectural rules, executable.
 *
 * These turn docs/spec/invariants.md from a document into a gate: the rules it
 * describes are checked on every run, so the architecture cannot erode silently
 * after a merge.
 */
arch('no debug leftovers ship')
    ->expect(['dd', 'dump', 'var_dump', 'print_r', 'die', 'exit', 'ray'])
    ->not->toBeUsed();

/**
 * Two classes are exempt, each for one reason, and both exemptions are by class
 * rather than by loosening the rule: a third use anywhere else still fails.
 *
 * **`Data\SignatureDetails`, for sha1 only.** The Document Security Store keys
 * /VRI entries by the SHA-1 of a signature's /Contents, which the PDF
 * specification fixes. The value is an identifier defined by a format this
 * package reads, not a digest this package chose for security, and computing it
 * with anything else would simply fail to match.
 *
 * **`Signing\Encryption\StandardSecurityHandler`, for md5.** ISO 32000-1
 * §7.6.4.3 specifies MD5 as the key derivation for encryption revisions 2 to 4,
 * so a document written under one of those opens with MD5 or does not open. The
 * same class implements RC4 for the same kind of reason, to recompute a check
 * value the document already carries, and refuses RC4-encrypted *content*
 * outright (docs/decisions/0030-signing-a-document-that-is-encrypted.md).
 *
 * Using either to protect something new would be a defect. Using them to read
 * what a standard already wrote is the only way to read it at all.
 */
arch('no weak hashing or insecure randomness')
    ->expect(['md5', 'sha1', 'rand', 'srand', 'mt_rand'])
    ->not->toBeUsed()
    ->ignoring([
        'LSNepomuceno\Signet\Data\SignatureDetails',
        'LSNepomuceno\Signet\Signing\Encryption\StandardSecurityHandler',
    ]);

arch('no eval or dynamic code execution')
    ->expect(['eval', 'create_function'])
    ->not->toBeUsed();

/**
 * ddn/sapp is LGPL and this package is MIT. It is a conceptual reference for
 * the incremental writer only; a single import would make the package a
 * derivative work. See docs/spec/invariants.md.
 */
arch('no trace of SAPP')
    ->expect('ddn\Sapp')
    ->not->toBeUsed();

/**
 * Every exception extends Exception.
 *
 * Written as a walk rather than as an arch expectation, because the namespace
 * now holds an interface as well, `SignetException`, and an arch rule cannot
 * be told to skip it: `ignoring()` still reports it for not extending
 * `Exception`, which an interface never does.
 *
 * **The Stringable half of the old rule is gone because it could never fail.**
 * `Throwable` has extended `Stringable` since PHP 8, so every exception
 * satisfies it whether or not it declares `__toString()`, and three of these
 * classes do not declare one while the rule passed. PHPStan said so outright:
 * "will always evaluate to true".
 */
it('keeps every exception throwable', function () {
    $wrong = [];

    $files = glob(dirname(__DIR__, 2) . '/src/Exceptions/*.php');

    foreach ($files === false ? [] : $files as $file) {
        $name = 'LSNepomuceno\\Signet\\Exceptions\\' . basename($file, '.php');

        if (interface_exists($name)) {
            continue;
        }

        if (! is_subclass_of($name, Exception::class)) {
            $wrong[] = $name;
        }
    }

    expect($wrong)->toBe([]);
});

arch('value objects are immutable')
    ->expect('LSNepomuceno\Signet\Data')
    ->toBeReadonly();

// BaseData is abstract, so it is exempt from both rules below.
arch('value objects are closed for extension')
    ->expect('LSNepomuceno\Signet\Data')
    ->toBeFinal()
    ->ignoring('LSNepomuceno\Signet\Data\BaseData');

arch('value objects stay on the shared base')
    ->expect('LSNepomuceno\Signet\Data')
    ->toExtend('LSNepomuceno\Signet\Data\BaseData')
    ->ignoring('LSNepomuceno\Signet\Data\BaseData');

/**
 * The reason is the one in the name, so it reaches only the enums a
 * configuration file can name. An enum whose values are fixed by a standard and
 * are natural integers, like an ASN.1 tag, is exempted here rather than by
 * weakening the rule for every enum
 * (docs/decisions/0018-prefer-the-platforms-own-constructs.md).
 *
 * `ExtendExitCode` is exempt for the same reason. A process exit status is an
 * integer the operating system defines, nobody configures one, and its
 * retryable case carries `EX_TEMPFAIL` from sysexits.h rather than a number
 * this package chose.
 */
arch('enums are string-backed, so configuration can express them as plain strings')
    ->expect('LSNepomuceno\Signet\Enums')
    ->toBeStringBackedEnums()
    ->ignoring([
        'LSNepomuceno\Signet\Enums\Asn1Tag',
        'LSNepomuceno\Signet\Enums\ExtendExitCode',
    ]);

/**
 * The regional layer keeps every guarantee the shared namespaces have.
 *
 * `IcpBrasil\` is bounded rather than special (0104). Its value objects and
 * enums are the same kind of thing as the ones in `Data\` and `Enums\`, so the
 * rules are repeated against its sub-namespaces rather than the classes being
 * exempted for living somewhere else. Sub-namespaces rather than a flat
 * `IcpBrasil\` exist for exactly this: a rule pointed at a namespace covers
 * whatever is added to it later, and a rule listing class names does not.
 */
arch('the regional value objects are immutable')
    ->expect('LSNepomuceno\Signet\IcpBrasil\Data')
    ->toBeReadonly();

arch('the regional value objects are closed for extension')
    ->expect('LSNepomuceno\Signet\IcpBrasil\Data')
    ->toBeFinal();

arch('the regional value objects stay on the shared base')
    ->expect('LSNepomuceno\Signet\IcpBrasil\Data')
    ->toExtend('LSNepomuceno\Signet\Data\BaseData');

arch('the regional enums are string-backed too')
    ->expect('LSNepomuceno\Signet\IcpBrasil\Enums')
    ->toBeStringBackedEnums();

/**
 * The package does not know what Laravel is.
 *
 * This is the gate the whole separation rests on, and it is worth more than
 * every other rule in this file put together: the reason `src/` can be
 * consumed from Symfony, Slim or a bare script is that nothing in it imports
 * a framework, and the only way that stays true is by failing a build when it
 * stops being true (docs/decisions/0100-the-core-is-framework-agnostic.md).
 *
 * A walk rather than an arch expectation, because an arch rule can only be
 * pointed at symbols that exist, and the entire point here is that these do
 * not: the framework's filesystem facade is not installed, so a rule naming it
 * matches nothing and passes for the wrong reason. The token walk sees the
 * import whether or not the class is autoloadable.
 *
 * **The prose is covered too, and it did not used to be.** Docblocks were
 * exempt while a dozen classes explained themselves by naming the construct
 * they replaced. They now explain the same thing without it, because a reader
 * who has never seen that framework should not have to know it to understand
 * why `Contracts\ProcessRunner` is an interface. The exemption went with the
 * last mention, and this rule is what stops the next one arriving.
 *
 * The one string allowed through is `lsnepomuceno/laravel-a1-pdf-sign`, which
 * is a package name rather than a framework construct. It has to be nameable:
 * `Support\OpensslEncrypter` reproduces that package's envelope byte for byte
 * on purpose, and a docblock that cannot say whose format it is documents
 * nothing (0101).
 */
it('imports no framework', function () {
    $forbidden = ['Illuminate\\', 'Orchestra\\', 'Laravel\\'];
    $inProse = '/\b(laravel|illuminate|orchestra|artisan|eloquent)\b/i';
    $siblingPackage = 'lsnepomuceno/laravel-a1-pdf-sign';
    $found = [];

    foreach (phpFilesUnder(dirname(__DIR__, 2) . '/src') as $path => $contents) {
        foreach (token_get_all($contents) as $token) {
            if (! is_array($token)) {
                continue;
            }

            if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                // Word boundaries, so the ICC specification's "illuminated by
                // D50" in `Support\SrgbProfile` is not a framework reference.
                $prose = str_replace($siblingPackage, '', $token[1]);

                if (preg_match_all($inProse, $prose, $matches) > 0) {
                    foreach ($matches[0] as $match) {
                        $found[] = "{$path}: {$match} (in a comment)";
                    }
                }

                continue;
            }

            if ($token[0] !== T_NAME_QUALIFIED) {
                continue;
            }

            foreach ($forbidden as $vendor) {
                if (str_starts_with($token[1], rtrim($vendor, '\\') . '\\')) {
                    $found[] = "{$path}: {$token[1]}";
                }
            }
        }
    }

    expect($found)->toBe([]);
});

/**
 * The byte-exact namespaces do not reach for multibyte string helpers.
 *
 * `mb_substr()` and `mb_strlen()` interpret their input as text, so running
 * them over a PDF or a DER blob reinterprets binary as UTF-8 and returns the
 * wrong offsets. In this package a wrong offset is a corrupted signature, and
 * the failure passes the whole suite: every fixture is ASCII, and it takes a
 * multi-byte sequence in the payload to show.
 *
 * The rule used to name `Illuminate\Support\Str`, whose `substr()` and
 * `length()` are multibyte for the same reason. The framework is gone and the
 * hazard is not, so the rule now names the functions underneath it.
 *
 * `mb_convert_encoding()` is exempt: `Signing\Encryption\ObjectCipher` uses it
 * to write a text string as UTF-16BE, which is a conversion of text that is
 * genuinely text, and is the opposite of the mistake being guarded against.
 */
it('keeps multibyte helpers out of the byte-exact namespaces', function () {
    $forbidden = ['mb_substr', 'mb_strlen', 'mb_strpos', 'mb_str_split', 'mb_strtolower', 'mb_strtoupper'];
    $found = [];

    foreach (['Signing', 'Validation'] as $namespace) {
        foreach (phpFilesUnder(dirname(__DIR__, 2) . '/src/' . $namespace) as $path => $contents) {
            foreach (token_get_all($contents) as $token) {
                if (is_array($token) && $token[0] === T_STRING && in_array($token[1], $forbidden, true)) {
                    $found[] = "{$path}: {$token[1]}";
                }
            }
        }
    }

    expect($found)->toBe([]);
});

arch('contracts are interfaces')
    ->expect('LSNepomuceno\Signet\Contracts')
    ->toBeInterfaces();

/**
 * Everything that opens an external process has to go through the single
 * audited implementation (docs/spec/invariants.md, rule 8).
 *
 * The target moved with the split. Under Laravel the one place was
 * `Support\ProcessRunner`, a concrete class built on the framework's process
 * factory so a host could fake it. Here `Contracts\ProcessRunner` is the seam
 * and `Support\SymfonyProcessRunner` is the only thing behind it that actually
 * starts anything.
 */
arch('only the shell helper opens processes')
    ->expect(['Symfony\Component\Process', 'exec', 'shell_exec', 'proc_open', 'passthru', 'system', 'popen'])
    ->toOnlyBeUsedIn('LSNepomuceno\Signet\Support\SymfonyProcessRunner');

/**
 * Constants PHP defines only where the host platform provides them.
 *
 * GLOB_BRACE is a GNU extension and is undefined on musl, so a call carrying it
 * is a fatal error on php:8.4-alpine while passing everywhere CI runs. That is
 * the shape of the failure worth guarding: the suite was green on Ubuntu for a
 * whole release while `TrustStore::fromDirectory()` could not be called at all
 * in one of the images this package most often ships in.
 *
 * A Pest arch rule cannot see constants, so this reads the sources.
 */
it('uses no constant the host platform may not define', function () {
    $optional = ['GLOB_BRACE'];
    $found = [];

    /** @var SplFileInfo $file */
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 2) . '/src')) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        // Tokenised rather than grepped, so the comment above the fix can name
        // the constant it is warning about without tripping the gate.
        foreach (token_get_all((string) file_get_contents($file->getPathname())) as $token) {
            if (is_array($token) && $token[0] === T_STRING && in_array($token[1], $optional, true)) {
                $found[] = str_replace(dirname(__DIR__, 2) . '/', '', $file->getPathname()) . ": {$token[1]}";
            }
        }
    }

    expect($found)->toBe([]);
});

/**
 * veraPDF is a measuring instrument, not a dependency.
 *
 * So are qpdf, poppler, Ghostscript and pyHanko. Every one of them is installed for
 * development and CI, to establish verdicts the suite cannot establish for
 * itself, and **none of them may reach production**
 * (docs/decisions/0025-what-signing-does-to-pdf-a.md).
 *
 * **Nothing in src/ may reach for it.** A package that shells out to a JVM to
 * answer a question at runtime would be a different package, and the consuming
 * application would inherit an installation requirement nobody wrote down. The
 * same applies to poppler's pdfsig, which has verified this package's output
 * since 2.0 and has never been called by it.
 */
it('keeps the verification tools out of the package', function () {
    $tools = ['verapdf', 'veraPDF', 'pdfsig', 'pdftoppm', 'qpdf', 'ghostscript', 'pyhanko'];
    $found = [];

    /** @var SplFileInfo $file */
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 2) . '/src')) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        // Only string literals. Naming a tool in a docblock is expected and
        // happens: what must not exist is a code path that invokes one, and
        // matching the raw text would flag the comment explaining that.
        foreach (token_get_all((string) file_get_contents($file->getPathname())) as $token) {
            if (! is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }

            foreach ($tools as $tool) {
                if (stripos($token[1], $tool) !== false) {
                    $found[] = str_replace(dirname(__DIR__, 2) . '/', '', $file->getPathname()) . ": {$tool}";
                }
            }
        }
    }

    expect($found)->toBe([]);
});

/*
|--------------------------------------------------------------------------
| Docblocks
|--------------------------------------------------------------------------
|
| Checked mechanically, because prose rots quietly. Both rules below describe
| defects that actually shipped rather than defects worth worrying about, and
| neither is a style preference: a docblock that documents nothing is a comment
| nobody reads, and a docblock that documents the wrong thing is worse than
| none, because it is believed (docs/spec/conventions.md).
|
| The first of them caught this very file, five minutes after it was written.
|
*/

/**
 * Every PHP file under a directory, as relative path to contents.
 *
 * @return Generator<string, string>
 */
function phpFilesUnder(string $directory): Generator
{
    /** @var SplFileInfo $file */
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory)) as $file) {
        if ($file->getExtension() === 'php') {
            yield str_replace(dirname(__DIR__, 2) . '/', '', $file->getPathname()) => (string) file_get_contents($file->getPathname());
        }
    }
}

it('declares no test helper twice, whatever the two do', function () {
    // A fatal, not a subtlety: two files declaring one function name kill the
    // run the moment both load, with `Cannot redeclare`. It cost a red CI, and
    // it is invisible to running one file at a time, which is how a new helper
    // is usually tried out.
    //
    // The rule in CLAUDE.md sends a **shared** helper to tests/Pest.php, since
    // a file-local one is invisible to the others under `--parallel`. This is
    // the other half of the same rule: a helper that stays local still has to
    // own its name across the suite.
    $declared = [];

    foreach (phpFilesUnder(dirname(__DIR__)) as $path => $contents) {
        if (preg_match_all('/^function ([a-zA-Z_][a-zA-Z0-9_]*)\s*\(/m', $contents, $found) === 0) {
            continue;
        }

        foreach ($found[1] as $name) {
            $declared[$name][] = $path;
        }
    }

    $collisions = array_filter($declared, static fn(array $files): bool => count($files) > 1);

    expect($collisions)->toBe([]);
});

it('never leaves a docblock documenting another docblock', function (string $directory) {
    // What this catches: inserting a method between a docblock and the method
    // it described, which leaves the first one attached to the newcomer and the
    // original method undocumented. Both blocks look fine in isolation, the
    // diff looks like an addition, and every tool that reads docblocks now
    // reports the wrong thing about two methods.
    //
    // Found four times across src/ and tests/ on the day this was written, one
    // of them describing `latest()` while sitting above `timestamps()`.
    $found = [];

    foreach (phpFilesUnder(dirname(__DIR__, 2) . '/' . $directory) as $path => $contents) {
        $lines = explode("\n", $contents);

        foreach ($lines as $number => $line) {
            if (trim($line) === '*/' && str_starts_with(trim($lines[$number + 1] ?? ''), '/**')) {
                $found[] = "{$path}:" . ($number + 2);
            }
        }
    }

    expect($found)->toBe([]);
})->with(['src', 'tests']);

it('documents parameters that exist', function () {
    // A @param surviving a rename or a removal is the other half of the same
    // problem: the signature moved and the prose did not.
    $found = [];

    foreach (phpFilesUnder(dirname(__DIR__, 2) . '/src') as $path => $contents) {
        preg_match_all(
            '#/\*\*(.*?)\*/\s*(?:\#\[[^\]]*\]\s*)*(?:(?:public|private|protected|final|static|abstract)\s+)*function\s+(\w+)\s*\((.*?)\)\s*[:{]#s',
            $contents,
            $matches,
            PREG_SET_ORDER,
        );

        foreach ($matches as [, $doc, $method, $parameters]) {
            preg_match_all('/@param\s+\S+\s+\$(\w+)/', $doc, $documented);

            foreach ($documented[1] as $name) {
                if (preg_match('/\$' . $name . '\b/', $parameters) !== 1) {
                    $found[] = "{$path}: {$method}() documents \${$name}";
                }
            }
        }
    }

    expect($found)->toBe([]);
});

/**
 * The front door describes the package.
 *
 * `icpBrasil()` and `extendArchive()` were both public for a release before the
 * README mentioned either, which is the same failure as a stale docblock at a
 * larger scale: the thing that tells people what the package does had stopped
 * being true. A method a consumer is expected to call is a method the first
 * page should name (CONTRIBUTING.md).
 *
 * The check is deliberately shallow. It asks whether the name appears, not
 * whether what is written about it is any good, because only the second is
 * worth a human's time and only the first can be checked at all.
 */
it('names every entry point on the front page', function () {
    $readme = (string) file_get_contents(dirname(__DIR__, 2) . '/README.md');
    $missing = [];

    foreach (new ReflectionClass(LSNepomuceno\Signet\Signet::class)->getMethods() as $method) {
        // Infrastructure a consumer never calls on purpose, plus the accessors
        // that exist so a host container can reach a collaborator. The builder
        // appears in every example rather than by name.
        $infrastructure = [
            '__construct', 'newSignature', 'temp', 'processes', 'transport',
            'signer', 'validator', 'verifier', 'sealRenderer', 'certificateReader',
        ];

        if (! $method->isPublic() || in_array($method->getName(), $infrastructure, true)) {
            continue;
        }

        if (! str_contains($readme, $method->getName())) {
            $missing[] = $method->getName();
        }
    }

    expect($missing)->toBe([]);
});

/**
 * The error-suppression operator does not appear in `src/`.
 *
 * Three places in this package ask a question by trying something and reading
 * the refusal, and `@` is the language's way of saying so. It is not enough on
 * its own: a custom error handler is still invoked for a suppressed
 * diagnostic, and PHPUnit installs one that reports it. The suite carried 109
 * warnings that way, every one of them expected, which is precisely how a
 * warning count stops meaning anything and a real one hides among them.
 *
 * `Support\Probe::run()` replaces the handler for the duration of the call, so
 * the diagnostic is not raised at all, and `phpunit.xml` fails the run on any
 * warning that is. One mechanism rather than two: `@` looks like it does the
 * same job and does not.
 *
 * Tokenised rather than grepped, so an `@` inside a string or a docblock, an
 * email address in an author tag among them, does not trip the gate.
 */
it('suppresses no diagnostic with the @ operator', function () {
    $found = [];

    foreach (phpFilesUnder(packageRoot() . '/src') as $path => $contents) {
        foreach (token_get_all($contents) as $token) {
            if ($token === '@') {
                $found[] = $path;
            }
        }
    }

    expect(array_values(array_unique($found)))->toBe([]);
});

/**
 * Every file declares strict types.
 *
 * `docs/spec/conventions.md` makes it mandatory, and `pint.json` writes it, so
 * this exists for the case Pint cannot reach: a file added outside the
 * formatter's path, or the rule being switched off again. It was off on
 * purpose until 2026-08-12, `"declare_strict_types": false`, and none of the
 * 169 files carried the declaration.
 *
 * The arch expectation covers `src/`. It cannot cover `tests/` or `bin/`,
 * because arch expectations work on classes and those files declare none: the
 * CLI entry point is a script and the test files are closures. Hence the walk
 * below as well.
 */
arch('src declares strict types')
    ->expect('LSNepomuceno\Signet')
    ->toUseStrictTypes();

it('declares strict types in every file, including the ones with no class in them', function () {
    $missing = [];

    foreach (['/src', '/tests', '/bin'] as $directory) {
        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 2) . $directory)) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $contents = (string) file_get_contents($file->getPathname());

            if (! str_contains($contents, 'declare(strict_types=1);')) {
                $missing[] = str_replace(dirname(__DIR__, 2) . '/', '', $file->getPathname());
            }
        }
    }

    sort($missing);

    expect($missing)->toBe([]);
});
