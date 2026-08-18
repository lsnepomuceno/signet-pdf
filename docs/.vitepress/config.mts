import { defineConfig } from 'vitepress'
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
  // The exception is a link that climbs out of `docs/`, which today is exactly
  // one: `docs/spec/quality-policy.md` points at `UPGRADE.md` in the root.
  // Those files are correct where they are, GitHub renders them, and
  // `SpecTest` already proves they exist. The exception goes away when the
  // root documents become pages of this site rather than neighbours of it.
  ignoreDeadLinks: [/^(\.\/)?(\.\.\/)+/],

  head: [['meta', { name: 'theme-color', content: '#cf222e' }]],

  themeConfig: {
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
        { text: 'Verifying', slugs: ['validation', 'trust'] },
        { text: 'Certificates', slugs: ['certificates', 'icp-brasil'] },
        {
          text: 'Tooling',
          slugs: ['cli', 'testing', 'audit-log', 'troubleshooting', 'references'],
        },
      ]),

      '/spec/': [{ text: 'Specification', items: pages('spec') }],
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
