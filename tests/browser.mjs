// Testes do front-end (tpuk.js) em Chrome headless, via CDP, sem dependências (Node >= 22).
// Uso: node tests/browser.mjs <cenário>   -> escreve uma linha (o id da resposta usada; run-all.sh verifica no WordPress)
import { spawn } from 'node:child_process';
import { mkdtempSync, readFileSync, rmSync, existsSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { randomBytes } from 'node:crypto';

const SITE = process.env.TPUK_SITE || 'http://localhost:8088';
const PAGE = SITE + '/teste-typeform/';
const CHROME = process.env.CHROME || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const rid = (tag) => tag + Date.now().toString(36) + randomBytes(4).toString('hex'); // só letras e números

async function chrome() {
  const dir = mkdtempSync(join(tmpdir(), 'tpuk-chrome-'));
  const proc = spawn(CHROME, ['--headless=new', '--remote-debugging-port=0', '--user-data-dir=' + dir,
    '--no-first-run', '--no-default-browser-check', 'about:blank'], { stdio: 'ignore' });
  const portFile = join(dir, 'DevToolsActivePort');
  for (let i = 0; i < 100 && !existsSync(portFile); i++) await sleep(100);
  const port = readFileSync(portFile, 'utf8').split('\n')[0];
  const close = () => { proc.kill(); setTimeout(() => rmSync(dir, { recursive: true, force: true }), 500); };
  return { port, close };
}

// Abre um separador e devolve eval(expr), go(url) e o fecho.
async function tab(port, url) {
  const t = await (await fetch(`http://127.0.0.1:${port}/json/new?about:blank`, { method: 'PUT' })).json();
  const ws = new WebSocket(t.webSocketDebuggerUrl);
  await new Promise((r) => ws.addEventListener('open', r, { once: true }));
  let id = 0; const waiting = new Map(); const loaded = [];
  ws.addEventListener('message', (m) => {
    const d = JSON.parse(m.data);
    if (d.id && waiting.has(d.id)) { waiting.get(d.id)(d); waiting.delete(d.id); }
    if (d.method === 'Page.loadEventFired') loaded.splice(0).forEach((f) => f());
  });
  const send = (method, params = {}) => new Promise((r) => { id++; waiting.set(id, r); ws.send(JSON.stringify({ id, method, params })); });
  await send('Page.enable');
  const go = async (u) => { const l = new Promise((r) => loaded.push(r)); await send('Page.navigate', { url: u }); await l; };
  await go(url);
  const evaluate = async (expression) => {
    const r = await send('Runtime.evaluate', { expression, awaitPromise: true, returnByValue: true });
    if (r.result.exceptionDetails) throw new Error(JSON.stringify(r.result.exceptionDetails));
    return r.result.result.value;
  };
  return { evaluate, go, close: () => ws.close() };
}

// O que o iframe do Typeform envia à página quando o formulário é submetido.
const SUBMIT = (formId, r) => `window.dispatchEvent(new MessageEvent('message', { origin: 'https://form.typeform.com', data: { type: 'form-submit', formId: '${formId}', responseId: '${r}' } }))`;

// Escolha no banner do Complianz, antes de a página do plugin correr (cmplz_marketing / cmplz_statistics).
const CONSENT = (v) => `document.cookie = 'cmplz_marketing=${v}; path=/'; document.cookie = 'cmplz_statistics=${v}; path=/';`;

const scenarios = {
  // Marketing aceite; landing com cliques do Google, Microsoft e Meta; o questionário é submetido nessa página.
  async landing(port) {
    const r = rid('rland');
    const t = await tab(port, SITE + '/');
    await t.evaluate(CONSENT('allow'));
    await t.go(PAGE + '?gclid=GBROWSER&msclkid=MSBROWSER&fbclid=FBBROWSER');
    await t.evaluate(SUBMIT('mockmain01', r));
    await sleep(2000);
    t.close();
    return r;
  },
  // Marketing aceite; chega com gclid, navega para outra página do site e só depois submete o Typeform.
  async 'navega-antes'(port) {
    const r = rid('rnav');
    const t = await tab(port, SITE + '/');
    await t.evaluate(CONSENT('allow'));
    await t.go(PAGE + '?gclid=GNAV');
    await t.go(SITE + '/');
    await t.evaluate(SUBMIT('mockmain01', r));
    await sleep(2000);
    t.close();
    return r;
  },
  // Chega com gclid sem ter respondido ao banner; aceita depois (evento do Complianz) e muda de página.
  async 'aceita-depois'(port) {
    const r = rid('rlate');
    const t = await tab(port, PAGE + '?gclid=GLATE');
    await t.evaluate(CONSENT('allow') + "document.dispatchEvent(new CustomEvent('cmplz_status_change'));");
    await t.go(SITE + '/');
    await t.evaluate(SUBMIT('mockmain01', r));
    await sleep(2000);
    t.close();
    return r;
  },
  // Marketing recusado: só vai o estado do consentimento.
  async recusou(port) {
    const r = rid('rdeny');
    const t = await tab(port, SITE + '/');
    await t.evaluate(CONSENT('deny'));
    await t.go(PAGE + '?gclid=GDENY&fbclid=FBDENY');
    await t.evaluate(SUBMIT('mockmain01', r));
    await sleep(2000);
    t.close();
    return r;
  },
  // Marketing recusado: o cookie com os cliques (tpuk_attr) não é criado.
  async 'recusou-cookie'(port) {
    const t = await tab(port, SITE + '/');
    await t.evaluate(CONSENT('deny'));
    await t.go(PAGE + '?gclid=GDENY');
    const has = await t.evaluate("document.cookie.indexOf('tpuk_attr=') !== -1");
    t.close();
    return has ? 'cookie criado' : 'sem cookie';
  },
  // Banner por responder e sem cliques: vai só o estado "unknown".
  async 'sem-banner'(port) {
    const r = rid('rnone');
    const t = await tab(port, PAGE + '?gclid=GNONE');
    await t.evaluate(SUBMIT('mockmain01', r));
    await sleep(2000);
    t.close();
    return r;
  },
};

const name = process.argv[2];
if (!scenarios[name]) { console.log('cenário desconhecido: ' + name); process.exit(2); }
const { port, close } = await chrome();
try { console.log(await scenarios[name](port)); } catch (e) { console.log('erro: ' + e.message); }
finally { close(); }
