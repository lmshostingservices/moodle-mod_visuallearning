import {build} from 'esbuild';
import {fileURLToPath} from 'node:url';
const entry=fileURLToPath(new URL('./preview-entry.mjs',import.meta.url));
const outfile=fileURLToPath(new URL('../generated-artifacts/svg-showcase/engine-preview.js',import.meta.url));
await build({entryPoints:[entry],outfile,bundle:true,format:'iife',minify:false});
console.log('Bundled actual scene renderer and actual evaluator into benchmark-only harness.');
