import { defineConfig } from 'vitepress'
import { release } from './release'
import { index, pages, sections } from './sidebar'

/**
 * The documentation site. A pilot: the guide holds one page, and the rest of
 * the manual still lives in README.md waiting to be split up.
 *
 * `srcDir` is this directory, so the prose stays where the code is and the
 * pages the repository already maintains are published as they are rather
 * than copied. `/docs` is `export-ignore` in `.gitattributes`, so none of this
 * reaches the package a consumer installs.
 */
export default defineConfig({
  title: 'Signet PDF',
  description:
    'Sign PDF files with A1 certificates, and verify the signatures already in them.',

  // The project page on GitHub Pages is served under the repository name.
  base: '/signet-pdf/',

  cleanUrls: true,

  // The home page is `guide/index.md`, published at the site root.
  //
  // A page cannot live inside `.vitepress/`: that directory is the site's
  // machinery, and VitePress excludes it from routing, so a document put there
  // is simply never published. What can be done is to keep the root of `docs/`
  // free of loose files, so everything outside `.vitepress/` is a directory of
  // documentation and nothing else.
  rewrites: { 'guide/index.md': 'index.md' },
  lastUpdated: true,

  // A link that resolves to nothing fails the build, which is the same rule
  // `tests/Project/SpecTest.php` applies to prose, arriving through the other
  // door. The site catches what the test cannot see, since the test asks
  // whether a path exists in the repository and this asks whether it exists as
  // a page.
  //
  // There is no exception any more. There used to be one, for a link climbing
  // out of `docs/` to `UPGRADE.md` in the root, and it is gone the way the
  // comment said it would go: those documents are pages of this site now
  // (`releases/`), so the links point at pages and are checked like every other
  // (docs/decisions/0112-the-site-documents-one-release-line.md).
  //
  // The two `releases/` pages still climb out, deliberately, to name the
  // canonical file GitHub renders. They are the only ones, and they are
  // allowed here rather than by turning the check off.
  ignoreDeadLinks: [/^(\.\/)?(\.\.\/)+(CHANGELOG|UPGRADE)(\.md)?$/],

  markdown: {
    config(md) {
      // The two `releases/` pages include files that live in the repository
      // root, and those files' own links are written from there:
      // `docs/decisions/0030-....md`, `UPGRADE.md`. Correct where they are
      // authored, and pointing at nothing once the same text is a page under
      // `/releases/`.
      //
      // So they are rewritten as they render, on those two pages only. This
      // wraps VitePress's own link rule rather than replacing it, and rewrites
      // before calling it, so the dead-link check sees the rewritten target and
      // still fails a link that goes nowhere. Ignoring them instead would have
      // left a reader of the site clicking into a 404
      // (docs/decisions/0112-the-site-documents-one-release-line.md).
      const included = ['releases/changelog.md', 'releases/upgrade.md']
      const previous = md.renderer.rules.link_open

      md.renderer.rules.link_open = (tokens, index, options, env, self) => {
        if (included.includes(env?.relativePath)) {
          const token = tokens[index]
          const href = token.attrGet('href')

          if (href) {
            token.attrSet(
              'href',
              href
                .replace(/^(\.\/)?CHANGELOG\.md/, '/releases/changelog.md')
                .replace(/^(\.\/)?UPGRADE\.md/, '/releases/upgrade.md')
                .replace(/^(\.\/)?docs\//, '/'),
            )
          }
        }

        return previous
          ? previous(tokens, index, options, env, self)
          : self.renderToken(tokens, index, options)
      }
    },
  },

  head: [['meta', { name: 'theme-color', content: '#cf222e' }]],

  themeConfig: {
    // Handed to the client so the banner every page carries can name the line
    // this build documents (docs/decisions/0112-the-site-documents-one-release-line.md).
    release: release(),

    // `activeMatch` on every entry, because the default is an exact match
    // against `link`: without it "Guide" highlights on the one page it points
    // at and goes dark on the other sixteen, which reads as having left the
    // section rather than as having moved inside it.
    nav: [
      { text: 'Guide', link: '/guide/getting-started', activeMatch: '^/guide/' },
      { text: 'Specification', link: '/spec/public-api', activeMatch: '^/spec/' },
      { text: 'Decisions', link: '/decisions/README', activeMatch: '^/decisions/' },
      { text: 'History', link: '/history/decision-log', activeMatch: '^/history/' },
      {
        // The version in the navigation, read from the changelog rather than
        // typed here, so it cannot drift from the release it names. The
        // archives beneath it are what makes this a switcher rather than a
        // label (docs/decisions/0112-the-site-documents-one-release-line.md).
        text: `v${release().version}`,
        items: [
          { text: 'Changelog', link: '/releases/changelog' },
          { text: 'Upgrading', link: '/releases/upgrade' },
          {
            text: '1.x (archived)',
            link: '/signet-pdf/v1/',
          },
          {
            text: 'All releases',
            link: 'https://github.com/lsnepomuceno/signet-pdf/releases',
          },
        ],
      },
      {
        text: 'Packagist',
        link: 'https://packagist.org/packages/lsnepomuceno/signet-pdf',
      },
    ],

    sidebar: {
      // Grouped and ordered by hand, because the reading order of a guide is
      // not its filename order. `sections()` refuses a definition that does
      // not match the directory exactly, in either direction, so a page cannot
      // be added without appearing here and an entry cannot outlive its file.
      '/guide/': sections('guide', [
        { text: 'Introduction', slugs: ['getting-started', 'configuration'] },
        {
          text: 'Signing',
          slugs: [
            'signing',
            'profiles',
            'seals',
            'templates',
            'certification',
            'encrypted-documents',
          ],
        },
        { text: 'Verifying', slugs: ['validation', 'trust', 'samples'] },
        { text: 'Certificates', slugs: ['certificates', 'icp-brasil'] },
        {
          text: 'Tooling',
          slugs: ['cli', 'testing', 'audit-log', 'troubleshooting', 'types', 'references'],
        },
      ]),

      '/spec/': [{ text: 'Specification', items: pages('spec') }],
      '/releases/': [{ text: 'Releases', items: pages('releases') }],
      '/history/': [{ text: 'History', items: pages('history') }],

      '/decisions/': [
        {
          text: 'Decisions',
          link: index('decisions'),
          items: pages('decisions'),
          collapsed: false,
        },
      ],
    },

    outline: [2, 3],

    socialLinks: [
      { icon: 'github', link: 'https://github.com/lsnepomuceno/signet-pdf' },
    ],

    editLink: {
      pattern:
        'https://github.com/lsnepomuceno/signet-pdf/edit/main/docs/:path',
      text: 'Edit this page on GitHub',
    },

    search: { provider: 'local' },

    footer: {
      message: 'Released under the MIT Licence.',
      copyright: 'Copyright © Lucas Nepomuceno',
    },
  },
})
