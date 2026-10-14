import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import { VitePWA } from 'vite-plugin-pwa'

export default defineConfig({
  plugins: [
    vue(),
    VitePWA({
      registerType: 'autoUpdate',
      includeAssets: ['favicon.png', 'logo-up.png'],
      manifest: {
        name: 'UP Gestão Comercial',
        short_name: 'UP',
        description: 'Pedidos, clientes, metas e financeiro da UP Gestão Comercial',
        lang: 'pt-BR',
        start_url: '/',
        display: 'standalone',
        background_color: '#F2F4F7',
        theme_color: '#0174B3',
        icons: [
          { src: 'icone-192.png', sizes: '192x192', type: 'image/png' },
          { src: 'icone-512.png', sizes: '512x512', type: 'image/png' },
          { src: 'icone-512.png', sizes: '512x512', type: 'image/png', purpose: 'maskable' },
        ],
      },
      workbox: {
        navigateFallback: '/index.html',
        navigateFallbackDenylist: [/^\/api\//],
        globPatterns: ['**/*.{js,css,html,svg,png,woff2}'],
      },
    }),
  ],
  build: {
    outDir: '../public',
    emptyOutDir: false, // public/ também guarda api/ e .htaccess
  },
  server: {
    proxy: { '/api': 'http://127.0.0.1:8080' },
  },
})
