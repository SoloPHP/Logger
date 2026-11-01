import { defineConfig } from 'vitepress'

export default defineConfig({
  title: 'Solo Logger',
  description: 'PSR-3 logger for PHP: coordinated file rotation, standard error, bounded context, line, JSON and logfmt output',
  base: '/Logger/',

  head: [
    ['link', { rel: 'icon', type: 'image/svg+xml', href: '/Logger/logo.svg' }],
    ['meta', { name: 'theme-color', content: '#14b8a6' }],
    ['meta', { property: 'og:type', content: 'website' }],
    ['meta', { property: 'og:title', content: 'Solo Logger' }],
    ['meta', { property: 'og:description', content: 'PSR-3 logger for PHP: coordinated file rotation, standard error, bounded context, line, JSON and logfmt output' }],
  ],

  themeConfig: {
    logo: '/logo.svg',

    nav: [
      { text: 'Guide', link: '/guide/installation' },
      { text: 'Features', link: '/features/rotation' },
      { text: 'API', link: '/api/logger' },
      { text: 'v2.0', link: 'https://github.com/SoloPHP/Logger/releases' },
      {
        text: 'Links',
        items: [
          { text: 'GitHub', link: 'https://github.com/SoloPHP/Logger' },
          { text: 'Packagist', link: 'https://packagist.org/packages/solophp/logger' },
          { text: 'SoloPHP', link: 'https://github.com/SoloPHP' }
        ]
      }
    ],

    sidebar: [
      {
        text: 'Getting Started',
        items: [
          { text: 'Installation', link: '/guide/installation' },
          { text: 'Quick Start', link: '/guide/quick-start' },
          { text: 'Upgrading from 1.x', link: 'https://github.com/SoloPHP/Logger/blob/main/UPGRADE.md' }
        ]
      },
      {
        text: 'Features',
        items: [
          { text: 'Log Rotation', link: '/features/rotation' },
          { text: 'Formatters', link: '/features/formatters' },
          { text: 'Context', link: '/features/context' },
          { text: 'Streams', link: '/features/streams' },
          { text: 'Decorators', link: '/features/decorators' },
          { text: 'Extending', link: '/features/extending' }
        ]
      },
      {
        text: 'API Reference',
        items: [
          { text: 'Logger', link: '/api/logger' },
          { text: 'Decorators', link: '/api/decorators' },
          { text: 'Formatters', link: '/api/formatters' },
          { text: 'Writers', link: '/api/writers' }
        ]
      }
    ],

    socialLinks: [
      { icon: 'github', link: 'https://github.com/SoloPHP/Logger' }
    ],

    footer: {
      message: 'Released under the MIT License.',
      copyright: `Copyright © 2024-${new Date().getFullYear()} SoloPHP`
    },

    search: {
      provider: 'local'
    },

    editLink: {
      pattern: 'https://github.com/SoloPHP/Logger/edit/main/docs/:path',
      text: 'Edit this page on GitHub'
    }
  }
})
