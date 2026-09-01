import { readFileSync } from 'node:fs'
import { defineConfig } from 'vitepress'
import { index, pages } from './sidebar'

/**
 * The configuration an archived line is built with.
 *
 * **This file is never loaded where it sits.** `versions.mjs` copies it into the
 * extracted tag's `.vitepress/` as `config.mts`, beside `sidebar.ts`, the theme
 * and a `descriptor.json` written for that line, and VitePress loads it from
 * there. Its relative imports are written for that destination, which is why
 * `./sidebar` resolves there and here alike.
 *
 * It is a file in the repository rather than a string built at runtime, and
 * that is the whole point of it: what stood here before was a template literal
 * in `versions.mjs` producing this config as text, comments included, with no
 * highlighting, no type checking and a second copy of the sidebar wiring that
 * had to be kept in step with `config.mts` by hand
 * (docs/decisions/0112-the-site-documents-one-release-line.md).
 *
 * The tag's own `.vitepress/` is not used even where it exists: an archive
 * built with its own tooling is a second build to keep working, and the point
 * of an archive is the prose rather than the generator.
 */

type Descriptor = {
  prefix: string
  line: string
  tag: string
  title: string
  base: string
  /** The line that is current now, so the control can point back at it. */
  current: { label: string; base: string; routes: string[] }
}

const descriptor: Descriptor = JSON.parse(
  readFileSync(new URL('./descriptor.json', import.meta.url), 'utf-8'),
)

export default defineConfig({
  title: descriptor.title,
  description: `Archived documentation for the ${descriptor.line} line.`,
  base: descriptor.base,
  cleanUrls: true,

  // The date a page was last updated comes from the commit that touched it, and
  // this tree is an extract rather than a repository.
  lastUpdated: false,

  // **Deliberate, and only here.** These pages were written to be read on
  // GitHub at their tag, so they link to README.md, UPGRADE.md and files under
  // src/, none of which is a page of this site. Failing the build on those
  // would mean editing an archive, which would stop it being one.
  ignoreDeadLinks: true,

  head: [['meta', { name: 'theme-color', content: '#cf222e' }]],

  themeConfig: {
    nav: [
      { text: 'Specification', link: '/spec/public-api', activeMatch: '^/spec/' },
      { text: 'Decisions', link: '/decisions/README', activeMatch: '^/decisions/' },
      { text: 'History', link: '/history/decision-log', activeMatch: '^/history/' },
      { text: 'All releases', link: 'https://github.com/lsnepomuceno/signet-pdf/releases' },
    ],

    // Read by `theme/VersionSwitcher.vue`. An archive offers one destination,
    // the line that is current now, and it carries that line's routes so
    // leaving an archive lands on the same page where the current line still
    // has it.
    versions: {
      active: `${descriptor.line} (archived)`,
      lines: [
        {
          label: descriptor.current.label,
          base: descriptor.current.base,
          routes: descriptor.current.routes,
        },
      ],
    },

    sidebar: {
      '/spec/': [{ text: 'Specification', items: pages('spec') }],
      '/history/': [{ text: 'History', items: pages('history') }],
      '/decisions/': [
        { text: 'Decisions', link: index('decisions'), items: pages('decisions'), collapsed: false },
      ],
    },

    outline: [2, 3],

    socialLinks: [
      { icon: 'github', link: `https://github.com/lsnepomuceno/signet-pdf/tree/${descriptor.tag}` },
    ],

    search: { provider: 'local' },

    footer: {
      message: 'Released under the MIT Licence.',
      copyright: 'Copyright © Lucas Nepomuceno',
    },
  },
})
