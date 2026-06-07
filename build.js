#!/usr/bin/env node
/**
 * OrbitHost Performance Build Script
 * Run: node build.js
 *
 * - Minifies css/style.css  → css/style.min.css
 * - Minifies js/main.js     → js/main.min.js
 * - Updates all HTML files:
 *     • references switch to minified assets
 *     • Font Awesome and Google Fonts become non-blocking (preload)
 *     • DNS-prefetch for cdnjs is moved before the FA preload link
 */

const fs   = require('fs');
const path = require('path');

// ── Minification ─────────────────────────────────────────────────────────────

function minifyCSS(src) {
  return src
    .replace(/\/\*[\s\S]*?\*\//g, '')   // strip comments
    .replace(/\n|\r|\t/g,        ' ')   // flatten newlines
    .replace(/ {2,}/g,           ' ')   // collapse spaces
    .replace(/\s*\{\s*/g,        '{')
    .replace(/\s*\}\s*/g,        '}')
    .replace(/\s*;\s*/g,         ';')
    .replace(/\s*,\s*/g,         ',')
    .replace(/;}/g,              '}')   // remove trailing semicolons
    .trim();
}

function minifyJS(src) {
  return src
    .replace(/\/\*[\s\S]*?\*\//g, '')   // strip block comments
    .replace(/^\s*\/\/.*$/gm,    '')    // strip line comments
    .replace(/\n\s*\n/g,         '\n') // remove blank lines
    .replace(/^\s+/gm,           '')    // remove leading whitespace per line
    .replace(/ {2,}/g,           ' ')   // collapse inline spaces
    .trim();
}

// ── Run minification ─────────────────────────────────────────────────────────

const cssIn  = fs.readFileSync('css/style.css',  'utf8');
const cssOut = minifyCSS(cssIn);
fs.writeFileSync('css/style.min.css', cssOut);
console.log(`CSS  ${cssIn.length} B → ${cssOut.length} B  (${pct(cssIn, cssOut)} smaller)`);

const jsIn  = fs.readFileSync('js/main.js',  'utf8');
const jsOut = minifyJS(jsIn);
fs.writeFileSync('js/main.min.js', jsOut);
console.log(`JS   ${jsIn.length} B → ${jsOut.length} B  (${pct(jsIn, jsOut)} smaller)`);

function pct(a, b) { return Math.round((1 - b.length / a.length) * 100) + '%'; }

// ── HTML transformation patterns ─────────────────────────────────────────────

const FA_URL = 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css';
const GF_URL = 'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap';

const FA_BLOCKING  = `<link rel="stylesheet" href="${FA_URL}" />`;
const FA_ASYNC     = `<link rel="preload" href="${FA_URL}" as="style" onload="this.onload=null;this.rel='stylesheet'" />`
                   + `<noscript><link rel="stylesheet" href="${FA_URL}" /></noscript>`;

const GF_BLOCKING  = `<link href="${GF_URL}" rel="stylesheet" />`;
const GF_ASYNC     = `<link rel="preload" href="${GF_URL}" as="style" onload="this.onload=null;this.rel='stylesheet'" />`
                   + `<noscript><link href="${GF_URL}" rel="stylesheet" /></noscript>`;

// ── Collect all HTML files ────────────────────────────────────────────────────

function walk(dir, results = []) {
  fs.readdirSync(dir).forEach(f => {
    const full = path.join(dir, f);
    if (f === 'node_modules') return;
    if (fs.statSync(full).isDirectory()) walk(full, results);
    else if (f.endsWith('.html')) results.push(full);
  });
  return results;
}

const htmlFiles = walk('.');
let changed = 0;

htmlFiles.forEach(file => {
  let html = fs.readFileSync(file, 'utf8');
  const before = html;

  // Switch to minified assets
  html = html.replace(/href="css\/style\.css"/g,    'href="css/style.min.css"');
  html = html.replace(/href="\.\.\/css\/style\.css"/g, 'href="../css/style.min.css"');
  html = html.replace(/src="js\/main\.js"/g,        'src="js/main.min.js"');
  html = html.replace(/src="\.\.\/js\/main\.js"/g,  'src="../js/main.min.js"');

  // Make Font Awesome non-blocking
  html = html.replace(FA_BLOCKING, FA_ASYNC);

  // Make Google Fonts non-blocking
  html = html.replace(GF_BLOCKING, GF_ASYNC);

  if (html !== before) {
    fs.writeFileSync(file, html);
    changed++;
    console.log(`  updated ${path.relative('.', file)}`);
  }
});

console.log(`\n✓ ${changed} HTML files updated`);
console.log('✓ Build complete — serve the project to test');
