import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

/*
 * Preline's per-plugin builds (preline/plugins/*) only register window.HS* globals and export
 * nothing, but the package marks them side-effect free, so the bundler would drop them.
 * Keep them (docs/07 D-12).
 */
const prelinePlugins = {
    name: 'woodex:preline-plugins',
    enforce: 'pre',
    async resolveId(source, importer, options) {
        if (!source.startsWith('preline/plugins/')) {
            return null;
        }

        const resolved = await this.resolve(source, importer, { ...options, skipSelf: true });

        return resolved ? { ...resolved, moduleSideEffects: true } : null;
    },
};

/*
 * Assets are built locally or in GitHub Actions and shipped in public/build.
 * Hostinger never runs Node (docs/07 D-10).
 */
export default defineConfig({
    plugins: [
        prelinePlugins,
        laravel({
            input: [
                'resources/css/admin.css',
                'resources/js/admin.js',
                'resources/js/auth.js',
                'resources/css/site.css',
                'resources/js/site.js',
            ],
            refresh: true,
        }),
        tailwindcss(),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
    build: {
        rollupOptions: {
            output: {
                /*
                 * The public site's home page is a static file (docs/audit/16.19): public/index.html
                 * links its stylesheet and script by name, and no Blade template can rewrite that link
                 * after a build. A content hash would break the home page the first time the assets are
                 * rebuilt, so the site entry keeps stable names. Every other asset keeps its hash, and
                 * the admin keeps its hashes too — Blade reads the manifest for those.
                 */
                entryFileNames: (chunk) => {
                    // The site's JS entry only; the CSS entry is emitted through assetFileNames below.
                    return (chunk.facadeModuleId ?? '').endsWith('/resources/js/site.js')
                        ? 'assets/site.js'
                        : 'assets/[name]-[hash].js';
                },
                assetFileNames: (asset) => {
                    const name = asset.names?.[0] ?? asset.name ?? '';

                    if (name === 'site.css') {
                        return 'assets/site.css';
                    }

                    return 'assets/[name]-[hash][extname]';
                },
            },
        },
    },
});
