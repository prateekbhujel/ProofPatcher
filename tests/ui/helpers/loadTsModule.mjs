import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { transformSync } from 'esbuild';

/** Load a dependency-free TS/TSX module so naive single-file test loaders can stub it. */
export function loadTsModule(pathUrl) {
  const source = readFileSync(pathUrl, 'utf8');
  const { code } = transformSync(source, { loader: String(pathUrl).endsWith('.ts') ? 'ts' : 'tsx', format: 'cjs' });
  const mod = { exports: {} };
  new Function('exports', 'require', 'module', code)(mod.exports, createRequire(pathUrl), mod);
  return mod.exports;
}
