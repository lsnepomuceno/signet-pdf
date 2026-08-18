import DefaultTheme from 'vitepress/theme'
import type { Theme } from 'vitepress'
import { useData } from 'vitepress'
import { h } from 'vue'
import './custom.css'

/**
 * The default theme, restyled through its own custom properties.
 *
 * Nothing is copied out of it and nothing is replaced. A theme that forks the
 * components has to reconcile every VitePress release by hand, which is the
 * cost the monochrome themes on npm carry; overriding tokens survives an
 * upgrade because it never touches what the upgrade changes.
 *
 * The one addition is the release banner, and it is filled into a slot the
 * default layout publishes rather than being a component swapped in for one of
 * its own, so the same reasoning holds.
 */

/**
 * The line this build documents, on every page.
 *
 * The site builds off `main` and therefore always describes the next release,
 * which nothing on the page said: a reader who installed an older line was
 * reading pages written against a newer one with no way to tell
 * (docs/decisions/0112-the-site-documents-one-release-line.md).
 *
 * It is a hard requirement rather than a nicety, because it is the whole of
 * what makes documenting one line honest.
 */
const ReleaseBanner = {
  setup() {
    const { theme } = useData()

    return () => {
      const release = theme.value.release

      if (! release) {
        return null
      }

      // An archive says so first, because a reader who arrived from a search
      // result has no other way to know these pages describe a line that is no
      // longer current.
      if (release.archived) {
        return h('div', { class: 'release-banner release-banner--archived' }, [
          h('span', null, `Archived documentation for the ${release.line} line, frozen at ${release.version}.`),
          ' ',
          h('a', { href: '/signet-pdf/' }, 'Go to the current documentation.'),
        ])
      }

      const state = release.released
        ? `Documenting the ${release.line} line, at ${release.version}.`
        : `Documenting the ${release.line} line, ahead of ${release.version}, which is not tagged yet.`

      return h('div', { class: 'release-banner' }, [
        h('span', null, state),
        ' ',
        h('a', { href: '/signet-pdf/v1/' }, 'Reading the 1.x line? Its documentation is archived here.'),
      ])
    }
  },
}

export default {
  extends: DefaultTheme,
  Layout: () => h(DefaultTheme.Layout, null, { 'layout-top': () => h(ReleaseBanner) }),
} satisfies Theme
