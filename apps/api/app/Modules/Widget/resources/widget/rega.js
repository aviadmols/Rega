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
 *      anything that is not in stock or whose price superlative no longer holds.
 *   4. Places itself by the CSS selector set for the shop, or floats at the bottom.
 *   5. Reports anonymous events (packages/event-spec) with navigator.sendBeacon.
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

  function pageContext() {
    var page = { type: PAGE_TYPE, path: (location.pathname || '/').slice(0, 512) };
    if (page.path.charAt(0) !== '/') {
      page.path = '/';
    }
    page[PAGE_TYPE === 'product' ? 'product_id' : 'content_id'] = PAGE_ID;
    return page;
  }

  function track(type, section, slot, data) {
    var event = { id: randomId(22), type: type, ts: Date.now(), page: pageContext() };

    if (section) {
      event.candidate = { id: section.candidate, version: bank.bank_version, model: section.model, slot: slot };
      event.bank_version = bank.bank_version;
      event.eligible = bank.sections.slice(0, 12).map(function (s) { return s.candidate; });
      event.bucket = 'none';
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

  function safeUrl(value) {
    return typeof value === 'string' && /^https?:\/\//i.test(value) ? value : null;
  }

  function liveProducts(ids) {
    if (!STORE_API || ids.length === 0 || !window.fetch) {
      return Promise.resolve(null);
    }

    var url = STORE_API + 'products?per_page=100&include=' + ids.map(encodeURIComponent).join(',');

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
        if (/variation|attribute|option/.test(code)) {
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

  // ---------------------------------------------------------------- rendering

  var CSS = [
    ':host{all:initial;display:block;margin:16px 0;font-family:inherit;color:inherit;font-size:15px;line-height:1.5;',
    '--accent:var(--rega-accent,#1f2933);--surface:var(--rega-surface,#fff);--radius:var(--rega-radius,14px);--line:rgba(17,24,39,.12);--muted:rgba(17,24,39,.62)}',
    ':host(.is-floating){position:fixed;bottom:16px;inset-inline-start:16px;z-index:2147483000;margin:0;max-width:calc(100vw - 32px)}',
    '*{box-sizing:border-box}',
    '.rega{position:relative}',
    '.note{display:block;width:fit-content;margin:0 0 6px;padding:2px 8px;border-radius:999px;background:#fff4d6;color:#7a5200;font-size:12px}',
    '.pill{all:unset;box-sizing:border-box;display:inline-flex;align-items:center;gap:8px;max-width:100%;padding:9px 14px;border:1px solid var(--line);',
    'border-radius:999px;background:var(--surface);color:inherit;font:inherit;cursor:pointer;box-shadow:0 1px 2px rgba(0,0,0,.05);transition:box-shadow .2s,border-color .2s}',
    '.pill:hover,.pill:focus-visible{border-color:var(--accent);box-shadow:0 4px 14px rgba(0,0,0,.08)}',
    '.pill:focus-visible{outline:2px solid var(--accent);outline-offset:2px}',
    '.spark{flex:none;width:18px;height:18px;color:var(--accent)}',
    '.teaser{display:block;max-width:24ch;overflow:hidden;white-space:nowrap;text-overflow:ellipsis;transition:max-width .35s ease}',
    '.pill:hover .teaser,.pill:focus-visible .teaser,.pill[aria-expanded="true"] .teaser{max-width:70ch}',
    '.chev{flex:none;width:14px;height:14px;opacity:.6;transition:transform .2s}',
    '.pill[aria-expanded="true"] .chev{transform:rotate(180deg)}',
    '.panel{margin-top:8px;padding:6px 16px 14px;border:1px solid var(--line);border-radius:var(--radius);background:var(--surface);color:#111827}',
    ':host(.is-floating) .panel{position:absolute;bottom:calc(100% + 8px);inset-inline-start:0;width:min(400px,calc(100vw - 32px));max-height:70vh;overflow:auto;box-shadow:0 12px 40px rgba(0,0,0,.18)}',
    '.panel[hidden]{display:none}',
    '.head{display:flex;justify-content:flex-end}',
    '.close{all:unset;cursor:pointer;width:28px;height:28px;display:grid;place-items:center;border-radius:50%;color:var(--muted);font-size:20px;line-height:1}',
    '.close:hover,.close:focus-visible{background:rgba(0,0,0,.06)}',
    'section+section{margin-top:14px;padding-top:12px;border-top:1px solid var(--line)}',
    'h3{margin:0 0 8px;font-size:14px;font-weight:600;color:#111827}',
    'ul{margin:0;padding:0;list-style:none}',
    '.lines li{position:relative;padding-inline-start:18px;margin:4px 0}',
    '.lines li:before{content:"";position:absolute;inset-inline-start:2px;top:.6em;width:7px;height:7px;border-radius:50%;background:var(--accent)}',
    '.specs{display:grid;grid-template-columns:auto 1fr;gap:4px 14px;margin:0}',
    '.specs dt{color:var(--muted)}.specs dd{margin:0;font-weight:500}',
    '.cards{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:10px}',
    '.card{display:flex;flex-direction:column;gap:6px;padding:8px;border:1px solid var(--line);border-radius:10px;background:#fff}',
    '.card a{color:inherit;text-decoration:none}',
    '.card img{display:block;width:100%;aspect-ratio:1;object-fit:contain;background:#f6f6f7;border-radius:6px}',
    '.card .title{font-size:13px;line-height:1.35;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}',
    '.card .reason{font-size:12px;color:var(--muted);line-height:1.35}',
    '.price{font-weight:600;font-size:14px}.price del{display:inline-block;font-weight:400;color:var(--muted);margin-inline-start:8px;font-size:12px}',
    '.add{all:unset;box-sizing:border-box;margin-top:auto;text-align:center;padding:7px 8px;border-radius:8px;background:var(--accent);color:#fff;font-size:13px;cursor:pointer}',
    '.add[disabled]{opacity:.6;cursor:default}.add.secondary{background:transparent;color:var(--accent);border:1px solid var(--accent)}',
    '.status{font-size:12px;color:var(--muted)}.status a{color:var(--accent)}',
    '.guides a{display:flex;align-items:center;gap:10px;padding:6px 0;color:inherit;text-decoration:none}',
    '.guides img{width:56px;height:42px;object-fit:cover;border-radius:6px;flex:none;background:#f6f6f7}',
    '.guides span{font-size:14px}.guides a:hover span{text-decoration:underline}'
  ].join('');

  var SPARK = '<svg class="spark" viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M12 2l1.9 6.1L20 10l-6.1 1.9L12 18l-1.9-6.1L4 10l6.1-1.9zM19 15l.9 2.1L22 18l-2.1.9L19 21l-.9-2.1L16 18l2.1-.9z"/></svg>';
  var CHEVRON = '<svg class="chev" viewBox="0 0 24 24" aria-hidden="true"><path fill="none" stroke="currentColor" stroke-width="2.5" d="M6 9l6 6 6-6"/></svg>';

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

    var card = el('article', 'card');
    var url = safeUrl(data ? data.permalink : product.url);
    var link = el('a');
    if (url) {
      link.href = url;
    }
    link.addEventListener('click', function () { track('click', section, 'panel'); });

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

    var simple = data.type === 'simple' && data.is_purchasable && !data.has_options;
    if (!simple) {
      var choose = el('a', 'add secondary', labels.choose_options);
      if (url) {
        choose.href = url;
      }
      choose.addEventListener('click', function () { track('click', section, 'panel'); });
      card.appendChild(choose);
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
          refreshCartFragments();
          var cart = safeUrl(ctx.cartUrl);
          if (cart) {
            var view = el('a', null, labels.view_cart);
            view.href = cart;
            status.appendChild(view);
          }
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

  var decoder = document.createElement('textarea');
  function decodeEntities(value) {
    decoder.innerHTML = String(value || '');
    return decoder.value;
  }

  function renderSection(section, live, labels) {
    var node = el('section');
    node.appendChild(el('h3', null, section.title));

    if (section.lines) {
      var list = el('ul', 'lines');
      section.lines.forEach(function (line) {
        list.appendChild(el('li', null, line.text));
      });
      node.appendChild(list);
    }

    if (section.specs) {
      var specs = el('dl', 'specs');
      section.specs.forEach(function (row) {
        specs.appendChild(el('dt', null, row.label));
        specs.appendChild(el('dd', null, row.value));
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
    }

    if (section.guides) {
      var guides = el('ul', 'guides');
      section.guides.forEach(function (guide) {
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
        link.addEventListener('click', function () { track('click', section, 'panel'); });
        item.appendChild(link);
        guides.appendChild(item);
      });
      if (!guides.firstChild) {
        return null;
      }
      node.appendChild(guides);
    }

    return node;
  }

  /** Drops what live store data contradicts. Without live data, price claims are dropped. */
  function reconcile(live) {
    var current = live && live[PAGE_ID];

    bank.sections = bank.sections.map(function (section) {
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
      return lines.length ? Object.assign({}, section, { lines: lines }) : null;
    }).filter(Boolean);
  }

  function render(live) {
    var labels = bank.labels || {};
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
      wrap.appendChild(el('div', 'note', labels.preview));
    }

    var panelId = 'rega-panel';
    var panel = el('div', 'panel');
    panel.id = panelId;
    panel.hidden = true;
    panel.setAttribute('role', 'region');

    var head = el('div', 'head');
    var close = el('button', 'close', '×');
    close.type = 'button';
    close.setAttribute('aria-label', labels.close || 'Close');
    head.appendChild(close);
    panel.appendChild(head);

    var rendered = [];
    bank.sections.forEach(function (section) {
      var node = renderSection(section, live, labels);
      if (node) {
        panel.appendChild(node);
        rendered.push({ section: section, node: node });
      }
    });

    if (rendered.length === 0) {
      return;
    }

    var teaserSection = rendered[0].section;
    var teaserText = bank.teaser && bank.teaser.candidate === teaserSection.candidate
      ? (teaserSection.lines ? teaserSection.lines[0].text : bank.teaser.text)
      : teaserSection.title;

    var pill = el('button', 'pill');
    pill.type = 'button';
    pill.setAttribute('aria-expanded', 'false');
    pill.setAttribute('aria-controls', panelId);
    pill.innerHTML = SPARK;
    pill.appendChild(el('span', 'teaser', teaserText));
    pill.insertAdjacentHTML('beforeend', CHEVRON);
    pill.title = teaserText;

    wrap.appendChild(pill);
    wrap.appendChild(panel);
    root.appendChild(wrap);

    function setOpen(open, reason) {
      if (open === !panel.hidden) {
        return;
      }
      panel.hidden = !open;
      pill.setAttribute('aria-expanded', open ? 'true' : 'false');
      if (open) {
        track('open', teaserSection, 'teaser');
      } else {
        track('dismiss', teaserSection, 'teaser', { reason: reason });
        pill.focus();
      }
    }

    pill.addEventListener('click', function () { setOpen(panel.hidden, 'closed'); });
    close.addEventListener('click', function () { setOpen(false, 'closed'); });
    wrap.addEventListener('keydown', function (event) {
      if (event.key === 'Escape' && !panel.hidden) {
        setOpen(false, 'escape');
      }
    });

    if (!place(host)) {
      return;
    }

    watchExposure(pill, function (ms, ratio) {
      track('exposure', teaserSection, 'teaser', { visible_ms: ms, ratio: ratio });
    });
    rendered.forEach(function (item) {
      watchExposure(item.node, function (ms, ratio) {
        track('exposure', item.section, 'panel', { visible_ms: ms, ratio: ratio });
      });
    });
  }

  // ---------------------------------------------------------------- start

  function start() {
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

        // In preview mode real visitors only count as page views; the widget is for the team.
        if (!data.enabled || !data.sections || data.sections.length === 0 || (isPreviewMode && !teamPreview)) {
          return;
        }

        var ids = [];
        data.sections.forEach(function (section) {
          (section.products || []).forEach(function (product) { ids.push(String(product.id)); });
        });
        if (PAGE_TYPE === 'product') {
          ids.push(PAGE_ID);
        }

        return liveProducts(ids).then(function (live) {
          reconcile(live);
          render(live);
        });
      })
      .catch(function () { /* the page works without the widget */ });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start);
  } else {
    start();
  }
})();
