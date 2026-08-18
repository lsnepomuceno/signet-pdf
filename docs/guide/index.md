---
layout: home

hero:
  name: Signet PDF
  text: Signatures a reader can verify
  tagline: >
    A standalone PHP library that signs PDF files with A1 certificates and
    cryptographically verifies the signatures already in them. No framework,
    no container, no global state.
  actions:
    - theme: brand
      text: Getting started
      link: /guide/getting-started
    - theme: alt
      text: Public API
      link: /spec/public-api
    - theme: alt
      text: Decisions
      link: /decisions/README

features:
  - title: A revision, never a rebuild
    details: >
      Signing appends a revision to the file it was given, so the original
      bytes survive and an earlier signature stays valid. Annotations and form
      fields survive with them.
    link: /decisions/0006-incremental-revision
    linkText: Why it works this way

  - title: PAdES, up to B-LTA
    details: >
      Legacy through pades-b-lta, with RFC 3161 timestamps, a document
      security store and an archive timestamp, each behind an injected
      transport the host application controls.
    link: /guide/profiles
    linkText: Profiles and timestamps

  - title: Verified, not asserted
    details: >
      Validation reports what a document can prove: whether the CMS verifies,
      what a third party attested, what changed after each signature, and what
      is missing for offline use.
    link: /guide/validation
    linkText: Verifying signatures

  - title: The reasoning is written down
    details: >
      Every design decision has a numbered record explaining what was chosen,
      what it cost and what has happened since.
    link: /decisions/README
    linkText: Read the decisions
---

