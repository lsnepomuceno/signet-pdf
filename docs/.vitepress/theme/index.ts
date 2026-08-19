import DefaultTheme from 'vitepress/theme'
import type { Theme } from 'vitepress'
import './custom.css'

/**
 * The default theme, restyled through its own custom properties.
 *
 * Nothing is copied out of it and nothing is replaced. A theme that forks the
 * components has to reconcile every VitePress release by hand, which is the
 * cost the monochrome themes on npm carry; overriding tokens survives an
 * upgrade because it never touches what the upgrade changes.
 *
 * There was a release banner here, filled into the `layout-top` slot, and it is
 * gone: the version switcher in the navigation names the line on every page and
 * carries the link to the archive, so the banner said the same thing twice and
 * took a strip of every screen to do it
 * (docs/decisions/0112-the-site-documents-one-release-line.md).
 */
export default { extends: DefaultTheme } satisfies Theme
