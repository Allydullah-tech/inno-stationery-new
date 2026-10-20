/* INNO's Stationery — landing page interactions (vanilla JS, no dependencies) */
(function () {
    'use strict';
    var reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var $ = function (s, c) { return (c || document).querySelector(s); };

    /* footer year */
    var yr = $('#year'); if (yr) yr.textContent = new Date().getFullYear();

    /* nav: shadow on scroll, progress bar, back-to-top */
    var nav = $('#nav'), prog = $('#scrollProgress'), toTop = $('#toTop');
    function onScroll() {
        var y = window.pageYOffset, h = document.documentElement.scrollHeight - window.innerHeight;
        nav.classList.toggle('scrolled', y > 20);
        prog.style.width = (h > 0 ? (y / h) * 100 : 0) + '%';
        toTop.classList.toggle('show', y > 600);
    }
    window.addEventListener('scroll', onScroll, { passive: true }); onScroll();
    toTop.addEventListener('click', function () { window.scrollTo({ top: 0, behavior: reduce ? 'auto' : 'smooth' }); });

    /* mobile menu */
    var burger = $('#burger'), links = $('#navLinks');
    function closeMenu() { links.classList.remove('open'); burger.setAttribute('aria-expanded', 'false'); }
    burger.addEventListener('click', function () {
        var open = links.classList.toggle('open');
        burger.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
    links.addEventListener('click', function (e) { if (e.target.closest('a')) closeMenu(); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeMenu(); });

    /* rotating headline word with typing effect */
    var words = ['clarity', 'confidence', 'speed', 'control'];
    var rot = $('#rotator .rot-word');
    if (rot && !reduce) {
        var wi = 0, ci = words[0].length, deleting = false;
        (function tick() {
            var w = words[wi], delay = deleting ? 55 : 110;
            if (!deleting && ci === w.length) { deleting = true; setTimeout(tick, 1800); return; }
            ci += deleting ? -1 : 1;
            rot.textContent = w.slice(0, ci);
            if (deleting && ci === 0) { deleting = false; wi = (wi + 1) % words.length; delay = 350; }
            setTimeout(tick, delay);
        })();
    }

    /* count-up numbers */
    function fmt(n) { return Math.round(n).toLocaleString('en-US'); }
    function countUp(el) {
        var target = parseFloat(el.getAttribute('data-count')), prefix = el.getAttribute('data-prefix') || '';
        if (reduce) { el.textContent = prefix + fmt(target); return; }
        var dur = 1800, start = null, run = (el._run = (el._run || 0) + 1);
        function step(ts) {
            if (el._run !== run) return;   // a newer animation took over
            if (!start) start = ts;
            var p = Math.min((ts - start) / dur, 1), eased = 1 - Math.pow(1 - p, 4);
            el.textContent = prefix + fmt(target * eased);
            if (p < 1) requestAnimationFrame(step);
        }
        requestAnimationFrame(step);
    }

    /* real numbers for today's summary card (public totals only) */
    (function loadStats() {
        if (!window.fetch) return;
        fetch('../backend/api/public_stats.php').then(function (r) { return r.json(); }).then(function (d) {
            if (!d || !d.success) return;
            [['statTotal', d.total_sales], ['statItems', d.items_sold], ['statCount', d.sales_count]].forEach(function (x) {
                var el = document.getElementById(x[0]); if (!el) return;
                el.setAttribute('data-count', x[1]);
                var card = el.closest('.reveal');
                if (!card || card.classList.contains('in')) countUp(el);   // card already on screen -> animate now
            });
        }).catch(function () {});
    })();

    /* reveal on scroll + triggers */
    var steps = $('.steps'), fill = $('#stepsFill'), bars = $('#bars');
    function activate(el) {
        el.classList.add('in');
        el.querySelectorAll('[data-count]').forEach(countUp);
        if (el.matches('[data-count]')) countUp(el);
    }
    if ('IntersectionObserver' in window) {
        var io = new IntersectionObserver(function (entries) {
            entries.forEach(function (en) {
                if (!en.isIntersecting) return;
                activate(en.target); io.unobserve(en.target);
            });
        }, { threshold: .15, rootMargin: '0px 0px -40px 0px' });
        document.querySelectorAll('.reveal').forEach(function (el) { io.observe(el); });

        if (steps && fill) {
            new IntersectionObserver(function (en, ob) {
                if (en[0].isIntersecting) { fill.style.width = '100%'; ob.disconnect(); }
            }, { threshold: .4 }).observe(steps);
        }
    } else {
        document.querySelectorAll('.reveal').forEach(activate);
        if (fill) fill.style.width = '100%';
    }
    if (bars) setTimeout(function () { bars.classList.add('go'); }, 700);

    /* 3D tilt on the dashboard preview (desktop pointers only) */
    var wrap = $('#tiltWrap'), card = $('#tiltCard');
    if (wrap && card && !reduce && window.matchMedia('(hover: hover)').matches) {
        wrap.addEventListener('mousemove', function (e) {
            var r = wrap.getBoundingClientRect();
            var x = (e.clientX - r.left) / r.width - .5, y = (e.clientY - r.top) / r.height - .5;
            card.style.transform = 'rotateY(' + (x * 10) + 'deg) rotateX(' + (-y * 10) + 'deg)';
        });
        wrap.addEventListener('mouseleave', function () { card.style.transform = ''; });
    }

    /* close mobile menu if resized to desktop */
    window.addEventListener('resize', function () { if (window.innerWidth > 860) closeMenu(); });
})();
