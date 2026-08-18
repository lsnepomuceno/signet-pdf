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
 */
export default { extends: DefaultTheme } satisfies Theme
