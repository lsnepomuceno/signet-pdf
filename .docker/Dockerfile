# Development / CI image for signet-pdf.
#
# The PHP version is parameterized so any cell of the CI matrix can be
# reproduced locally. The development host does not need the target version
# installed, which is the normal case here: this package's floor is 8.4 and a
# distribution PHP is usually older.
ARG PHP_VERSION=8.4

FROM php:${PHP_VERSION}-cli-alpine

# openssl: PKCS#7 signing and PKCS#12 reading
# gd / imagick: seal rendering (Intervention Image)
# bcmath: required by tc-lib-pdf through tc-lib-barcode
# pcov: fast coverage driver, required by mutation testing
# qpdf: structural validation of what the revision writer emits. Unlike
#       veraPDF it is C++ and a couple of megabytes, so it belongs in the
#       everyday image (docs/spec/quality-policy.md).
RUN apk add --no-cache \
        git unzip libzip-dev libpng-dev libjpeg-turbo-dev freetype-dev \
        imagemagick imagemagick-dev qpdf $PHPIZE_DEPS \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" gd zip bcmath \
    && pecl install pcov imagick \
    && docker-php-ext-enable pcov imagick \
    && apk del $PHPIZE_DEPS \
    && rm -rf /tmp/pear

# Static analysis needs far more than the 128M CLI default.
RUN printf 'memory_limit = 1G\n' > /usr/local/etc/php/conf.d/zz-dev.ini

# veraPDF, the reference PDF/A validator.
#
# It is Java, so it costs a headless JRE on top of its own 33 MB. It is
# installed everywhere rather than behind a build argument: a suite that skips
# its PDF/A checks by default is a suite whose conformance claims are
# unverified on the machine where the work is being done. Nothing skips.
#
# Pinned rather than tracking "latest": a validator that changes its verdicts
# between builds cannot be the thing a gate is measured against.
# See docs/decisions/0026-verification-tools-are-instruments.md.
ARG VERAPDF_VERSION=1.30.2

# **The full JRE, not the headless one.** veraPDF is happy either way; PDFBox,
# which EU DSS reads a document with, loads `liblcms.so` out of the JVM's own
# `lib/` to read an ICC profile, and the headless build omits it along with the
# rest of java.desktop. Every sealed document this package emits carries an sRGB
# profile, so the witness installed against them died with
# `UnsatisfiedLinkError: no lcms in system library path` and read only the
# documents that had none
# (docs/decisions/0124-the-policy-digest-has-an-offline-witness.md).
RUN set -eux; \
        apk add --no-cache openjdk17-jre curl; \
        curl -sSL -o /tmp/verapdf.zip \
            "https://software.verapdf.org/releases/${VERAPDF_VERSION%.*}/verapdf-greenfield-${VERAPDF_VERSION}-installer.zip"; \
        unzip -q /tmp/verapdf.zip -d /tmp/verapdf-src; \
        printf '%s\n' \
            '<?xml version="1.0" encoding="UTF-8" standalone="no"?>' \
            '<AutomatedInstallation langpack="eng">' \
            '<com.izforge.izpack.panels.htmlhello.HTMLHelloPanel id="welcome"/>' \
            '<com.izforge.izpack.panels.target.TargetPanel id="install_dir">' \
            '<installpath>/opt/verapdf</installpath>' \
            '</com.izforge.izpack.panels.target.TargetPanel>' \
            '<com.izforge.izpack.panels.packs.PacksPanel id="sdk_pack_select">' \
            '<pack index="0" name="veraPDF GUI" selected="true"/>' \
            '<pack index="1" name="veraPDF Mac and *nix Scripts" selected="true"/>' \
            '<pack index="3" name="veraPDF Corpus and Tests" selected="false"/>' \
            '<pack index="4" name="veraPDF Documentation" selected="false"/>' \
            '<pack index="5" name="veraPDF Sample Plugins" selected="false"/>' \
            '</com.izforge.izpack.panels.packs.PacksPanel>' \
            '<com.izforge.izpack.panels.install.InstallPanel id="install"/>' \
            '<com.izforge.izpack.panels.finish.FinishPanel id="finish"/>' \
            '</AutomatedInstallation>' > /tmp/verapdf-auto.xml; \
        java -jar /tmp/verapdf-src/verapdf-greenfield-*/verapdf-izpack-installer-*.jar /tmp/verapdf-auto.xml; \
        ln -s /opt/verapdf/verapdf /usr/local/bin/verapdf; \
        rm -rf /tmp/verapdf.zip /tmp/verapdf-src /tmp/verapdf-auto.xml; \
        verapdf --version

# The Arlington PDF Model, the PDF Association's machine-readable grammar of
# ISO 32000, and TestGrammar which checks a file against it.
#
# It asks what nothing else here asks. qpdf asks whether the syntax closes and
# the offsets line up; veraPDF asks about PDF/A and PDF/UA. This asks whether
# each object is the object the specification describes: right key, right type,
# right version, right permitted values. For a revision writer that assembles
# dictionaries by string concatenation, that is the check nobody was running
# (docs/decisions/0037-what-we-write-against-the-grammar.md).
#
# Pinned by commit rather than by tag: the releases carry no binaries, and the
# TSV model files live in the same tree, so one SHA pins the tool and the
# grammar together.
#
# Two patches, both to code the project vendors rather than writes, and both
# recorded here rather than carried as files:
#
#   sarge.h uses uint32_t without including <cstdint>, which glibc pulls in
#   transitively and musl does not, so -include cstdint supplies it.
#
#   pdfium's logging.h reinterpret_casts NULL, which GCC 15 rejects outright
#   and -fpermissive does not downgrade. The line is dead code after abort().
#
# The pdfium backend, not PDFix: PDFix ships a proprietary libpdfix.so, and the
# QPDF backend is marked not working by its own README.
ARG ARLINGTON_COMMIT=9d75f6de8cdc3883d5519f83e804238329b9eb10

