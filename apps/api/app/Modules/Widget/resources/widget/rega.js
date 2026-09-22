/*!
 * Rega storefront widget v1.
 *
 * Loaded by the Rega WordPress plugin, which sets window.RegaContext:
 *   { site, api, script, mode: 'live'|'preview', preview, page: {type, id}, locale,
 *     storeApi, nonce, cartUrl }
 *
 * What it does, in order:
 *   1. Reads the page content from the Rega API (cached, public data).
 *   2. In preview mode, shows the widget only to the store team (a valid preview key).
 *   3. Replaces prices and stock with live values from the store's own Store API, and drops
 *      anything that is not in stock, not on sale where a sale is promised, or whose price
 *      superlative no longer holds.
 *   4. Shows a row of circles, one per section; each opens its own panel. When the shopper
 *      viewed a product of the same type before, one circle compares the two. What was viewed
 *      stays in this browser only.
 *   5. Places itself by the CSS selector set for the shop, or floats at the bottom.
 *   6. Reports anonymous events (packages/event-spec) with navigator.sendBeacon.
 *
 * Everything renders in a shadow root, so the theme's CSS does not leak in and ours does not
 * leak out. Stores can still set --rega-accent, --rega-radius and --rega-surface on .rega-widget.
 * Text is always set with textContent; links and images must be http(s).
 */
