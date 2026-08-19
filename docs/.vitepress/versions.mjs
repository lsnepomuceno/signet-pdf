import { execFileSync } from 'node:child_process'
import { cpSync, mkdirSync, rmSync, symlinkSync, writeFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'

/**
 * Builds the site, and the archive of the previous line beside it.
 *
 * The site documents one line at a time and says which, which was the whole of
 * [0112](../decisions/0112-the-site-documents-one-release-line.md). That is no
 * longer enough on its own: `2.0.0` is a major release, so a reader who
 * installed `^1` needs pages that describe `^1` rather than a banner telling
 * them the ones they are reading do not.
 *
 * **The `1.x` line never had a site.** At `1.0.1` the repository carried
 * `docs/spec`, `docs/decisions` and `docs/history` as files GitHub rendered,
 * and no guide at all. So the archive is not a rebuild of an old site: it is
 * this machinery pointed at that tag's own markdown, which makes it the
 * documentation that shipped with the release rather than a reconstruction.
 *
 * The layout it produces:
 *
 *     /signet-pdf/         the current line, whatever that is now
 *     /signet-pdf/v1/      the 1.x archive, frozen at its last tag
 *
 * The current line lives at the root rather than under `/v2/`, so a link into
 * the documentation does not have to be rewritten every major release, and an
 * archive is added under its own prefix when a line stops being current.
 */

const vitepress = dirname(fileURLToPath(import.meta.url))
const docs = dirname(vitepress)
const dist = join(vitepress, 'dist')
const binary = join(vitepress, 'node_modules', 'vitepress', 'bin', 'vitepress.js')

/**
 * Every archived line, newest first. One entry per major release that stopped
 * being current, pinned to the last tag published on it.
 */
const ARCHIVES = [
  {
    prefix: 'v1',
    line: '1.x',
    tag: '1.0.1',
    title: 'Signet PDF 1.x',
  },
]

/**
 * Where the current line lives, as an absolute URL.
 *
 * An archive cannot link to it as an internal path: VitePress prepends the
 * site's own base to those, and the archive's base is `/signet-pdf/v1/`, so
 * `/signet-pdf/` renders as `/signet-pdf/v1/signet-pdf/`. An absolute URL is
 * the one form that leaves an archive's link alone, and an archive is published
 * at exactly one address for the life of the project.
 */
const CURRENT = 'https://lsnepomuceno.github.io/signet-pdf/'

function run(command, args, options = {}) {
  execFileSync(command, args, { stdio: 'inherit', ...options })
}

/**
 * The tag's own `docs/` tree, in a directory of its own.
 *
 * File by file through `git show`, which reads the object database and touches
 * neither the working copy nor the index: this runs from the repository
 * somebody is working in, so a checkout or a worktree would be a side effect
 * they did not ask for.
 *
 * **`git archive` cannot do it**, which is worth writing down because it is the
 * obvious first attempt: `.gitattributes` marks `/docs export-ignore` so the
 * documentation stays out of the package a consumer installs, and `git archive`
 * honours that. The command succeeds and produces a tar with nothing in it.
 */
function materialise(archive) {
  const repository = dirname(docs)
  const root = join(dist, '.archives', archive.prefix)

  rmSync(root, { recursive: true, force: true })
  mkdirSync(root, { recursive: true })

  const listed = execFileSync('git', ['ls-tree', '-r', '--name-only', archive.tag, '--', 'docs'], {
    cwd: repository,
    encoding: 'utf-8',
  })

  const files = listed.split('\n').filter(name => name.endsWith('.md'))

  if (files.length === 0) {
    throw new Error(`versions: ${archive.tag} carries no documentation to archive`)
  }

  for (const file of files) {
    const contents = execFileSync('git', ['show', `${archive.tag}:${file}`], {
      cwd: repository,
      encoding: 'utf-8',
    })

    const target = join(root, file)

    mkdirSync(dirname(target), { recursive: true })
    writeFileSync(target, contents)
  }

  console.log(`versions: extracted ${files.length} pages from ${archive.tag}`)

  return join(root, 'docs')
}

/**
 * The machinery the archive is built with, which is this one.
 *
 * The tag's own `.vitepress/` is not used even where it exists: an archive
 * built with its own tooling is a second build to keep working, and the point
 * of the archive is the prose rather than the generator.
 */
function install(source) {
  const machinery = join(source, '.vitepress')

  rmSync(machinery, { recursive: true, force: true })
  mkdirSync(machinery, { recursive: true })

  cpSync(join(vitepress, 'theme'), join(machinery, 'theme'), { recursive: true })
  cpSync(join(vitepress, 'sidebar.ts'), join(machinery, 'sidebar.ts'))

  // Vite resolves a bare import by climbing from the file that made it, and
  // `.vitepress/` is a sibling of the content rather than an ancestor of it.
  // The same link `postinstall.mjs` makes for the main site, for the same
  // reason (docs/.vitepress/postinstall.mjs).
  symlinkSync(join(vitepress, 'node_modules'), join(source, 'node_modules'), 'junction')
}

/**
 * A home page, because the tag never had one.
 *
 * Written rather than copied: `docs/` at `1.0.1` holds three directories and no
 * index, since it was read on GitHub where a directory listing is the index.
 */
function homePage(archive) {
  return `---
title: ${archive.title}
---

# Signet PDF ${archive.line}

**This is the archived documentation for the ${archive.line} line, frozen at
\`${archive.tag}\`.** It is the prose that shipped with that release, published
as it stood: the specification, the decision records and the history.

For the line that is current now, go to the
[current documentation](${CURRENT}).

The \`${archive.line}\` line had no guide. It was documented by its README, which
is on the tag, and by the pages below.

- [Specification](./spec/public-api)
- [Decisions](./decisions/README)
- [History](./history/decision-log)
`
}

function config(archive) {
  return `import { defineConfig } from 'vitepress'
import { index, pages } from './sidebar'

// Generated by docs/.vitepress/versions.mjs. Editing it edits nothing: the
// archive is rebuilt from ${archive.tag} on every deploy.
export default defineConfig({
  title: ${JSON.stringify(archive.title)},
  description: 'Archived documentation for the ${archive.line} line.',
  base: '/signet-pdf/${archive.prefix}/',
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
      {
        text: ${JSON.stringify(archive.line + ' (archived)')},
        items: [
          // Absolute, because an internal path here would get the archive's
          // own prefix; and with a target, because the router would otherwise
          // intercept a same-origin link and look for the route inside the
          // archive, which does not have it.
          { text: 'Current documentation', link: '${CURRENT}', target: '_self' },
          { text: 'All releases', link: 'https://github.com/lsnepomuceno/signet-pdf/releases' },
        ],
      },
    ],

    sidebar: {
      '/spec/': [{ text: 'Specification', items: pages('spec') }],
      '/history/': [{ text: 'History', items: pages('history') }],
      '/decisions/': [
        { text: 'Decisions', link: index('decisions'), items: pages('decisions'), collapsed: false },
      ],
    },

    outline: [2, 3],

    socialLinks: [
      { icon: 'github', link: 'https://github.com/lsnepomuceno/signet-pdf/tree/${archive.tag}' },
    ],

    search: { provider: 'local' },

    footer: {
      message: 'Released under the MIT Licence.',
      copyright: 'Copyright © Lucas Nepomuceno',
    },
  },
})
`
}

function build(source) {
  run(process.execPath, [binary, 'build', source], { cwd: vitepress })
}

// The current line first, so a failure in it fails the whole build before any
// time is spent on an archive that cannot change.
build(docs)

console.log('versions: built the current line at the root')

for (const archive of ARCHIVES) {
  const source = materialise(archive)

  install(source)
  writeFileSync(join(source, 'index.md'), homePage(archive))
  writeFileSync(join(source, '.vitepress', 'config.mjs'), config(archive))

  build(source)

  cpSync(join(source, '.vitepress', 'dist'), join(dist, archive.prefix), { recursive: true })

  console.log(`versions: built the ${archive.line} archive from ${archive.tag} at /${archive.prefix}/`)
}

// The extracted trees are inside dist/ only because it is the directory this
// script already owns, and they are not part of what gets published.
rmSync(join(dist, '.archives'), { recursive: true, force: true })