RUN set -eux; \
        apk add --no-cache --virtual .arlington-build build-base cmake linux-headers; \
        git clone --quiet https://github.com/pdf-association/arlington-pdf-model.git /tmp/arlington; \
        cd /tmp/arlington; \
        git checkout --quiet "${ARLINGTON_COMMIT}"; \
        sed -i \
            's|reinterpret_cast<volatile char\*>(NULL)|reinterpret_cast<volatile char*>(static_cast<uintptr_t>(0))|' \
            TestGrammar/pdfium/third_party/logging.h; \
        cd TestGrammar; \
        cmake -B build -DPDFSDK_PDFIUM=ON -DCMAKE_BUILD_TYPE=Release -DCMAKE_CXX_FLAGS="-include cstdint" . ; \
        cmake --build build --config Release -j"$(nproc)"; \
        mkdir -p /opt/arlington; \
        cp bin/linux/TestGrammar /usr/local/bin/testgrammar; \
        cp -r /tmp/arlington/tsv /opt/arlington/tsv; \
        rm -rf /tmp/arlington; \
        apk del .arlington-build; \
        testgrammar --help > /dev/null

# The model is versioned, and `latest` is the current specification. Pointing
# at the parent finds no FileTrailer.tsv and reports it as a missing model.
ENV ARLINGTON_TSV=/opt/arlington/tsv/latest

# pyHanko, the reader that enforces /DocMDP.
#
# Certification is the one claim this package makes with no other outside
# witness: pdfsig reports signatures and their coverage and says nothing about
# the permissions a certification imposes, so a document certified at
# no-changes and then modified reads the same to it as one that was never
# certified. pyHanko compares the revisions against the policy and refuses.
#
# Two distributions, pinned separately: `pip install pyHanko` installs the
# library and no command at all, because the CLI lives in pyhanko-cli and is
# versioned on its own.
#
# tzdata is not optional. pyHanko loads a Europe/Brussels zone at import time
# for the EU trusted list, and Alpine ships no zone data, so without it every
# invocation dies in an import with ZoneInfoNotFoundError.
ARG PYHANKO_VERSION=0.36.2
ARG PYHANKO_CLI_VERSION=0.4.2

RUN set -eux; \
        apk add --no-cache python3 py3-pip tzdata; \
        pip install --break-system-packages --no-cache-dir \
            "pyHanko==${PYHANKO_VERSION}" "pyhanko-cli==${PYHANKO_CLI_VERSION}"; \
        pyhanko --version

# poppler-utils provides pdfsig, the independent cross-check that has caught
# bugs this suite passed straight through (docs/spec/invariants.md, rule 3).
RUN apk add --no-cache poppler-utils && pdfsig -v 2>&1 | head -1

# EU DSS, the reference implementation of the European signature standards, and
# here the witness for one property nothing else in the image can see: whether
# the digest a signature declares for its policy is the digest that policy
# document actually carries.
#
# It earned its place by catching a defect the suite passed. Every ICP-Brasil
# policy digest this package shipped was SHA-256 of the policy *file*, taken
# from ITI's published list, where what a signature declares is the
# `signPolicyHash` the policy carries in its own third field. Both are real
# hashes of real artefacts published by the same authority, which is why the
# wrong one survived review and a test. pdfsig, pyHanko and Demoiselle all
# reported the defective document as valid, because none of them resolves the
# policy document and so none of them ever compares
# (docs/decisions/0124-the-policy-digest-has-an-offline-witness.md).
#
# It is Java, and it costs no new runtime: the JRE is already here for veraPDF.
# What it costs is the jars, which are resolved at build time and copied in, so
# no build tool and no network is present when the suite runs.
#
# Pinned rather than tracking latest, for the reason every instrument here is
# pinned: a verifier that changes its verdicts between builds cannot be what a
# gate is measured against.
#
# The logging configuration is a guard rather than a fix. No SLF4J provider is
# on this classpath, so the three warnings it prints about that go to stderr and
# stdout stays parseable JSON. The configuration is here so that remains true
# the day a DSS release pulls one in.
ARG DSS_VERSION=6.5

COPY .docker/dss /tmp/dss

RUN set -eux; \
        apk add --no-cache --virtual .dss-build maven openjdk17-jdk; \
        cd /tmp/dss; \
        mvn -q -B -Ddss.version="${DSS_VERSION}" \
            dependency:copy-dependencies -DoutputDirectory=/opt/dss/lib; \
        mvn -q -B -Ddss.version="${DSS_VERSION}" compile; \
        cp -r target/classes /opt/dss/classes; \
        printf '%s\n' \
            '<configuration><root level="OFF"/></configuration>' \
            > /opt/dss/logback.xml; \
        printf '%s\n' \
            '#!/bin/sh' \
            '# The policy witness. Prints one JSON object on stdout.' \
            'exec java -Dlogback.configurationFile=/opt/dss/logback.xml \' \
            '     -cp "/opt/dss/classes:/opt/dss/lib/*" DssPolicyCheck "$@"' \
            > /usr/local/bin/dss-policy-check; \
        chmod +x /usr/local/bin/dss-policy-check; \
        apk del .dss-build; \
        rm -rf /tmp/dss /root/.m2; \
        cd /; \
        dss-policy-check 2>&1 | grep -q 'usage: dss-policy-check'

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_MEMORY_LIMIT=-1

# The bind-mounted checkout belongs to the host user, so git inside the
# container refuses to read it and Composer loses the package version.
RUN git config --global --add safe.directory /app

WORKDIR /app
