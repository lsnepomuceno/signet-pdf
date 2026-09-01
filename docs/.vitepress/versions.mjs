import { execFileSync } from 'node:child_process'
import { cpSync, mkdirSync, readdirSync, rmSync, symlinkSync, writeFileSync } from 'node:fs'
import { dirname, join, relative } from 'node:path'
import { fileURLToPath } from 'node:url'
import { ARCHIVES, CURRENT_URL, archiveBase, archiveRoutes } from './archives.mjs'

/**
 * Builds the site, and the archive of each superseded line beside it.
 *
 * The site documents one line at a time and says which, which was the whole of
 * [0112](../decisions/0112-the-site-documents-one-release-line.md). That is no
 * longer enough on its own: `2.0.0` was a major release, so a reader who
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
 *
 * **This script writes data and copies files, and generates no code.** A third
 * of it used to be JavaScript and Markdown inside template literals: a whole
 * VitePress config assembled as a string, comments included, with no
 * highlighting, no type checking, and a second copy of the sidebar wiring to
 * keep in step with `config.mts` by hand. Those are `archive.mts` and
 * `archive/<prefix>/index.md` now, which are files somebody can read.
 */

const vitepress = dirname(fileURLToPath(import.meta.url))
const docs = dirname(vitepress)
const repository = dirname(docs)
const dist = join(vitepress, 'dist')
const binary = join(vitepress, 'node_modules', 'vitepress', 'bin', 'vitepress.js')

function run(command, args, options = {}) {
  execFileSync(command, args, { stdio: 'inherit', ...options })
}

/**
 * Every page the current line publishes, as a route without its extension.
 *
 * Read from the working tree rather than from a tag, because the current line
 * is whatever is checked out. An archive carries this list so that leaving one
 * lands on the same page rather than on the current site's front door.
 */
function currentRoutes(directory = docs) {
  const routes = []

  for (const entry of readdirSync(directory, { withFileTypes: true })) {
    // `.vitepress/` is the site's machinery and is not routed, which is also
    // why the archive's home pages can live inside it.
    if (entry.name.startsWith('.') || entry.name === 'node_modules') {
      continue
    }

    const path = join(directory, entry.name)

    if (entry.isDirectory()) {
      routes.push(...currentRoutes(path))
    } else if (entry.name.endsWith('.md')) {
      routes.push(relative(docs, path).replace(/\.md$/, ''))
    }
  }

  return routes
}

/**
 * The tag's own `docs/` tree, in a directory of its own.
 *
 * File by file through `git show`, which reads the object database and touches
 * neither the working copy nor the index: this runs from the repository
 * somebody is working in, so a checkout or a worktree would be a side effect
 * they did not ask for.
 */
function materialise(archive) {
  const root = join(dist, '.archives', archive.prefix)

  rmSync(root, { recursive: true, force: true })
  mkdirSync(root, { recursive: true })

  const routes = archiveRoutes(archive, repository)

  if (routes.length === 0) {
    throw new Error(`versions: ${archive.tag} carries no documentation to archive`)
  }

  for (const route of routes) {
    const file = `docs/${route}.md`

    const contents = execFileSync('git', ['show', `${archive.tag}:${file}`], {
      cwd: repository,
      encoding: 'utf-8',
    })

    const target = join(root, file)

    mkdirSync(dirname(target), { recursive: true })
    writeFileSync(target, contents)
  }

  console.log(`versions: extracted ${routes.length} pages from ${archive.tag}`)

  return join(root, 'docs')
}

/**
 * The machinery the archive is built with, which is this site's.
 *
 * Copied rather than referenced: VitePress resolves `.vitepress/` relative to
 * the build root and offers no way to point it elsewhere. What is copied is
 * files that exist in the repository, so the archive is built by code somebody
 * has reviewed rather than by code assembled here.
 */
function install(source, archive) {
  const machinery = join(source, '.vitepress')

  rmSync(machinery, { recursive: true, force: true })
  mkdirSync(machinery, { recursive: true })

  cpSync(join(vitepress, 'theme'), join(machinery, 'theme'), { recursive: true })
  cpSync(join(vitepress, 'sidebar.ts'), join(machinery, 'sidebar.ts'))

  // Renamed on the way in, because VitePress loads `.vitepress/config.*` and
  // nothing else. Its relative imports are written for this destination.
  cpSync(join(vitepress, 'archive.mts'), join(machinery, 'config.mts'))

  // The archive's home page, because the tag never had one: `docs/` at 1.0.1
  // held three directories and no index, since it was read on GitHub where a
  // directory listing is the index. It is ours rather than the tag's, so
  // committing it copies nothing twice.
  cpSync(join(vitepress, 'archive', archive.prefix, 'index.md'), join(source, 'index.md'))

  writeFileSync(
    join(machinery, 'descriptor.json'),
    `${JSON.stringify(
      {
        ...archive,
        base: archiveBase(archive),
        // Named rather than numbered. `release.ts` reads the version from the
        // changelog and is TypeScript, which this file cannot import without
        // depending on Node's type stripping; and a label baked into an archive
        // at build time would name whichever release last triggered a deploy,
        // which is not a fact about the archive.
        current: {
          label: 'Current documentation',
          base: CURRENT_URL,
          routes: currentRoutes(),
        },
      },
      null,
      2,
    )}\n`,
  )

  // Vite resolves a bare import by climbing from the file that made it, and
  // `.vitepress/` is a sibling of the content rather than an ancestor of it.
  // The same link `postinstall.mjs` makes for the main site, for the same
  // reason (docs/.vitepress/postinstall.mjs).
  symlinkSync(join(vitepress, 'node_modules'), join(source, 'node_modules'), 'junction')
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

  install(source, archive)
  build(source)

  cpSync(join(source, '.vitepress', 'dist'), join(dist, archive.prefix), { recursive: true })

  console.log(`versions: built the ${archive.line} archive from ${archive.tag} at /${archive.prefix}/`)
}

// The extracted trees are inside dist/ only because it is the directory this
// script already owns, and they are not part of what gets published.
rmSync(join(dist, '.archives'), { recursive: true, force: true })
