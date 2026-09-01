import { execFileSync } from 'node:child_process'

/**
 * Which release lines are archived, and what each one contains.
 *
 * Read by two things that must not disagree: `versions.mjs`, which extracts a
 * tag and builds the archive, and `config.mts`, which puts the version control
 * in the navigation of the current site. It is `.mjs` rather than `.ts` because
 * `versions.mjs` runs under plain Node and Vite compiles neither for it
 * (docs/decisions/0112-the-site-documents-one-release-line.md).
 */

/**
 * @typedef {object} Archive
 * @property {string} prefix  The path segment it is published under, `v1`.
 * @property {string} line    The line it documents, `1.x`.
 * @property {string} tag     The last tag published on it, which is what is extracted.
 * @property {string} title   The site title, which is also the browser tab.
 */

/**
 * Every archived line, newest first. One entry per major release that stopped
 * being current, pinned to the last tag published on it.
 *
 * @type {Archive[]}
 */
export const ARCHIVES = [
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
export const CURRENT_URL = 'https://lsnepomuceno.github.io/signet-pdf/'

/** The base an archive is served from, which is the current base plus its prefix. */
export function archiveBase(archive) {
  return `/signet-pdf/${archive.prefix}/`
}

/**
 * Every page the tag carries, as a route without its extension.
 *
 * This is what makes switching version keep the page rather than land on the
 * archive's front door: the control links to the same route when the archive
 * has it, and to the archive's home page when it does not.
 *
 * `git ls-tree` reads the object database and touches neither the working copy
 * nor the index, so it is safe to run from the repository somebody is working
 * in. **`git archive` cannot do it**, which is worth writing down because it is
 * the obvious first attempt: `.gitattributes` marks `/docs export-ignore` so the
 * documentation stays out of the package a consumer installs, and `git archive`
 * honours that, succeeding with an empty tar.
 *
 * @param {Archive} archive
 * @param {string} repository  The repository root.
 * @returns {string[]}
 */
export function archiveRoutes(archive, repository) {
  const listed = execFileSync('git', ['ls-tree', '-r', '--name-only', archive.tag, '--', 'docs'], {
    cwd: repository,
    encoding: 'utf-8',
  })

  return listed
    .split('\n')
    .filter(name => name.endsWith('.md'))
    .map(name => name.replace(/^docs\//, '').replace(/\.md$/, ''))
}

/**
 * The same list, or an empty one when the tag cannot be read.
 *
 * The current site's configuration wants these and does not depend on them: a
 * checkout with no tags still builds, and the version control falls back to the
 * archive's home page, which is what it did before it knew any routes. The
 * build that actually produces an archive uses `archiveRoutes` directly and
 * fails loudly instead, because there it is the whole input.
 *
 * @param {Archive} archive
 * @param {string} repository
 * @returns {string[]}
 */
export function archiveRoutesOrNone(archive, repository) {
  try {
    return archiveRoutes(archive, repository)
  } catch {
    console.warn(
      `versions: ${archive.tag} is not in this checkout, so the version control cannot keep the page`,
    )

    return []
  }
}