(function () {
  'use strict';

  var ctx = window.RegaContext;
  if (!ctx || !ctx.site || !ctx.api || !ctx.page || window.__regaWidget) {
    return;
  }
  window.__regaWidget = true;

  var API = String(ctx.api).replace(/\/+$/, '');
  var STORE_API = ctx.storeApi ? String(ctx.storeApi).replace(/\/?$/, '/') : null;
  var PAGE_TYPE = ctx.page.type === 'content' ? 'content' : 'product';
  var PAGE_ID = String(ctx.page.id || '');
  var ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_';
  var MAX_BATCH = 50;
  var SEEN_KEY = 'rega_seen';
  var MAX_SEEN = 8;
  var CHIP_SLOTS = ['teaser', 'chip_1', 'chip_2', 'chip_3', 'chip_4', 'chip_5', 'chip_6', 'chip_7', 'chip_8'];

  if (!/^[A-Za-z0-9_.:-]{1,64}$/.test(PAGE_ID)) {
    return;
  }

  // ---------------------------------------------------------------- identity

  function randomId(length) {
    var bytes = new Uint8Array(length);
    (window.crypto || window.msCrypto).getRandomValues(bytes);
    var out = '';
    for (var i = 0; i < length; i++) {
      out += ALPHABET[bytes[i] & 63];
    }
    return out;
  }

  function readCookie(name) {
    var match = document.cookie.match(new RegExp('(?:^|; )' + name + '=([^;]*)'));
    return match ? decodeURIComponent(match[1]) : null;
  }

  function writeCookie(name, value, maxAge) {
    document.cookie = name + '=' + encodeURIComponent(value) + '; path=/; max-age=' + maxAge +
      '; SameSite=Lax' + (location.protocol === 'https:' ? '; Secure' : '');
  }

  function storage(kind, key, fallback) {
    try {
      var store = window[kind];
      var value = store.getItem(key);
      if (!value) {
        value = fallback();
        store.setItem(key, value);
      }
      return value;
    } catch (e) {
      return null;
    }
  }

  // The plugin reads rega_vid at checkout to link an order to widget use, so it lives in a cookie too.
  var vid = readCookie('rega_vid');
  if (!/^anon-[A-Za-z0-9_-]{16,64}$/.test(vid || '')) {
    vid = storage('localStorage', 'rega_vid', function () { return 'anon-' + randomId(22); }) || 'anon-' + randomId(22);
  }
  writeCookie('rega_vid', vid, 31536000);

  var session = storage('sessionStorage', 'rega_sid', function () { return randomId(22); }) || randomId(22);

  // ---------------------------------------------------------------- preview

  var previewKey = null;
  (function () {
    var fromUrl = null;
    try {
      fromUrl = new URLSearchParams(location.search).get('rega_preview');
    } catch (e) { /* old browser */ }

    if (fromUrl && /^[a-f0-9]{32}$/.test(fromUrl)) {
      writeCookie('rega_preview', fromUrl, 30 * 86400);
    }

    var candidate = ctx.preview || fromUrl || readCookie('rega_preview');
    previewKey = /^[a-f0-9]{32}$/.test(candidate || '') ? candidate : null;
  })();

  var isPreviewMode = ctx.mode === 'preview';

  // ---------------------------------------------------------------- events

  var queue = [];
  var shop = null;
  var bank = null;
  var teamPreview = false;
  var viewed = null;

  function pageContext() {
    var page = { type: PAGE_TYPE, path: (location.pathname || '/').slice(0, 512) };
    if (page.path.charAt(0) !== '/') {
      page.path = '/';
    }
    page[PAGE_TYPE === 'product' ? 'product_id' : 'content_id'] = PAGE_ID;
    return page;
  }

  function eligible() {
    var ids = bank.sections.map(function (s) { return s.candidate; });
    return ids.filter(function (id, i) { return ids.indexOf(id) === i; }).slice(0, 12);
  }

  function track(type, section, slot, data) {
    var event = { id: randomId(22), type: type, ts: Date.now(), page: pageContext() };

    if (section) {
      event.candidate = { id: section.candidate, version: bank.bank_version, model: section.model, slot: slot };
      event.bank_version = bank.bank_version;
      event.eligible = eligible();
      event.bucket = section.candidate === 'compare' ? 'same_family_seen' : 'none';
    }
    if (data) {
      event.data = data;
    }

    queue.push(event);
    if (queue.length >= 20) {
      flush(false);
    }
  }

  function flush(leaving) {
    if (!shop || queue.length === 0) {
      return;
    }

    var url = API + '/widget/' + ctx.site + '/events';

    while (queue.length) {
      var body = JSON.stringify({
        v: 1,
        shop: shop,
        vid: vid,
        session: session,
        sent_at: Date.now(),
        holdout: false,
        preview: teamPreview,
        events: queue.splice(0, MAX_BATCH)
      });

      // text/plain keeps it a simple request: no preflight.
      var sent = false;
      if (leaving && navigator.sendBeacon) {
        try {
          sent = navigator.sendBeacon(url, new Blob([body], { type: 'text/plain' }));
        } catch (e) { sent = false; }
      }
      if (!sent && window.fetch) {
        fetch(url, { method: 'POST', body: body, keepalive: true, mode: 'no-cors', credentials: 'omit', headers: { 'Content-Type': 'text/plain' } })
          .catch(function () {});
      }
    }
  }

  // ---------------------------------------------------------------- the store's own add to cart

  var lastAddAt = 0;

  /** Counts an add to the cart once, whichever of the store's signals fired for it. */
  function storeAdded(quantity) {
    var now = Date.now();
    if (PAGE_TYPE !== 'product' || now - lastAddAt < 1500) {
      return;
    }
    lastAddAt = now;
    track('add_to_cart', null, null, { source: 'page', product_id: PAGE_ID, quantity: quantity, result: 'added' });
    // The form may leave the page right now: a beacon survives that.
    flush(true);
  }

  function formQuantity(form) {
    var input = form.querySelector('input[name="quantity"]');
    var quantity = input ? parseInt(input.value, 10) : 1;
    return quantity >= 1 && quantity <= 999 ? quantity : 1;
  }

  /**
   * The store's own button on a product page: the product form's submit, or the theme's ajax add
   * (WooCommerce's added_to_cart on jQuery, the blocks' wc-blocks_added_to_cart). Nothing about the
   * shopper is read, only that the product on this page went into the cart.
   */
  function watchStoreAdds() {
    if (PAGE_TYPE !== 'product') {
      return;
    }
    document.addEventListener('submit', function (event) {
      var form = event.target;
      if (form && form.matches && form.matches('form.cart')) {
        storeAdded(formQuantity(form));
      }
    }, true);
    document.body.addEventListener('wc-blocks_added_to_cart', function () { storeAdded(1); });
    try {
      if (window.jQuery) {
        window.jQuery(document.body).on('added_to_cart', function () { storeAdded(1); });
      }
    } catch (e) { /* a theme without jQuery */ }
  }

  setInterval(function () { flush(false); }, 4000);
  document.addEventListener('visibilitychange', function () {
    if (document.visibilityState === 'hidden') {
      flush(true);
    }
  });
  window.addEventListener('pagehide', function () { flush(true); });

  function watchExposure(element, fire) {
    if (!('IntersectionObserver' in window)) {
      return;
    }

    var timer = null;
    var since = 0;
    var ratio = 0;
    var observer = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        ratio = entry.intersectionRatio;
        if (ratio >= 0.5 && !timer) {
          since = Date.now();
          timer = setTimeout(function () {
            observer.disconnect();
            fire(Math.min(3600000, Date.now() - since), Math.min(1, Math.round(ratio * 100) / 100));
          }, 1000);
        } else if (ratio < 0.5 && timer) {
          clearTimeout(timer);
          timer = null;
        }
      });
    }, { threshold: [0, 0.5, 1] });

    observer.observe(element);
  }

  // ---------------------------------------------------------------- store data

  var nonce = ctx.nonce || null;
  var lastMessage = '';

  function safeUrl(value) {
    return typeof value === 'string' && /^https?:\/\//i.test(value) ? value : null;
  }

  function liveProducts(ids) {
    if (!STORE_API || ids.length === 0 || !window.fetch) {
      return Promise.resolve(null);
    }

    var unique = ids.filter(function (id, i) { return ids.indexOf(id) === i; }).slice(0, 100);
    var url = STORE_API + 'products?per_page=100&include=' + unique.map(encodeURIComponent).join(',');

    return fetch(url, { credentials: 'same-origin' })
      .then(function (response) { return response.ok ? response.json() : null; })
      .then(function (products) {
        if (!Array.isArray(products)) {
          return null;
        }
        var byId = {};
        products.forEach(function (product) { byId[String(product.id)] = product; });
        return byId;
      })
      .catch(function () { return null; });
  }

  function money(prices, key) {
    if (!prices || prices[key] === undefined || prices[key] === null || prices[key] === '') {
      return '';
    }
    var minor = parseInt(prices.currency_minor_unit, 10) || 0;
    var amount = parseInt(prices[key], 10) / Math.pow(10, minor);
    if (isNaN(amount)) {
      return '';
    }
    var parts = amount.toFixed(minor).split('.');
    parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, prices.currency_thousand_separator || ',');
    return (prices.currency_prefix || '') + parts.join(prices.currency_decimal_separator || '.') + (prices.currency_suffix || '');
  }

  function amount(prices, key) {
    var minor = parseInt(prices && prices.currency_minor_unit, 10) || 0;
    return parseInt(prices && prices[key], 10) / Math.pow(10, minor);
  }

  function addToCart(productId, retried) {
    var headers = { 'Content-Type': 'application/json' };
    var ready = nonce ? Promise.resolve(nonce) : fetch(STORE_API + 'cart', { credentials: 'same-origin' })
      .then(function (response) {
        nonce = response.headers.get('Nonce') || response.headers.get('X-WC-Store-API-Nonce');
        return nonce;
      });

    return ready.then(function (value) {
      if (value) {
        headers.Nonce = value;
        headers['X-WC-Store-API-Nonce'] = value;
      }
      return fetch(STORE_API + 'cart/add-item', {
        method: 'POST',
        credentials: 'same-origin',
        headers: headers,
        body: JSON.stringify({ id: Number(productId), quantity: 1 })
      });
    }).then(function (response) {
      var fresh = response.headers.get('Nonce');
      if (fresh) {
        nonce = fresh;
      }
      return response.json().catch(function () { return {}; }).then(function (body) {
        if (response.ok) {
          return 'added';
        }
        var code = String((body && body.code) || '');
        if (!retried && /nonce/.test(code)) {
          nonce = null;
          return addToCart(productId, true);
        }
        if (/stock/.test(code)) {
          return 'out_of_stock';
        }
        // A store plugin that requires a choice (a length, a color) refuses with a general add-to-cart
        // error and a message for the shopper. Send them to the product page with that message.
        if (/variation|attribute|option|add_to_cart_error/.test(code)) {
          lastMessage = body && body.message ? decodeEntities(body.message) : '';
          return 'needs_options';
        }
        return 'error';
      });
    }).catch(function () { return 'error'; });
  }

  function refreshCartFragments() {
    try {
      if (window.jQuery) {
        window.jQuery(document.body).trigger('wc_fragment_refresh');
      }
      document.body.dispatchEvent(new Event('wc-blocks_added_to_cart'));
    } catch (e) { /* the theme has no mini cart */ }
  }

  // ---------------------------------------------------------------- memory of viewed products

  function seenProducts() {
    try {
      var list = JSON.parse(window.localStorage.getItem(SEEN_KEY) || '[]');
      return Array.isArray(list) ? list : [];
    } catch (e) {
      return [];
    }
  }

  function rememberProduct(entry) {
    try {
      var list = seenProducts().filter(function (item) { return item && item.id !== entry.id; });
      list.unshift(entry);
      window.localStorage.setItem(SEEN_KEY, JSON.stringify(list.slice(0, MAX_SEEN)));
    } catch (e) { /* private mode */ }
  }

  /** The most recent other product of the same type, viewed within 30 days. */
  function comparable() {
    if (!bank.compare) {
      return null;
    }
    var limit = Date.now() - 30 * 86400000;
    var list = seenProducts();
    for (var i = 0; i < list.length; i++) {
      var item = list[i];
      if (item && item.id !== PAGE_ID && item.key === bank.compare.key && item.at > limit && Array.isArray(item.rows)) {
        return item;
      }
    }
    return null;
  }

  // ---------------------------------------------------------------- rendering

  var CSS = [
    // inline-size containment: the widget takes its column's width and a long line never widens the column.
    ':host{all:initial;display:block;contain:inline-size;max-width:100%;margin:16px 0;font-family:inherit;color:inherit;font-size:15px;line-height:1.5;',
    '--accent:var(--rega-accent,#1f2933);--surface:var(--rega-surface,#fff);--radius:var(--rega-radius,14px);--line:rgba(17,24,39,.12);--muted:rgba(17,24,39,.62)}',
    ':host(.is-floating){contain:none;position:fixed;bottom:16px;inset-inline-start:16px;z-index:2147483000;margin:0;max-width:calc(100vw - 32px)}',
    '*{box-sizing:border-box}',
    '.rega{position:relative}',
    '.note{display:block;width:fit-content;margin:0 0 6px;padding:2px 8px;border-radius:999px;background:#fff4d6;color:#7a5200;font-size:12px}.note a{color:inherit;text-decoration:underline}',
    '.chips{display:flex;flex-wrap:wrap;gap:8px;align-items:center}',
    ':host(.is-floating) .chips{flex-wrap:nowrap;overflow-x:auto;scrollbar-width:none}',
    '.pill{all:unset;box-sizing:border-box;display:inline-flex;align-items:center;gap:7px;max-width:100%;padding:8px 13px;border:1px solid var(--line);',
    'border-radius:999px;background:var(--surface);color:inherit;font:inherit;font-size:14px;cursor:pointer;box-shadow:0 1px 2px rgba(0,0,0,.05);transition:box-shadow .2s,border-color .2s,background .2s;white-space:nowrap}',
    '.pill:hover,.pill:focus-visible{border-color:var(--accent);box-shadow:0 4px 14px rgba(0,0,0,.08)}',
    '.pill:focus-visible{outline:2px solid var(--accent);outline-offset:2px}',
    '.pill[aria-expanded="true"]{background:var(--accent);border-color:var(--accent);color:#fff}',
    '.pill[aria-expanded="true"] .spark{color:#fff}',
    '.spark{flex:none;width:17px;height:17px;color:var(--accent)}',
    '.quote{all:unset;box-sizing:border-box;display:flex;align-items:flex-start;gap:10px;width:100%;margin:0 0 10px;padding:10px 14px;',
    'border-inline-start:3px solid var(--accent);border-start-end-radius:10px;border-end-end-radius:10px;background:rgba(17,24,39,.04);cursor:pointer;font:inherit;font-size:15px;line-height:1.55;color:inherit}',
    '.quote:hover,.quote:focus-visible{background:rgba(17,24,39,.07)}.quote:focus-visible{outline:2px solid var(--accent);outline-offset:2px}',
    '.quote .mark{flex:none;font-family:Georgia,serif;font-size:30px;line-height:.9;color:var(--accent)}',
    '.quote strong,.highlights strong{font-weight:700}',
    '.pop{display:flex;flex-wrap:wrap;align-items:center;gap:8px;margin:0 0 10px;font-size:13px;color:var(--muted)}',
    '.pop .hot{display:inline-flex;align-items:center;gap:5px;padding:2px 9px;border-radius:999px;background:rgba(200,30,30,.09);color:#a51616;font-weight:600;font-size:12px}',
    '.pop .hot svg{width:12px;height:12px}',
    '.highlights li{position:relative;padding-inline-start:18px;margin:6px 0}',
    '.highlights li:before{content:"";position:absolute;inset-inline-start:2px;top:.6em;width:7px;height:7px;border-radius:50%;background:var(--accent)}',
    '.chip-label{display:block;max-width:22ch;overflow:hidden;text-overflow:ellipsis}',
    // How much waits inside a circle: "+4" products, "3" points. Quiet on a closed circle, lit on the open one.
    '.count{flex:none;display:inline-flex;align-items:center;justify-content:center;min-width:18px;height:18px;padding:0 5px;border-radius:999px;background:rgba(17,24,39,.07);color:var(--muted);font-size:11px;font-weight:700;line-height:1}',
    '.pill[aria-expanded="true"] .count{background:rgba(255,255,255,.28);color:#fff}',
    '.panel{margin-top:8px;padding:6px 16px 14px;border:1px solid var(--line);border-radius:var(--radius);background:var(--surface);color:#111827}',
    ':host(.is-floating) .panel{position:absolute;bottom:calc(100% + 8px);inset-inline-start:0;width:min(420px,calc(100vw - 32px));max-height:70vh;overflow:auto;box-shadow:0 12px 40px rgba(0,0,0,.18)}',
    '.panel[hidden]{display:none}',
    '.head{display:flex;align-items:center;justify-content:space-between;gap:8px;padding-top:4px}',
    'h3{margin:0;font-size:15px;font-weight:600;color:#111827}',
    '.close{all:unset;cursor:pointer;width:28px;height:28px;display:grid;place-items:center;border-radius:50%;color:var(--muted);font-size:20px;line-height:1}',
    '.close:hover,.close:focus-visible{background:rgba(0,0,0,.06)}',
    '.body{margin-top:8px}',
    'ul{margin:0;padding:0;list-style:none}',
    '.lines li{position:relative;padding-inline-start:18px;margin:4px 0}',
    '.lines li:before{content:"";position:absolute;inset-inline-start:2px;top:.6em;width:7px;height:7px;border-radius:50%;background:var(--accent)}',
    '.specs{display:grid;grid-template-columns:auto 1fr;gap:4px 14px;margin:0}',
    '.specs dt{color:var(--muted)}.specs dd{margin:0;font-weight:500}',
    '.tags{display:flex;flex-wrap:wrap;gap:6px;margin-bottom:10px}',
    '.tag{padding:3px 10px;border-radius:999px;background:rgba(17,24,39,.06);font-size:13px}',
    '.cards{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:10px}',
    '.card{position:relative;display:flex;flex-direction:column;gap:6px;padding:8px;border:1px solid var(--line);border-radius:10px;background:#fff}',
    '.card a{color:inherit;text-decoration:none}',
    '.card img{display:block;width:100%;aspect-ratio:1;object-fit:contain;background:#f6f6f7;border-radius:6px}',
    '.card .title{font-size:13px;line-height:1.35;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}',
    '.card .reason{font-size:12px;color:var(--muted);line-height:1.35}',
    '.badge{position:absolute;top:12px;inset-inline-start:12px;padding:1px 8px;border-radius:999px;background:#c81e1e;color:#fff;font-size:11px}',
    '.price{font-weight:600;font-size:14px}.price del{display:inline-block;font-weight:400;color:var(--muted);margin-inline-start:8px;font-size:12px}',
    '.add{all:unset;box-sizing:border-box;margin-top:auto;text-align:center;padding:7px 8px;border-radius:8px;background:var(--accent);color:#fff;font-size:13px;cursor:pointer}',
    '.add[disabled]{opacity:.6;cursor:default}.add.secondary{background:transparent;color:var(--accent);border:1px solid var(--accent)}',
    '.note-price{font-size:12px;color:var(--muted);margin-top:-4px}.status{font-size:12px;color:var(--muted)}.status a{color:var(--accent)}',
    '.ask-suggested{display:flex;flex-wrap:wrap;gap:6px;margin-bottom:10px}',
    '.ask-chip{all:unset;box-sizing:border-box;cursor:pointer;padding:6px 12px;border-radius:999px;background:rgba(17,24,39,.06);font-size:13px;line-height:1.4}',
    '.ask-chip:hover,.ask-chip:focus-visible{background:rgba(17,24,39,.12)}',
    '.ask-form{display:flex;gap:8px}',
    '.ask-input{flex:1;min-width:0;box-sizing:border-box;padding:10px 12px;border:1px solid var(--line);border-radius:10px;background:#fff;color:inherit;font:inherit;font-size:16px}',
    '.ask-input:focus{outline:2px solid var(--accent);outline-offset:1px}',
    '.ask-send{all:unset;box-sizing:border-box;cursor:pointer;padding:10px 16px;border-radius:10px;background:var(--accent);color:#fff;font-size:14px}',
    '.ask-send[disabled]{opacity:.6;cursor:default}',
    '.ask-answer{margin-top:10px;padding:10px 12px;border-radius:10px;background:rgba(17,24,39,.04)}',
    '.signup{margin-top:12px;padding:12px;border:1px dashed var(--line);border-radius:10px}',
    '.signup-title{font-weight:600;margin-bottom:8px}',
    '.signup-form{display:flex;gap:8px}',
    '.signup-input{flex:1;min-width:0;box-sizing:border-box;padding:10px 12px;border:1px solid var(--line);border-radius:10px;background:#fff;color:inherit;font:inherit;font-size:16px}',
    '.signup-input:focus{outline:2px solid var(--accent);outline-offset:1px}',
    '.signup-send{all:unset;box-sizing:border-box;cursor:pointer;padding:10px 16px;border-radius:10px;background:var(--accent);color:#fff;font-size:14px;white-space:nowrap}',
    '.signup-send[disabled]{opacity:.6;cursor:default}',
    '.signup-consent{display:flex;gap:8px;align-items:flex-start;margin-top:8px;font-size:12px;color:var(--muted);line-height:1.5;cursor:pointer}',
    '.signup-consent input{margin:2px 0 0;flex:none}',
    '.signup-status{margin-top:8px;font-size:13px}',
    '.signup-note{font-size:13px;color:var(--muted)}',
    '.ask-q{font-weight:600;margin-bottom:3px}.ask-a{line-height:1.55}.ask-a.is-loading{color:var(--muted)}',
    '.ask-heading{margin:14px 0 4px;font-size:13px;font-weight:600;color:var(--muted)}',
    '.ask-item{padding:8px 0;border-top:1px solid var(--line)}',
    '.ask-note{margin-top:10px;font-size:11px;color:var(--muted)}',
    '.ask-general{margin-top:4px;font-size:12px;color:var(--muted)}',
    '.contact{display:flex;flex-wrap:wrap;align-items:center;gap:8px 12px;margin-top:10px;padding:10px 14px;border:1px solid var(--line);border-radius:var(--radius);background:var(--surface)}',
    '.contact-head{display:flex;align-items:center;gap:8px;flex:1 1 200px;min-width:0}',
    '.contact-title{font-size:14px;line-height:1.4}',
    '.contact-badge{flex:none;display:inline-flex;align-items:center;gap:5px;padding:2px 9px;border-radius:999px;background:rgba(17,24,39,.07);color:var(--muted);font-size:11px}',
    '.contact-badge:before{content:"";width:7px;height:7px;border-radius:50%;background:currentColor}',
    '.contact.is-online .contact-badge{background:#e8f6ee;color:#146c43}',
    '.contact-button{flex:none;display:inline-flex;align-items:center;gap:7px;padding:8px 16px;border-radius:999px;background:#25d366;color:#0b2e13;text-decoration:none;font-size:14px;font-weight:600}',
    '.contact-button:hover,.contact-button:focus-visible{filter:brightness(.95)}',
    '.contact-note{flex:1 1 100%;font-size:12px;color:var(--muted)}',
    '.browse{display:flex;flex-wrap:wrap;gap:8px;margin-top:12px}',
    '.browse a{display:inline-flex;align-items:center;gap:6px;padding:5px 12px;border:1px solid var(--line);border-radius:999px;color:inherit;text-decoration:none;font-size:13px}',
    '.browse a:after{content:"\\203A"}.rega[dir="rtl"] .browse a:after{content:"\\2039"}',
    '.browse a:hover,.browse a:focus-visible{border-color:var(--accent)}',
    '.guides a{display:flex;align-items:center;gap:10px;padding:6px 0;color:inherit;text-decoration:none}',
    '.guides img{width:56px;height:42px;object-fit:cover;border-radius:6px;flex:none;background:#f6f6f7}',
    '.guides span{font-size:14px}.guides a:hover span{text-decoration:underline}',
    '.cmp-cards{display:grid;grid-template-columns:1fr 1fr;gap:10px}',
    '.cmp-card{display:flex;flex-direction:column;align-items:flex-start;gap:4px;padding:10px;border:1px solid var(--line);border-radius:12px;background:#fff;color:inherit;text-decoration:none;min-width:0}',
    '.cmp-card.is-this{border-color:var(--accent);box-shadow:inset 0 0 0 1px var(--accent)}',
    'a.cmp-card:hover,a.cmp-card:focus-visible{border-color:var(--accent)}',
    '.cmp-card img{display:block;width:100%;aspect-ratio:4/3;object-fit:contain;background:#f6f6f7;border-radius:8px}',
    '.cmp-badge{padding:1px 8px;border-radius:999px;background:rgba(17,24,39,.07);font-size:11px;color:var(--muted)}',
    '.cmp-card.is-this .cmp-badge{background:var(--accent);color:#fff}',
    '.cmp-title{font-size:13px;line-height:1.35;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}',
    '.cmp-price{font-weight:700;font-size:15px}',
    '.cmp-note{margin-top:10px;padding:6px 10px;border-radius:8px;background:#e8f6ee;color:#146c43;font-size:13px}',
    '.cmp-heading{margin:14px 0 4px;font-size:13px;font-weight:600;color:var(--muted)}',
    '.cmp-row{display:grid;grid-template-columns:1fr 1fr;gap:2px 10px;padding:8px 0;border-top:1px solid var(--line)}',
    '.cmp-label{grid-column:1/-1;font-size:12px;color:var(--muted)}',
    '.cmp-value{font-size:14px;font-weight:500}.cmp-value.is-this{font-weight:700}',
    '.cmp-same{margin-top:10px;padding-top:8px;border-top:1px solid var(--line);font-size:12px;color:var(--muted)}'
  ].join('');

  var SPARK = '<svg class="spark" viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M12 2l1.9 6.1L20 10l-6.1 1.9L12 18l-1.9-6.1L4 10l6.1-1.9zM19 15l.9 2.1L22 18l-2.1.9L19 21l-.9-2.1L16 18l2.1-.9z"/></svg>';

  function el(tag, className, text) {
    var node = document.createElement(tag);
    if (className) {
      node.className = className;
    }
    if (text !== undefined && text !== null) {
      node.textContent = String(text);
    }
    return node;
  }

  var decoder = document.createElement('textarea');
  function decodeEntities(value) {
    decoder.innerHTML = String(value || '');
    return decoder.value;
  }

  function place(host) {
    var placement = bank.placement || {};
    var target = null;

    try {
      target = placement.selector ? document.querySelector(placement.selector) : null;
    } catch (e) {
      target = null; // an invalid selector falls back like a missing element
    }

    if (target && target.parentNode) {
      switch (placement.position) {
        case 'before': target.parentNode.insertBefore(host, target); break;
        case 'prepend': target.insertBefore(host, target.firstChild); break;
        case 'append': target.appendChild(host); break;
        default: target.parentNode.insertBefore(host, target.nextSibling);
      }
      return true;
    }

    if (placement.floating) {
      host.classList.add('is-floating');
      document.body.appendChild(host);
      return true;
    }

    return false;
  }

  function productCard(section, product, live, labels) {
    var data = live && live[product.id];
    if (live && (!data || !data.is_in_stock)) {
      return null; // gone or out of stock right now
    }
    if (section.require_sale && !(data && data.on_sale)) {
      return null; // "on sale" is a promise: only what is on sale right now
    }

    var card = el('article', 'card');
    var url = safeUrl(data ? data.permalink : product.url);
    var link = el('a');
    if (url) {
      link.href = url;
    }
    link.addEventListener('click', function () { track('click', section, 'panel', { product_id: String(product.id) }); });

    var image = safeUrl(data && data.images && data.images[0] ? data.images[0].thumbnail : product.image);
    if (image) {
      var img = el('img');
      img.src = image;
      img.alt = '';
      img.loading = 'lazy';
      link.appendChild(img);
    }
    link.appendChild(el('span', 'title', data ? decodeEntities(data.name) : product.title));
    card.appendChild(link);

    if (data && data.on_sale) {
      card.appendChild(el('span', 'badge', labels.on_sale));
    }

    if (product.reason) {
      card.appendChild(el('div', 'reason', product.reason));
    }

    if (!data) {
      return card; // no live data: no price and no button, the link still works
    }

    var price = el('div', 'price', money(data.prices, 'price'));
    if (data.on_sale && data.prices && data.prices.regular_price !== data.prices.price) {
      price.appendChild(el('del', null, money(data.prices, 'regular_price')));
    }
    card.appendChild(price);
    if (product.price_note) {
      card.appendChild(el('div', 'note-price', product.price_note));
    }

    function chooseLink() {
      var choose = el('a', 'add secondary', labels.choose_options);
      if (url) {
        choose.href = url;
      }
      choose.addEventListener('click', function () { track('click', section, 'panel', { product_id: String(product.id) }); });
      return choose;
    }

    var simple = data.type === 'simple' && data.is_purchasable && !data.has_options && !product.needs_options;
    if (!simple) {
      card.appendChild(chooseLink());
      return card;
    }

    var button = el('button', 'add', labels.add_to_cart);
    button.type = 'button';
    var status = el('div', 'status');
    button.addEventListener('click', function () {
      button.disabled = true;
      button.textContent = labels.adding;
      addToCart(product.id).then(function (result) {
        track('add_to_cart', section, 'panel', { source: 'widget', product_id: String(product.id), quantity: 1, result: result });
        status.textContent = '';
        if (result === 'added') {
          button.textContent = labels.added;
          // The refresh below fires the store's own signals; this add is already counted.
          lastAddAt = Date.now();
          refreshCartFragments();
          var cart = safeUrl(ctx.cartUrl);
          if (cart) {
            var view = el('a', null, labels.view_cart);
            view.href = cart;
            status.appendChild(view);
          }
        } else if (result === 'needs_options') {
          card.replaceChild(chooseLink(), button);
          status.textContent = lastMessage;
        } else {
          button.disabled = false;
          button.textContent = labels.add_to_cart;
          status.textContent = result === 'out_of_stock' ? labels.out_of_stock : labels.error;
        }
      });
    });
    card.appendChild(button);
    card.appendChild(status);

    return card;
  }

  function guideList(section, guides) {
    var list = el('ul', 'guides');
    (guides || []).forEach(function (guide) {
      var url = safeUrl(guide.url);
      if (!url) {
        return;
      }
      var item = el('li');
      var link = el('a');
      link.href = url;
      var image = safeUrl(guide.image);
      if (image) {
        var img = el('img');
        img.src = image;
        img.alt = '';
        img.loading = 'lazy';
        link.appendChild(img);
      }
      link.appendChild(el('span', null, guide.title));
      link.addEventListener('click', function () { track('click', section, 'panel', guide.id ? { content_id: String(guide.id) } : null); });
      item.appendChild(link);
      list.appendChild(item);
    });
    return list.firstChild ? list : null;
  }

  /** An amount in the store's currency format, like money() but for any value. */
  function moneyValue(prices, value) {
    var minor = parseInt(prices && prices.currency_minor_unit, 10) || 0;
    var formatted = {};
    for (var key in prices) {
      if (Object.prototype.hasOwnProperty.call(prices, key)) {
        formatted[key] = prices[key];
      }
    }
    formatted.value = String(Math.round(value * Math.pow(10, minor)));
    return money(formatted, 'value');
  }

  /**
   * This product next to one of the same kind the shopper viewed before: two cards with photo and
   * price, how much cheaper one is, what differs, and what is the same in one line.
   */
  function compareView(section, previous, live, labels) {
    var current = live && live[PAGE_ID];
    var before = live && live[previous.id];
    var view = el('div', 'cmp');

    function card(data, title, url, badge, isThis) {
      var col = el(url ? 'a' : 'div', 'cmp-card' + (isThis ? ' is-this' : ''));
      if (url) {
        col.href = url;
        col.addEventListener('click', function () { track('click', section, 'panel', { product_id: String(previous.id) }); });
      }
      var image = safeUrl(data && data.images && data.images[0] ? data.images[0].thumbnail : '');
      if (image) {
        var img = el('img');
        img.src = image;
        img.alt = '';
        img.loading = 'lazy';
        col.appendChild(img);
      }
      col.appendChild(el('span', 'cmp-badge', badge));
      col.appendChild(el('span', 'cmp-title', title));
      if (data) {
        col.appendChild(el('span', 'cmp-price', money(data.prices, 'price')));
      }
      return col;
    }

    var heading = document.querySelector('h1');
    var thisTitle = current ? decodeEntities(current.name) : (heading ? heading.textContent.trim() : labels.this_product);
    var cards = el('div', 'cmp-cards');
    cards.appendChild(card(current, thisTitle, null, labels.this_product, true));
    cards.appendChild(card(before, before ? decodeEntities(before.name) : previous.title, safeUrl(previous.url), labels.viewed_before, false));
    view.appendChild(cards);

    if (current && before) {
      var difference = amount(current.prices, 'price') - amount(before.prices, 'price');
      if (Math.abs(difference) >= 1) {
        var note = difference < 0 ? labels.this_cheaper : labels.before_cheaper;
        view.appendChild(el('div', 'cmp-note', String(note || '').replace(':amount', moneyValue(current.prices, Math.abs(difference)))));
      }
    }

    var mine = {};
    bank.compare.rows.forEach(function (r) { mine[r[0]] = r; });
    var theirs = {};
    previous.rows.forEach(function (r) { theirs[r[0]] = r; });
    var keys = bank.compare.rows.map(function (r) { return r[0]; });
    previous.rows.forEach(function (r) { if (keys.indexOf(r[0]) === -1) { keys.push(r[0]); } });

    var rows = el('div', 'cmp-rows');
    var same = [];
    keys.forEach(function (key) {
      var label = (mine[key] || theirs[key])[1];
      var a = mine[key] ? mine[key][2] : '';
      var b = theirs[key] ? theirs[key][2] : '';
      if (a && a === b) {
        same.push(label + ': ' + a);
        return;
      }
      var row = el('div', 'cmp-row');
      row.appendChild(el('div', 'cmp-label', label));
      row.appendChild(el('div', 'cmp-value is-this', a || '—'));
      row.appendChild(el('div', 'cmp-value', b || '—'));
      rows.appendChild(row);
    });

    if (rows.firstChild) {
      view.appendChild(el('div', 'cmp-heading', labels.differences));
      view.appendChild(rows);
    }
    if (same.length) {
      view.appendChild(el('div', 'cmp-same', (labels.same_in_both || '') + ' ' + same.join(' · ')));
    }

    return view;
  }

  /** Whether the shop answers right now, by its own hours and time zone. */
  function shopIsOnline(contact) {
    var hours = contact.hours || [];
    var now = new Date();
    var day;
    var minutes;

    try {
      var parts = new Intl.DateTimeFormat('en-GB', {
        timeZone: contact.timezone || 'UTC', weekday: 'short', hour: '2-digit', minute: '2-digit', hour12: false
      }).formatToParts(now);
      var read = {};
      parts.forEach(function (part) { read[part.type] = part.value; });
      day = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'].indexOf(read.weekday);
      minutes = parseInt(read.hour, 10) * 60 + parseInt(read.minute, 10);
    } catch (e) {
      day = now.getDay();
      minutes = now.getHours() * 60 + now.getMinutes();
    }

    // Sunday to Thursday share their hours; Friday and Saturday have their own.
    var today = day === 5 ? hours[1] : (day === 6 ? hours[2] : hours[0]);
    var match = /^(\d{1,2}):(\d{2})\s*-\s*(\d{1,2}):(\d{2})$/.exec(String(today || '').trim());

    if (!match) {
      return false;
    }

    var from = parseInt(match[1], 10) * 60 + parseInt(match[2], 10);
    var until = parseInt(match[3], 10) * 60 + parseInt(match[4], 10);

    return minutes >= from && minutes < until;
  }

  /** The strip that opens WhatsApp with this product, when the shop asked for one. */
  function contactStrip(live) {
    var contact = bank.contact;

    if (!contact) {
      return null;
    }

    var online = shopIsOnline(contact);

    if (!online && contact.hide_when_offline) {
      return null;
    }

    var data = live && live[PAGE_ID];
    var heading = document.querySelector('h1');
    var title = data ? decodeEntities(data.name) : (heading ? heading.textContent.trim().slice(0, 120) : document.title);
    var link = safeUrl(data ? data.permalink : location.href.split('?')[0]) || location.href.split('?')[0];
    var message = String(contact.message || '').replace(':product', title).replace(':url', link);

    var strip = el('div', 'contact' + (online ? ' is-online' : ''));
    var head = el('div', 'contact-head');
    head.appendChild(el('span', 'contact-badge', online ? contact.online_label : contact.offline_label));
    head.appendChild(el('span', 'contact-title', contact.title));
    strip.appendChild(head);

    var button = el('a', 'contact-button', contact.button);
    button.href = 'https://wa.me/' + contact.number + '?text=' + encodeURIComponent(message);
    button.target = '_blank';
    button.rel = 'noopener';
    var section = { candidate: 'contact', model: 'contact' };
    button.addEventListener('click', function () {
      track('click', section, 'teaser', PAGE_TYPE === 'product' ? { product_id: PAGE_ID } : { content_id: PAGE_ID });
    });
    strip.appendChild(button);

    if (!online && contact.offline_note) {
      strip.appendChild(el('div', 'contact-note', contact.offline_note));
    }

    return strip;
  }

  /**
   * The question box: questions to tap (asked most about this product, then common ones), a field
   * for a free question, the answer, and what earlier shoppers asked. Loaded on first open.
   */
  function askPanel(section, labels) {
    var node = el('div', 'body ask');
    var suggested = el('div', 'ask-suggested');
    var form = el('form', 'ask-form');
    var input = el('input', 'ask-input');
    input.type = 'text';
    input.maxLength = 200;
    input.placeholder = labels.ask_placeholder || '';
    input.setAttribute('aria-label', labels.ask_title || '');
    var send = el('button', 'ask-send', labels.ask_send);
    send.type = 'submit';
    form.appendChild(input);
    form.appendChild(send);
    var answer = el('div', 'ask-answer');
    answer.setAttribute('aria-live', 'polite');
    answer.hidden = true;
    var recent = el('div', 'ask-recent');
    node.appendChild(suggested);
    node.appendChild(form);
    node.appendChild(answer);
    node.appendChild(recent);
    node.appendChild(el('div', 'ask-note', labels.ask_note));

    var busy = false;
    function ask(question) {
      question = String(question || '').trim();
      if (!question || busy) {
        return;
      }
      busy = true;
      send.disabled = true;
      answer.hidden = false;
      answer.textContent = '';
      answer.appendChild(el('div', 'ask-q', question));
      var reply = el('div', 'ask-a is-loading', labels.ask_thinking);
      answer.appendChild(reply);

      fetch(API + '/widget/' + ctx.site + '/ask', {
        method: 'POST',
        credentials: 'omit',
        headers: { 'Content-Type': 'text/plain' },
        body: JSON.stringify({ id: PAGE_ID, question: question, vid: vid, locale: String(ctx.locale || 'he').slice(0, 2) })
      })
        .then(function (response) { return response.ok ? response.json() : null; })
        .then(function (json) {
          var data = json && json.data;
          reply.className = 'ask-a';
          reply.textContent = data ? data.answer : labels.ask_error;
          if (data && data.source === 'general') {
            answer.appendChild(el('div', 'ask-general', labels.ask_general));
          }
          track('chat_question', section, 'panel', {
            length: Math.min(2000, question.length),
            answered_from: data && data.from === 'bank' ? 'bank' : (data && data.outcome === 'answered' ? 'rag' : 'none')
          });
        })
        .catch(function () {
          reply.className = 'ask-a';
          reply.textContent = labels.ask_error;
        })
        .then(function () {
          busy = false;
          send.disabled = false;
        });
    }

    form.addEventListener('submit', function (event) {
      event.preventDefault();
      ask(input.value);
      input.value = '';
    });

    node.load = function () {
      node.load = null;
      fetch(API + '/widget/' + ctx.site + '/questions?id=' + encodeURIComponent(PAGE_ID) + '&locale=' + encodeURIComponent(String(ctx.locale || 'he').slice(0, 2)), { credentials: 'omit' })
        .then(function (response) { return response.ok ? response.json() : null; })
        .then(function (json) {
          var data = json && json.data;
          if (!data) {
            return;
          }
          (data.suggested || []).forEach(function (question) {
            var chip = el('button', 'ask-chip', question);
            chip.type = 'button';
            chip.addEventListener('click', function () { ask(question); });
            suggested.appendChild(chip);
          });
          if ((data.recent || []).length) {
            recent.appendChild(el('div', 'ask-heading', labels.ask_recent));
            data.recent.forEach(function (item) {
              var row = el('div', 'ask-item');
              row.appendChild(el('div', 'ask-q', item.question));
              row.appendChild(el('div', 'ask-a', item.answer));
              if (item.source === 'general') {
                row.appendChild(el('div', 'ask-general', labels.ask_general));
              }
              recent.appendChild(row);
            });
          }
        })
        .catch(function () { /* the field still works */ });
    };

    return node;
  }

  /**
   * Under the products a shopper viewed: the invitation to leave a phone or an email so the list
   * waits for them next time. Once they left one, the same place says so instead.
   */
  function signUpBox(section, labels) {
    var box = el('div', 'signup');

    if (section.signed_up) {
      box.appendChild(el('div', 'signup-note', String(labels.signed_up_as || '').replace(':contact', section.signed_up.masked)));
      return box;
    }

    var wording = section.signup;
    if (!wording) {
      return null;
    }

    box.appendChild(el('div', 'signup-title', wording.title));

    var form = el('form', 'signup-form');
    var input = el('input', 'signup-input');
    input.type = 'text';
    input.maxLength = 190;
    input.placeholder = wording.placeholder || '';
    input.setAttribute('aria-label', wording.title);
    var send = el('button', 'signup-send', wording.button);
    send.type = 'submit';
    form.appendChild(input);
    form.appendChild(send);
    box.appendChild(form);

    var consent = el('label', 'signup-consent');
    var agreed = el('input');
    agreed.type = 'checkbox';
    consent.appendChild(agreed);
    consent.appendChild(el('span', null, wording.consent));
    box.appendChild(consent);

    var status = el('div', 'signup-status');
    status.setAttribute('aria-live', 'polite');
    box.appendChild(status);

    function post(path, body) {
      return fetch(API + '/widget/' + ctx.site + '/' + path, {
        method: 'POST',
        credentials: 'omit',
        headers: { 'Content-Type': 'text/plain' },
        body: JSON.stringify(body)
      })
        .then(function (response) { return response.json(); })
        .then(function (json) { return (json && json.data) || null; })
        .catch(function () { return null; });
    }

    /** The second step: the code that proves the contact is theirs. */
    function askForCode(masked) {
      form.parentNode.removeChild(form);
      consent.parentNode.removeChild(consent);
      status.textContent = String(labels.signup_code_sent || '').replace(':contact', masked || '');

      var codeForm = el('form', 'signup-form');
      var code = el('input', 'signup-input');
      code.type = 'text';
      code.inputMode = 'numeric';
      code.maxLength = 8;
      code.placeholder = labels.signup_code_placeholder || '';
      code.setAttribute('aria-label', labels.signup_code_placeholder || '');
      var confirm = el('button', 'signup-send', labels.signup_confirm);
      confirm.type = 'submit';
      codeForm.appendChild(code);
      codeForm.appendChild(confirm);
      box.insertBefore(codeForm, status);

      codeForm.addEventListener('submit', function (event) {
        event.preventDefault();
        confirm.disabled = true;
        post('confirm', { vid: vid, code: code.value }).then(function (data) {
          confirm.disabled = false;
          var outcome = data && data.status;
          if (outcome === 'verified') {
            box.removeChild(codeForm);
            status.textContent = labels.signup_verified;
            return;
          }
          status.textContent = outcome === 'expired' ? labels.signup_expired
            : outcome === 'too_many' ? labels.signup_too_many
              : labels.signup_wrong_code;
        });
      });
    }

    form.addEventListener('submit', function (event) {
      event.preventDefault();

      if (!agreed.checked) {
        status.textContent = labels.signup_need_consent;
        return;
      }

      send.disabled = true;
      status.textContent = '';

      post('signup', { vid: vid, contact: input.value, consent: true, locale: String(ctx.locale || 'he').slice(0, 2) })
        .then(function (data) {
          send.disabled = false;
          var outcome = data && data.status;

          if (outcome === 'code_sent') {
            track('click', section, 'panel');
            askForCode(data.masked);
            return;
          }
          if (outcome === 'saved') {
            track('click', section, 'panel');
            box.removeChild(form);
            box.removeChild(consent);
            status.textContent = labels.signup_saved;
            return;
          }
          status.textContent = outcome === 'invalid_contact' ? labels.signup_invalid
            : outcome === 'no_consent' ? labels.signup_need_consent
              : outcome === 'too_many' ? labels.signup_too_many
                : labels.signup_error;
        });
    });

    return box;
  }

  /** The body of one section, or null when nothing in it survives live data. */
  function renderBody(section, live, labels) {
    var node = el('div', 'body');

    if (section.candidate === 'compare') {
      node.appendChild(compareView(section, section.previous, live, labels));
      return node;
    }

    if (section.uses) {
      var tags = el('div', 'tags');
      section.uses.forEach(function (use) { tags.appendChild(el('span', 'tag', use)); });
      node.appendChild(tags);
    }

    // Where the model stands among its kind first, then the highlights, in one list.
    if (section.lines || section.items) {
      var points = el('ul', 'highlights');
      (section.lines || []).forEach(function (line) {
        points.appendChild(el('li', null, line.text));
      });
      (section.items || []).forEach(function (item) {
        var point = el('li');
        point.appendChild(el('strong', null, item.key));
        point.appendChild(document.createTextNode(' ' + item.text));
        points.appendChild(point);
      });
      node.appendChild(points);
    }

    if (section.specs) {
      var specs = el('dl', 'specs');
      section.specs.forEach(function (spec) {
        specs.appendChild(el('dt', null, spec.label));
        specs.appendChild(el('dd', null, spec.value));
      });
      node.appendChild(specs);
    }

    if (section.products) {
      var cards = el('div', 'cards');
      section.products.forEach(function (product) {
        var card = productCard(section, product, live, labels);
        if (card) {
          cards.appendChild(card);
        }
      });
      if (!cards.firstChild) {
        return null;
      }
      node.appendChild(cards);

      // The store's own category pages for more of the same kind.
      var browse = el('div', 'browse');
      (section.categories || []).forEach(function (category) {
        var url = safeUrl(category.url);
        if (!url) {
          return;
        }
        var link = el('a', null, String(labels.browse || ':name').replace(':name', category.title));
        link.href = url;
        link.addEventListener('click', function () { track('click', section, 'panel'); });
        browse.appendChild(link);
      });
      if (browse.firstChild) {
        node.appendChild(browse);
      }
    }

    if (section.guides) {
      var guides = guideList(section, section.guides);
      if (guides) {
        node.appendChild(guides);
      } else if (!section.uses) {
        return null;
      }
    }

    if (section.signup || section.signed_up) {
      var box = signUpBox(section, labels);
      if (box) {
        node.appendChild(box);
      }
    }

    return node.firstChild ? node : null;
  }

  /** Drops what live store data contradicts. Without live data, price claims are dropped. */
  function reconcile(live) {
    var current = live && live[PAGE_ID];

    bank.sections = bank.sections.map(function (section) {
      if (section.require_sale && !live) {
        return null;
      }
      if (!section.lines) {
        return section;
      }
      var lines = section.lines.filter(function (line) {
        if (line.price === undefined) {
          return true;
        }
        if (!current) {
          return false;
        }
        var livePrice = amount(current.prices, 'price');
        return Math.abs(livePrice - line.price) <= Math.max(1, line.price * 0.005);
      });
      if (lines.length) {
        return Object.assign({}, section, { lines: lines });
      }
      // A price line the live price contradicts is gone; the highlights beside it stay.
      return section.items && section.items.length ? Object.assign({}, section, { lines: null }) : null;
    }).filter(Boolean);
  }

  function render(live, previous) {
    var labels = bank.labels || {};

    if (previous) {
      bank.sections.push({
        candidate: 'compare',
        model: 'compare',
        title: labels.compare_title,
        chip: String(labels.compare_chip || '').replace(':title', previous.title.length > 24 ? previous.title.slice(0, 22) + '…' : previous.title),
        previous: previous
      });
    }

    // What this visitor looked at, and the invitation to have it kept for next time. Never part of
    // the page bank: the bank is the same for everyone and cached, this is one person's own.
    if (viewed && viewed.products && viewed.products.length) {
      bank.sections.push({
        candidate: 'recent',
        model: 'recent',
        title: labels.recent_title,
        chip: labels.recent_chip,
        products: viewed.products,
        signed_up: viewed.signed_up || null,
        signup: viewed.signed_up ? null : (bank.signup || null)
      });
    }

    // Render every body first: a section whose products are all gone gets no circle.
    var rendered = [];
    bank.sections.forEach(function (section) {
      var body = renderBody(section, live, labels);
      if (body) {
        rendered.push({ section: section, body: body });
      }
    });

    // The question box is always the last circle.
    if (bank.ask) {
      var askSection = { candidate: 'ask', model: 'chat', title: labels.ask_title, chip: labels.ask_chip };
      rendered.push({ section: askSection, body: askPanel(askSection, labels) });
    }

    if (rendered.length === 0 && !bank.contact && !bank.popularity) {
      return;
    }

    var host = el('div', 'rega-widget');
    host.setAttribute('data-rega', PAGE_TYPE);
    var root = host.attachShadow ? host.attachShadow({ mode: 'open' }) : host;

    var style = el('style');
    style.textContent = CSS;
    root.appendChild(style);

    var wrap = el('div', 'rega');
    wrap.setAttribute('dir', bank.dir || 'rtl');
    wrap.setAttribute('lang', bank.locale || 'he');

    if (teamPreview) {
      var note = el('div', 'note', labels.preview);
      var explainUrl = safeUrl(bank.explain_url);
      if (explainUrl) {
        note.appendChild(document.createTextNode(' '));
        var explain = el('a', null, labels.why_shown);
        explain.href = explainUrl;
        explain.target = '_blank';
        explain.rel = 'noopener';
        note.appendChild(explain);
      }
      wrap.appendChild(note);
    }

    var chips = el('div', 'chips');
    var panel = el('div', 'panel');
    panel.id = 'rega-panel';
    panel.hidden = true;
    panel.setAttribute('role', 'region');

    var head = el('div', 'head');
    var heading = el('h3');
    var close = el('button', 'close', '×');
    close.type = 'button';
    close.setAttribute('aria-label', labels.close || 'Close');
    head.appendChild(heading);
    head.appendChild(close);
    panel.appendChild(head);
    var holder = el('div');
    panel.appendChild(holder);

    var open = null;
    var exposed = {};

    function setOpen(index, reason) {
      if (open !== null) {
        rendered[open].pill.setAttribute('aria-expanded', 'false');
      }

      if (index === null || index === open) {
        var closing = open;
        open = null;
        panel.hidden = true;
        if (closing !== null) {
          track('dismiss', rendered[closing].section, CHIP_SLOTS[Math.min(closing, CHIP_SLOTS.length - 1)], { reason: reason || 'closed' });
          rendered[closing].pill.focus();
        }
        return;
      }

      open = index;
      var item = rendered[index];
      item.pill.setAttribute('aria-expanded', 'true');
      heading.textContent = item.section.title;
      holder.textContent = '';
      holder.appendChild(item.body);
      if (typeof item.body.load === 'function') {
        item.body.load();
      }
      panel.hidden = false;
      track('open', item.section, CHIP_SLOTS[Math.min(index, CHIP_SLOTS.length - 1)]);

      if (!exposed[item.section.candidate]) {
        exposed[item.section.candidate] = true;
        watchExposure(item.body, function (ms, ratio) {
          track('exposure', item.section, 'panel', { visible_ms: ms, ratio: ratio });
        });
      }
    }

    // The key sentence, readable without a click: the first superlative or highlight, as a quote.
    // A highlight the store repeats on many products is never the key sentence.
    var quoteIndex = -1;
    var quoteItem = null;
    for (var q = 0; q < rendered.length && quoteIndex === -1; q++) {
      var candidate = rendered[q].section;
      var items = (candidate.items || []).filter(function (item) { return !item.common; });
      if (candidate.lines && candidate.lines.length) {
        quoteIndex = q;
      } else if (items.length) {
        quoteIndex = q;
        quoteItem = items[0];
      }
    }

    var quote = null;
    if (quoteIndex !== -1) {
      var source = rendered[quoteIndex].section;
      quote = el('button', 'quote');
      quote.type = 'button';
      quote.setAttribute('aria-controls', 'rega-panel');
      quote.appendChild(el('span', 'mark', '”'));
      var words = el('span', 'quote-text');
      if (quoteItem) {
        words.appendChild(el('strong', null, quoteItem.key));
        words.appendChild(document.createTextNode(' ' + quoteItem.text));
      } else {
        words.textContent = source.lines[0].text;
      }
      quote.appendChild(words);
      quote.addEventListener('click', function () { setOpen(quoteIndex, 'closed'); });
    }

    rendered.forEach(function (item, index) {
      var pill = el('button', 'pill');
      pill.type = 'button';
      pill.setAttribute('aria-expanded', 'false');
      pill.setAttribute('aria-controls', 'rega-panel');

      if (index === 0) {
        pill.innerHTML = SPARK;
      }
      pill.appendChild(el('span', 'chip-label', item.section.chip || item.section.title));
      var count = pillCount(item.body);
      if (count) {
        pill.appendChild(el('span', 'count', count));
      }
      pill.title = item.section.title;

      pill.addEventListener('click', function () { setOpen(index, 'closed'); });
      item.pill = pill;
      chips.appendChild(pill);
    });

    close.addEventListener('click', function () { setOpen(null, 'closed'); });
    wrap.addEventListener('keydown', function (event) {
      if (event.key === 'Escape' && open !== null) {
        setOpen(null, 'escape');
      }
    });

    if (quote) {
      wrap.appendChild(quote);
    }
    var pop = popularityLine();
    if (pop) {
      wrap.appendChild(pop);
    }
    wrap.appendChild(chips);
    wrap.appendChild(panel);

    var strip = contactStrip(live);
    if (strip) {
      wrap.appendChild(strip);
    }
    root.appendChild(wrap);

    if (!place(host)) {
      return;
    }

    // One exposure per section: the quote's section when it is shown, and the first circle's when it is another.
    if (quote) {
      watchExposure(quote, function (ms, ratio) {
        track('exposure', rendered[quoteIndex].section, 'teaser', { visible_ms: ms, ratio: ratio });
      });
    }
    if (quoteIndex !== 0 && rendered.length) {
      watchExposure(chips, function (ms, ratio) {
        track('exposure', rendered[0].section, quote ? 'chip_1' : 'teaser', { visible_ms: ms, ratio: ratio });
      });
    }
    if (strip) {
      watchExposure(strip, function (ms, ratio) {
        track('exposure', { candidate: 'contact', model: 'contact' }, 'teaser', { visible_ms: ms, ratio: ratio });
      });
    }
    if (pop) {
      watchExposure(pop, function (ms, ratio) {
        track('exposure', { candidate: 'popularity', model: 'popularity' }, 'teaser', { visible_ms: ms, ratio: ratio });
      });
    }
  }

  var FLAME = '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M13.5 2c.3 2.4-.3 4.5-1.7 6.2C10.2 10.1 8 11.5 8 14.5A4.5 4.5 0 0 0 12.5 19c2.6 0 4.5-1.9 4.5-4.5 0-1.6-.6-2.8-1.5-3.8-.3 1.1-.9 1.9-1.7 2.3.6-2 .3-4.8-.5-6.5C12.7 5 12.2 3.3 13.5 2z"/></svg>';

  /**
   * What a circle holds, counted from what was really rendered (live stock already applied):
   * "+4" for products or guides, "3" for points. Nothing for a comparison or the question box.
   */
  function pillCount(body) {
    var products = body.querySelectorAll('.card').length;
    if (products) {
      return '+' + products;
    }
    var guides = body.querySelectorAll('.guides li').length;
    if (guides) {
      return '+' + guides;
    }
    var points = body.querySelectorAll('.highlights li').length;
    return points ? String(points) : null;
  }

  /** How wanted the product is: the nightly counts as one quiet line, with a mark when it is among the shop's most wanted. */
  function popularityLine() {
    var pop = bank.popularity;
    if (!pop || (!pop.text && !pop.badge)) {
      return null;
    }
    var line = el('div', 'pop');
    if (pop.badge) {
      var hot = el('span', 'hot');
      hot.innerHTML = FLAME;
      hot.appendChild(document.createTextNode(pop.badge));
      line.appendChild(hot);
    }
    if (pop.text) {
      line.appendChild(el('span', 'pop-text', pop.text));
    }
    return line;
  }

  // ---------------------------------------------------------------- start

  function start() {
    watchStoreAdds();

    var query = '?type=' + PAGE_TYPE + '&id=' + encodeURIComponent(PAGE_ID) +
      '&locale=' + encodeURIComponent(String(ctx.locale || 'he').slice(0, 2)) +
      (previewKey ? '&preview=' + previewKey : '');

    fetch(API + '/widget/' + ctx.site + '/page' + query, { credentials: 'omit' })
      .then(function (response) { return response.ok ? response.json() : null; })
      .then(function (data) {
        if (!data || !data.shop) {
          return;
        }
        bank = data;
        shop = data.shop;
        teamPreview = isPreviewMode && data.preview === true;

        track('page_view');
        setTimeout(function () { flush(false); }, 1500);

        var showWidget = data.enabled && (!isPreviewMode || teamPreview);
        var previous = PAGE_TYPE === 'product' && showWidget ? comparable() : null;
        var sections = data.sections || [];

        // In preview mode real visitors only count as page views; the widget is for the team.
        if (!showWidget) {
          rememberCurrent(null);
          return;
        }

        return fetchViewed().then(function (recent) {
          viewed = recent;
          var hasViewed = !!(recent && recent.products && recent.products.length);

          if (sections.length === 0 && !previous && !hasViewed && !data.ask && !data.contact && !data.popularity) {
            rememberCurrent(null);
            return;
          }

          var ids = [];
          sections.forEach(function (section) {
            (section.products || []).forEach(function (product) { ids.push(String(product.id)); });
          });
          if (hasViewed) {
            recent.products.forEach(function (product) { ids.push(String(product.id)); });
          }
          if (PAGE_TYPE === 'product') {
            ids.push(PAGE_ID);
          }
          if (previous) {
            ids.push(String(previous.id));
          }

          return liveProducts(ids).then(function (live) {
            reconcile(live);
            render(live, previous);
            rememberCurrent(live);
          });
        });
      })
      .catch(function () { /* the page works without the widget */ });
  }

  /**
   * The products this visitor looked at, for their own circle. Asked for separately from the page
   * bank, which is cached and the same for everyone, and the visitor travels in the body, not the
   * URL. The widget works without it.
   */
  function fetchViewed() {
    if (!bank.recent) {
      return Promise.resolve(null);
    }

    return fetch(API + '/widget/' + ctx.site + '/recent', {
      method: 'POST',
      credentials: 'omit',
      headers: { 'Content-Type': 'text/plain' },
      body: JSON.stringify({
        vid: vid,
        id: PAGE_TYPE === 'product' ? PAGE_ID : '',
        locale: String(ctx.locale || 'he').slice(0, 2)
      })
    })
      .then(function (response) { return response.ok ? response.json() : null; })
      .then(function (json) { return (json && json.data) || null; })
      .catch(function () { return null; });
  }

  /** Remember this product for a later comparison, with its live title and link. */
  function rememberCurrent(live) {
    if (PAGE_TYPE !== 'product' || !bank || !bank.compare) {
      return;
    }
    var data = live && live[PAGE_ID];
    var heading = document.querySelector('h1');
    rememberProduct({
      id: PAGE_ID,
      key: bank.compare.key,
      title: data ? decodeEntities(data.name) : (heading ? heading.textContent.trim().slice(0, 120) : ''),
      url: data ? data.permalink : location.href.split('?')[0],
      rows: bank.compare.rows,
      at: Date.now()
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start);
  } else {
    start();
  }
})();
