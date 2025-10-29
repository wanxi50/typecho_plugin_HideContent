import { readFile, writeFile, mkdir, cp, rm } from 'node:fs/promises';
import { existsSync } from 'node:fs';
import path from 'node:path';
import esbuild from 'esbuild';
import postcss from 'postcss';
import autoprefixer from 'autoprefixer';
import cssnano from 'cssnano';
import bestzip from 'bestzip';

const root = path.resolve(process.cwd());
const srcAssets = path.join(root, 'assets');
const distDir = path.join(root, 'dist');
const distPluginDir = path.join(distDir, 'HideContent');

async function ensureDir(dir) {
  if (!existsSync(dir)) {
    await mkdir(dir, { recursive: true });
  }
}

async function getPluginVersion() {
  const pluginPhp = path.join(root, 'Plugin.php');
  try {
    const text = await readFile(pluginPhp, 'utf8');
    // 从头部注释读取 @version x.y.z
    const m = text.match(/@version\s+([0-9]+\.[0-9]+\.[0-9]+)/i);
    return m ? m[1] : '0.0.0';
  } catch {
    return '0.0.0';
  }
}

async function buildJS() {
  const entries = [
    { in: path.join(srcAssets, 'decrypt.js'), out: path.join(srcAssets, 'decrypt.min.js') },
    { in: path.join(srcAssets, 'editor.js'), out: path.join(srcAssets, 'editor.min.js') }
  ];
  for (const { in: input, out } of entries) {
    if (!existsSync(input)) continue;
    await esbuild.build({
      entryPoints: [input],
      outfile: out,
      minify: true,
      bundle: false,
      target: ['es2018'],
      legalComments: 'none',
      charset: 'utf8',
      logLevel: 'info'
    });
  }
}

async function buildCSS() {
  const cssFile = path.join(srcAssets, 'hide-content.css');
  if (!existsSync(cssFile)) return;
  const css = await readFile(cssFile, 'utf8');
  const result = await postcss([autoprefixer(), cssnano({ preset: 'default' })]).process(css, { from: cssFile });
  const outFile = path.join(srcAssets, 'hide-content.min.css');
  await writeFile(outFile, result.css, 'utf8');
}

async function copyForZip() {
  await ensureDir(distPluginDir);
  // 清空 dist/HideContent
  if (existsSync(distPluginDir)) {
    await rm(distPluginDir, { recursive: true, force: true });
    await ensureDir(distPluginDir);
  }
  const filesToCopy = [
    'Action.php',
    'Config.php',
    'Plugin.php',
    'README.md',
    'LICENSE'
  ];
  for (const f of filesToCopy) {
    const src = path.join(root, f);
    if (existsSync(src)) {
      await cp(src, path.join(distPluginDir, f));
    }
  }
  // 仅复制 .min 版本的前端资源到 assets 目录
  const distAssets = path.join(distPluginDir, 'assets');
  await ensureDir(distAssets);
  const minFiles = [
    'decrypt.min.js',
    'editor.min.js',
    'hide-content.min.css'
  ];
  for (const f of minFiles) {
    const src = path.join(srcAssets, f);
    if (existsSync(src)) {
      await cp(src, path.join(distAssets, f));
    }
  }
}

async function zipDist() {
  const version = await getPluginVersion();
  const zipName = `HideContent-v${version}.zip`;
  await bestzip({
    source: 'HideContent/**',
    destination: zipName,
    cwd: distDir
  });
  return zipName;
}

async function main() {
  const mode = process.argv[2] || 'all';
  if (mode === 'js' || mode === 'all') {
    await buildJS();
  }
  if (mode === 'css' || mode === 'all') {
    await buildCSS();
  }
  if (mode === 'zip' || mode === 'all-zip') {
    await copyForZip();
    const name = await zipDist();
    console.log(`\nCreated dist/${name}`);
  }
  if (mode === 'all') {
    await copyForZip();
    const name = await zipDist();
    console.log(`\nBuild complete. Package: dist/${name}`);
  }
}

main().catch((e) => {
  console.error(e);
  process.exit(1);
});


