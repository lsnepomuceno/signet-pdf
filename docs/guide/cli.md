# Command line

The package ships `vendor/bin/signet`, built on `symfony/console`, with four
commands. It is meant for operators and for CI rather than as the primary API.

```bash
vendor/bin/signet sign contract.pdf --certificate cert.pfx
vendor/bin/signet verify contract-signed.pdf
vendor/bin/signet fields contract.pdf
vendor/bin/signet check
```

## sign

Signs a document with an A1 certificate.

| Option | Requirement | Meaning |
|---|---|---|
| `pdf` | argument, required | path to the document |
| `--certificate`, `-c` | required | path to the PKCS#12 or PEM certificate |
| `--password-env` | default `SIGNET_PASSWORD` | **name** of the environment variable holding the password |
| `--out`, `-o` | | where to write the signed document |
| `--profile`, `-p` | default `pades-b-b` | `legacy`, `pades-b-b`, `pades-b-t`, `pades-b-lt`, `pades-b-lta` |
| `--tsa` | | timestamp authority URL, required from `pades-b-t` up |

```bash
export SIGNET_PASSWORD='the certificate password'
vendor/bin/signet sign contract.pdf -c cert.pfx -o signed.pdf

vendor/bin/signet sign contract.pdf \
    -c cert.pfx \
    -o signed.pdf \
    -p pades-b-t \
    --tsa https://freetsa.org/tsr
```

::: tip The password is never an argument
It is read from an environment variable whose **name** you pass, because a
command line is visible in `ps` and lands in shell history. `--password-env`
names the variable; it does not take the password.
:::

## verify

```bash
vendor/bin/signet verify contract-signed.pdf
vendor/bin/signet verify contract-signed.pdf --trust /etc/ssl/anchors
vendor/bin/signet verify contract-signed.pdf --json
```

| Option | Meaning |
|---|---|
| `--json` | print a machine-readable report |
| `--trust` | a PEM file or a directory of roots to validate the chain against |

**The verdict is in the exit status**, so a build can gate on it without parsing
anything:

| Status | Means |
|---|---|
| `0` | every signature verifies |
| `1` | one does not |
| `2` | the document could not be read |

```bash
if ! vendor/bin/signet verify "$file" --json > report.json; then
    echo "refusing to publish $file"
    exit 1
fi
```

## fields

Lists the signature fields a document declares, signed or not. It is the
question that comes before signing into a template someone else laid out.

```bash
vendor/bin/signet fields template.pdf
vendor/bin/signet fields template.pdf --json
```

## check

Reports what this package needs from the environment, before anything is signed.

```bash
vendor/bin/signet check
vendor/bin/signet check --tsa
vendor/bin/signet check --tsa --tsa-url https://freetsa.org/tsr
```

| Option | Meaning |
|---|---|
| `--tsa` | also reach the configured timestamp authority |
| `--tsa-url` | the authority to reach, with `--tsa` |

It exists because a missing `openssl` binary once made validation report every
signature as invalid, in silence. Run it in the image build, not after the first
support ticket.
