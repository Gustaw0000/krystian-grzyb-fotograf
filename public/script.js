(function () {
  'use strict';

  var doc = document;
  var root = doc.documentElement;
  var body = doc.body;
  var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  /* ---- Current year ---- */
  var yearEl = doc.getElementById('year');
  if (yearEl) yearEl.textContent = String(new Date().getFullYear());

  /* ---- Hero intro reveal ---- */
  function startHero() {
    root.classList.add('hero-ready');
    root.classList.remove('intro-pending');
  }
  if (reduceMotion) {
    startHero();
  } else {
    window.requestAnimationFrame(function () {
      window.setTimeout(startHero, 60);
    });
  }

  /* ---- Reveal on scroll (with hero cascade) ---- */
  var reveals = Array.prototype.slice.call(doc.querySelectorAll('.reveal'));
  // Stagger hero items for the intro cascade
  var heroReveals = Array.prototype.slice.call(doc.querySelectorAll('.hero .reveal'));
  heroReveals.forEach(function (el, i) {
    el.style.setProperty('--reveal-delay', (260 + i * 90) + 'ms');
  });

  if ('IntersectionObserver' in window && !reduceMotion) {
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (entry.isIntersecting) {
          entry.target.classList.add('is-visible');
          io.unobserve(entry.target);
        }
      });
    }, { threshold: 0.12, rootMargin: '0px 0px -8% 0px' });
    reveals.forEach(function (el) { io.observe(el); });
  } else {
    reveals.forEach(function (el) { el.classList.add('is-visible'); });
  }

  /* ---- Header scroll state ---- */
  var header = doc.getElementById('top-header');
  function onScroll() {
    if (!header) return;
    if (window.scrollY > 40) header.classList.add('scrolled');
    else header.classList.remove('scrolled');
  }
  onScroll();
  window.addEventListener('scroll', onScroll, { passive: true });

  /* ---- Smooth scroll for in-page anchors ---- */
  var headerH = 76;
  doc.querySelectorAll('a[href^="#"]').forEach(function (link) {
    link.addEventListener('click', function (e) {
      var hash = link.getAttribute('href');
      if (!hash || hash === '#') return;
      if (hash === '#top' || hash === '#main') {
        e.preventDefault();
        closeNav();
        window.scrollTo({ top: 0, behavior: reduceMotion ? 'auto' : 'smooth' });
        if (hash === '#main') { var m = doc.getElementById('main'); if (m) m.focus({ preventScroll: true }); }
        return;
      }
      var target = doc.querySelector(hash);
      if (!target) return;
      e.preventDefault();
      closeNav();
      var top = target.getBoundingClientRect().top + window.scrollY - (header ? header.offsetHeight : headerH) - 8;
      window.scrollTo({ top: top, behavior: reduceMotion ? 'auto' : 'smooth' });
    });
  });

  /* ---- Mobile nav ---- */
  var navToggle = doc.querySelector('.nav-toggle');
  var mobileNav = doc.getElementById('mobile-nav');

  function bgInert(on) {
    // ukryj tlo (tresc + stopke) przed czytnikiem i klawiatura gdy menu otwarte
    var regions = [doc.getElementById('main'), doc.querySelector('.site-footer')];
    regions.forEach(function (el) {
      if (!el) return;
      if (on) { el.setAttribute('inert', ''); el.setAttribute('aria-hidden', 'true'); }
      else { el.removeAttribute('inert'); el.removeAttribute('aria-hidden'); }
    });
  }
  function openNav() {
    if (!mobileNav) return;
    mobileNav.hidden = false;
    window.requestAnimationFrame(function () {
      body.classList.add('is-nav-open');
    });
    if (navToggle) {
      navToggle.setAttribute('aria-expanded', 'true');
      navToggle.setAttribute('aria-label', 'Zamknij menu');
    }
    bgInert(true);
    var first = mobileNav.querySelector('a, button');
    if (first) first.focus();
  }
  function closeNav() {
    if (!body.classList.contains('is-nav-open')) return;
    body.classList.remove('is-nav-open');
    if (navToggle) {
      navToggle.setAttribute('aria-expanded', 'false');
      navToggle.setAttribute('aria-label', 'Otwórz menu');
      navToggle.focus();
    }
    bgInert(false);
    window.setTimeout(function () {
      if (!body.classList.contains('is-nav-open') && mobileNav) mobileNav.hidden = true;
    }, 320);
  }
  if (navToggle) {
    navToggle.addEventListener('click', function () {
      if (body.classList.contains('is-nav-open')) closeNav();
      else openNav();
    });
  }
  if (mobileNav) {
    mobileNav.querySelectorAll('a').forEach(function (a) {
      a.addEventListener('click', function () { closeNav(); });
    });
  }
  doc.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') closeNav();
  });
  window.addEventListener('resize', function () {
    if (window.innerWidth > 860) closeNav();
  });

  /* ---- web3forms submit ---- */
  var form = doc.getElementById('zgloszenie');
  if (form && window.fetch && window.FormData) {
    var statusEl = form.querySelector('.form-status');
    var submitBtn = form.querySelector('button[type="submit"]');
    var defaultLabel = submitBtn ? submitBtn.textContent : 'Wyślij';
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      if (statusEl) { statusEl.className = 'form-status is-pending'; statusEl.textContent = 'Wysyłam...'; }
      if (submitBtn) { submitBtn.disabled = true; submitBtn.style.opacity = '0.7'; submitBtn.textContent = 'Wysyłam...'; }
      var data = new FormData(form);
      fetch('https://api.web3forms.com/submit', {
        method: 'POST',
        headers: { 'Accept': 'application/json' },
        body: data
      }).then(function (res) {
        return res.json().catch(function () { return {}; }).then(function (json) {
          return { ok: res.ok, json: json };
        });
      }).then(function (r) {
        if (r.ok) {
          if (statusEl) { statusEl.className = 'form-status is-ok'; statusEl.textContent = 'Dziękuję. Odezwę się najszybciej, jak się da.'; }
          form.reset();
        } else {
          var msg = (r.json && r.json.message) ? r.json.message : 'Coś poszło nie tak. Zadzwoń albo spróbuj ponownie.';
          if (statusEl) { statusEl.className = 'form-status is-err'; statusEl.textContent = msg; }
        }
      }).catch(function () {
        if (statusEl) { statusEl.className = 'form-status is-err'; statusEl.textContent = 'Brak połączenia. Zadzwoń albo spróbuj ponownie.'; }
      }).then(function () {
        if (submitBtn) { submitBtn.disabled = false; submitBtn.style.opacity = ''; submitBtn.textContent = defaultLabel; }
      });
    });
  }

  /* ---- Cookies banner ---- */
  var COOKIE_KEY = 'kg-cookies-ack';
  var acked = false;
  try { acked = window.localStorage.getItem(COOKIE_KEY) === '1'; } catch (err) { acked = false; }
  if (!acked) {
    var banner = doc.createElement('div');
    banner.className = 'cookies';
    banner.setAttribute('role', 'region');
    banner.setAttribute('aria-label', 'Informacja o plikach cookies');
    banner.innerHTML =
      '<p>Ta strona używa wyłącznie technicznych plików potrzebnych do działania. Bez analityki, bez śledzenia, bez reklam.</p>' +
      '<div class="cookies-actions"><a href="/polityka-prywatnosci">Polityka prywatności</a>' +
      '<button type="button" class="btn btn-primary cookies-ok">Rozumiem</button></div>';
    body.appendChild(banner);
    window.setTimeout(function () { banner.classList.add('is-visible'); }, 800);
    banner.querySelector('.cookies-ok').addEventListener('click', function () {
      banner.classList.remove('is-visible');
      try { window.localStorage.setItem(COOKIE_KEY, '1'); } catch (err) {}
      window.setTimeout(function () { if (banner.parentNode) banner.parentNode.removeChild(banner); }, 300);
    });
  }

  /* ---- Soft-dot cursor (desktop fine pointer only) ---- */
  var canHover = window.matchMedia && window.matchMedia('(hover: hover) and (pointer: fine) and (min-width: 1024px)').matches;
  if (canHover && !reduceMotion) {
    var dot = doc.querySelector('.cursor-dot');
    if (dot) {
      var dx = 0, dy = 0, raf = null;
      function render() { dot.style.transform = 'translate(' + dx + 'px,' + dy + 'px)'; raf = null; }
      window.addEventListener('mousemove', function (e) {
        dx = e.clientX; dy = e.clientY;
        dot.classList.add('is-active');
        if (!raf) raf = window.requestAnimationFrame(render);
      });
      window.addEventListener('mouseout', function (e) {
        if (!e.relatedTarget) dot.classList.remove('is-active');
      });
      var hotSel = 'a, button, summary, input, textarea, .gphoto, .svc-card';
      doc.addEventListener('mouseover', function (e) {
        if (e.target.closest && e.target.closest(hotSel)) dot.classList.add('is-hot');
      });
      doc.addEventListener('mouseout', function (e) {
        if (e.target.closest && e.target.closest(hotSel)) dot.classList.remove('is-hot');
      });
    }
  }
})();
