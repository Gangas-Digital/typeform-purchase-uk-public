/*
 * Typeform Purchase UK (Arménio Ganga - Dronline). Versão em window.tpuk.version.
 * Guarda os cliques da visita (gclid, gbraid, wbraid, msclkid, fbclid/_fbc, _fbp, _ga, UTMs). Quando um Typeform
 * incorporado no site é submetido, envia-os ao WordPress com o id da resposta. O WordPress lê essa resposta na API
 * do Typeform e liga os cliques ao email: a compra paga depois (mesmo noutro Typeform) leva-os para o sGTM.
 * Consentimento (Complianz, opt-in): os cliques só são guardados e enviados com o marketing aceite; o _ga só
 * com as estatísticas aceites. O estado do consentimento vai sempre, para o sGTM decidir.
 * Não faz dataLayer nem altera os formulários.
 */
(function () {
  'use strict';

  var cfg = window.TPUK || {};
  var URL_KEYS = ['gclid', 'gbraid', 'wbraid', 'msclkid', 'fbclid', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'];
  var MARKETING_KEYS = ['gclid', 'gbraid', 'wbraid', 'msclkid', 'fbc', 'fbp'];
  var STATISTICS_KEYS = ['ga_client_id', 'ga_session_id'];
  var UTM_KEYS = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'];
  var STORE = 'tpuk_attr';
  var DAYS = 90;
  var RID_RE = /^[A-Za-z0-9]{8,64}$/;

  function getCookie(name) {
    var m = document.cookie.match(new RegExp('(?:^|; )' + name.replace(/[.$?*|{}()[\]\\/+^]/g, '\\$&') + '=([^;]*)'));
    return m ? decodeURIComponent(m[1]) : '';
  }

  function setCookie(name, value) {
    var exp = new Date(Date.now() + DAYS * 864e5).toUTCString();
    document.cookie = name + '=' + encodeURIComponent(value) + '; expires=' + exp + '; path=/; SameSite=Lax';
  }

  var qs = new URLSearchParams(window.location.search);
  var landing = {}; // cliques do URL desta página, em memória até haver consentimento para os guardar
  URL_KEYS.forEach(function (k) { if (qs.get(k)) landing[k] = qs.get(k); });
  if (landing.fbclid) landing.fbclid_t = Date.now();

  // Escolha da pessoa no banner do Complianz (cmplz_<categoria> = allow | deny; sem cookie = ainda não escolheu).
  function consent(category) {
    var v = getCookie('cmplz_' + category);
    return v === 'allow' ? 'granted' : (v ? 'denied' : 'unknown');
  }

  // Guarda os cliques/UTMs da landing (só com o marketing aceite) para não se perderem quando o utilizador
  // navega antes do formulário.
  function storedAttribution() {
    var saved = {};
    try { saved = JSON.parse(getCookie(STORE) || '{}'); } catch (e) { saved = {}; }
    if (Object.keys(landing).length) {
      saved = landing; // novo clique substitui a atribuição anterior
      if (consent('marketing') === 'granted') setCookie(STORE, JSON.stringify(saved));
    }
    return saved;
  }

  function gaClientId() {
    // _ga = GA1.1.123456789.1700000000 -> 123456789.1700000000
    var parts = getCookie('_ga').split('.');
    return parts.length >= 4 ? parts.slice(-2).join('.') : '';
  }

  function gaSessionId() {
    if (!cfg.measurementId) return '';
    var v = getCookie('_ga_' + cfg.measurementId.replace(/^G-/, ''));
    if (!v) return '';
    if (v.indexOf('GS2') === 0) {                 // GS2.1.s1700000000$o3$g1...
      var m = v.match(/[.$]s(\d+)/);
      return m ? m[1] : '';
    }
    var p = v.split('.');                         // GS1.1.1700000000.3.1...
    return p.length > 2 ? p[2] : '';
  }

  function gclidFromCookie() {
    // _gcl_aw = GCL.1700000000.<gclid>
    var p = getCookie('_gcl_aw').split('.');
    return p.length >= 3 ? p.slice(2).join('.') : '';
  }

  function msclkidFromCookie() {
    // _uetmsclkid = _uet<msclkid> (UET do Microsoft Ads)
    return getCookie('_uetmsclkid').replace(/^_uet/, '');
  }

  function fbc(v) {
    // _fbc = fb.1.<timestamp ms>.<fbclid> (pixel da Meta); sem cookie, monta-o a partir do fbclid da landing
    var c = getCookie('_fbc');
    if (c) return c;
    return v.fbclid ? 'fb.1.' + (v.fbclid_t || Date.now()) + '.' + v.fbclid : '';
  }

  function attribution() {
    var marketing = consent('marketing');
    var statistics = consent('statistics');
    var v = storedAttribution();
    var keys = UTM_KEYS.slice();
    if (marketing === 'granted') {
      if (!v.gclid) v.gclid = gclidFromCookie();
      if (!v.msclkid) v.msclkid = msclkidFromCookie();
      v.fbc = fbc(v);
      v.fbp = getCookie('_fbp');
      keys = keys.concat(MARKETING_KEYS);
    }
    if (statistics === 'granted') {
      v.ga_client_id = gaClientId();
      v.ga_session_id = gaSessionId();
      keys = keys.concat(STATISTICS_KEYS);
    }
    var out = { consent_marketing: marketing, consent_statistics: statistics };
    keys.forEach(function (k) { if (v[k]) out[k] = String(v[k]).slice(0, 250); });
    return out;
  }

  // Id do formulário pelo iframe que enviou a mensagem (quando a mensagem não o traz).
  function formIdFrom(source) {
    var found = '';
    document.querySelectorAll('iframe').forEach(function (f) {
      if (!found && f.contentWindow === source) {
        var m = (f.getAttribute('src') || '').match(/\/to\/([A-Za-z0-9]+)/);
        found = m ? m[1] : '';
      }
    });
    return found;
  }

  function sendVisit(formId, rid) {
    var attr = attribution(); // leva sempre o estado do consentimento, mesmo sem cliques
    if (!cfg.visitUrl) return;
    var body = JSON.stringify({ form_id: formId, response_id: rid, attr: attr });
    // sendBeacon: chega mesmo que o Typeform redirecione logo a seguir.
    try {
      if (navigator.sendBeacon && navigator.sendBeacon(cfg.visitUrl, new Blob([body], { type: 'application/json' }))) return;
    } catch (e) { /* segue para o fetch */ }
    fetch(cfg.visitUrl, { method: 'POST', credentials: 'same-origin', keepalive: true, headers: { 'Content-Type': 'application/json' }, body: body })
      .catch(function () { /* sem rede: a compra segue sem estes cliques */ });
  }

  // Sinal de submissão vindo do iframe do Typeform.
  window.addEventListener('message', function (e) {
    var host;
    try { host = new URL(e.origin).hostname; } catch (err) { return; }
    if (!/(^|\.)typeform\.com$/.test(host)) return;
    var d = e.data;
    if (typeof d === 'string') { try { d = JSON.parse(d); } catch (err) { return; } }
    if (!d || (d.type !== 'form-submit' && d.event !== 'form-submit')) return;
    var formId = String(d.formId || (d.data && d.data.formId) || formIdFrom(e.source) || '');
    var rid = String(d.responseId || (d.data && d.data.responseId) || '');
    if (formId && RID_RE.test(rid)) sendVisit(formId, rid);
  });

  storedAttribution(); // guarda já os cliques da landing, se o marketing já estiver aceite
  // A pessoa aceita o banner depois de chegar: guarda então os cliques desta landing.
  document.addEventListener('cmplz_status_change', storedAttribution);
  document.addEventListener('cmplz_fire_categories', storedAttribution);
  window.tpuk = { version: cfg.version || '', attribution: attribution };
})();
