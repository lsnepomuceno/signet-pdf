# Command line

The package ships `vendor/bin/signet`, built on `symfony/console`, with five
commands. It is meant for operators and for CI rather than as the primary API.

```bash
vendor/bin/signet sign contract.pdf --certificate cert.pfx
vendor/bin/signet verify contract-signed.pdf
vendor/bin/signet fields contract.pdf
vendor/bin/signet extend archive.pdf --out archive-renewed.pdf
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

## extend

Appends a fresh archive timestamp to a document that already carries signatures,
which is what keeps a B-LTA archive checkable as the algorithms under it age
([0022](../decisions/0022-the-archive-timestamp-is-a-chain.md)).

**No certificate is involved.** A DocTimeStamp is signed by the authority and
not by the signer, so this is the one thing in the package that belongs in a
cron entry with no key material on the machine.

```bash
vendor/bin/signet extend archive.pdf --out archive-renewed.pdf
vendor/bin/signet extend archive.pdf --in-place --if-due=365 --json
```

| Option | Meaning |
|---|---|
| `--out`, `-o` | where to write the extended document |
| `--in-place` | overwrite the document instead of writing a copy |
| `--tsa` | timestamp authority URL |
| `--if-due` | extend only when the newest archive timestamp is older than this many days |
| `--json` | print a machine-readable report |

::: warning The destination is never guessed
One of `--out` and `--in-place` is required. Writing in place is what a
retention job usually wants and is also the only version that can destroy an
archive, so it is stated rather than fallen into.
:::

`--if-due` is what turns the entry from "extend everything every night" into
something that can run over a directory: a document stamped last month is left
alone, and the authority is not asked about it. **An age the command cannot
establish counts as due**, since extending a document that did not need it costs
one request and skipping one that did lets an archive age out.

**The three failures are three different problems**, and only one of them is
worth retrying:

| Status | Means |
|---|---|
| `0` | extended, or nothing was due |
| `1` | something else failed, including a document that could not be written |
| `2` | the document could not be read |
| `3` | the document carries no signature, so there is nothing to archive |
| `4` | the document is certified `no-changes`, which forbids the revision |
| `75` | the authority did not answer. `EX_TEMPFAIL`, and the one to retry |

```bash
# renew every archive older than a year, retrying only what is worth retrying
for file in /var/archive/*.pdf; do
    vendor/bin/signet extend "$file" --in-place --if-due=365 --tsa https://freetsa.org/tsr
    test $? -eq 75 && echo "$file" >> /var/archive/retry.txt
done
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
