#!/usr/bin/env node
/**
 * Freemius launch operations for WP MCP (product 34955).
 *
 * Zero-dependency (Node 18+). Auth is Freemius FS-HMAC (product scope):
 *   Authorization: FS {productId}:{publicKey}:{base64url(hmac_sha256_hex(secretKey, stringToSign))}
 *   stringToSign = method\ncontentMd5\ncontentType\ndate\nresourcePath
 *
 * Config via .env.freemius next to this repo root (gitignored) or env:
 *   FREEMIUS_PRODUCT_ID=34955
 *   FREEMIUS_PRODUCT_PUBLIC_KEY=pk_...
 *   FREEMIUS_PRODUCT_SECRET_KEY=sk_...
 *
 * Usage:
 *   node scripts/freemius-ops.mjs status                 # plans, pricing, deployed tags
 *   node scripts/freemius-ops.mjs add-lifetime           # launch-window lifetime prices
 *   node scripts/freemius-ops.mjs deploy dist/wpmcp-X.zip
 *   node scripts/freemius-ops.mjs release <tag_id>
 */
import { createHmac, createHash, randomBytes } from 'node:crypto';
import { readFileSync, existsSync } from 'node:fs';
import { basename, dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..');
const ENV_FILE = join(ROOT, '.env.freemius');
if (existsSync(ENV_FILE)) {
  for (const line of readFileSync(ENV_FILE, 'utf8').split('\n')) {
    const m = line.match(/^\s*([A-Z0-9_]+)\s*=\s*(.*)\s*$/);
    if (m && !(m[1] in process.env)) process.env[m[1]] = m[2].replace(/^["']|["']$/g, '');
  }
}

const PRODUCT_ID = process.env.FREEMIUS_PRODUCT_ID ?? '34955';
const PUBLIC_KEY = process.env.FREEMIUS_PRODUCT_PUBLIC_KEY ?? 'pk_198c5294157bf7068fd2ffd493957';
const SECRET_KEY = process.env.FREEMIUS_PRODUCT_SECRET_KEY;
if (!SECRET_KEY) {
  console.error(`Missing FREEMIUS_PRODUCT_SECRET_KEY (set it in ${ENV_FILE})`);
  process.exit(1);
}

const API = 'https://api.freemius.com';
const PRO_PLAN_ID = '57477';
/* pricing rows on the Pro plan (from the dashboard, 2026-07-19) */
const PRICING = { 1: '77437', 10: '77465', 100: '77466', unlimited: '77467' };

const base64Url = (s) =>
  Buffer.from(s).toString('base64').replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');

function authHeaders(method, resourcePath, contentMd5 = '', contentType = '') {
  const date = new Date().toUTCString();
  const stringToSign = [method, contentMd5, contentType, date, resourcePath].join('\n');
  const signature = createHmac('sha256', SECRET_KEY).update(stringToSign).digest('hex');
  const headers = {
    Date: date,
    Authorization: `FS ${PRODUCT_ID}:${PUBLIC_KEY}:${base64Url(signature)}`,
    Accept: 'application/json',
  };
  if (contentType) headers['Content-Type'] = contentType;
  if (contentMd5) headers['Content-MD5'] = contentMd5;
  return headers;
}

async function call(method, resourcePath, body = null, contentType = '') {
  let payload = null;
  let contentMd5 = '';
  if (body !== null) {
    payload = contentType === 'application/json' ? JSON.stringify(body) : body;
    contentMd5 = createHash('md5')
      .update(typeof payload === 'string' ? payload : Buffer.from(payload))
      .digest('hex');
  }
  const res = await fetch(`${API}${resourcePath}`, {
    method,
    headers: authHeaders(method, resourcePath, contentMd5, contentType),
    body: payload,
  });
  const text = await res.text();
  if (!res.ok) throw new Error(`Freemius ${res.status} ${method} ${resourcePath}: ${text.slice(0, 400)}`);
  return text ? JSON.parse(text) : null;
}

const get = (p) => call('GET', p);
const put = (p, body) => call('PUT', p, body, 'application/json');

const base = `/v1/products/${PRODUCT_ID}`;

async function status() {
  const plans = await get(`${base}/plans.json`);
  console.log('== plans ==');
  for (const plan of plans.plans ?? []) {
    console.log(`plan ${plan.id} "${plan.name}"`);
    const pricing = await get(`${base}/plans/${plan.id}/pricing.json`);
    for (const p of pricing.pricing ?? []) {
      console.log(
        `  pricing ${p.id} licenses=${p.licenses ?? 'unlimited'} annual=${p.annual_price} lifetime=${p.lifetime_price}`
      );
    }
  }
  const tags = await get(`${base}/tags.json?count=10`);
  console.log('== deployed tags ==');
  for (const t of tags.tags ?? []) {
    console.log(`tag ${t.id} v${t.version} release_mode=${t.release_mode}`);
  }
}

async function addLifetime() {
  /* Launch-window lifetime tiers: 1-site $79, unlimited $179. */
  const updates = [
    { id: PRICING[1], lifetime_price: 79 },
    { id: PRICING.unlimited, lifetime_price: 179 },
  ];
  for (const u of updates) {
    const r = await put(`${base}/plans/${PRO_PLAN_ID}/pricing/${u.id}.json`, {
      lifetime_price: u.lifetime_price,
    });
    console.log(`pricing ${u.id}: lifetime_price=${r.lifetime_price}`);
  }
}

async function deploy(zipPath) {
  if (!zipPath || !existsSync(zipPath)) {
    console.error('usage: deploy <path-to-zip>');
    process.exit(1);
  }
  const boundary = '----wpmcp' + randomBytes(12).toString('hex');
  const file = readFileSync(zipPath);
  const head = Buffer.from(
    `--${boundary}\r\nContent-Disposition: form-data; name="file"; filename="${basename(zipPath)}"\r\nContent-Type: application/zip\r\n\r\n`
  );
  const tail = Buffer.from(`\r\n--${boundary}--\r\n`);
  const payload = Buffer.concat([head, file, tail]);
  const contentType = `multipart/form-data; boundary=${boundary}`;
  const tag = await call('POST', `${base}/tags.json`, payload, contentType);
  console.log(JSON.stringify(tag, null, 2));
  console.log(`\ndeployed tag ${tag.id} v${tag.version} (release_mode=${tag.release_mode})`);
  console.log(`release it with: node scripts/freemius-ops.mjs release ${tag.id}`);
}

async function release(tagId) {
  if (!tagId) {
    console.error('usage: release <tag_id>');
    process.exit(1);
  }
  const r = await put(`${base}/tags/${tagId}.json`, { release_mode: 'released' });
  console.log(`tag ${r.id} v${r.version} release_mode=${r.release_mode}`);
}

const [cmd, arg] = process.argv.slice(2);
const commands = { status, 'add-lifetime': addLifetime, deploy, release };
if (!commands[cmd]) {
  console.error(`usage: freemius-ops.mjs <${Object.keys(commands).join('|')}> [arg]`);
  process.exit(1);
}
commands[cmd](arg).catch((e) => {
  console.error(e.message);
  process.exit(1);
});
