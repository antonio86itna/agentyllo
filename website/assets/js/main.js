/* Agentyllo — agentyllo.com interactions. Vanilla, dependency-free. */
(function () {
	'use strict';
	var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

	/* ---- nav: scrolled state + scroll progress ---- */
	var nav = document.getElementById('nav');
	var progress = document.getElementById('progress');
	function onScroll() {
		var y = window.scrollY || document.documentElement.scrollTop;
		if (nav) nav.classList.toggle('scrolled', y > 20);
		if (progress) {
			var h = document.documentElement.scrollHeight - window.innerHeight;
			progress.style.width = (h > 0 ? (y / h) * 100 : 0) + '%';
		}
	}
	window.addEventListener('scroll', onScroll, { passive: true });
	onScroll();

	/* ---- mobile menu ---- */
	var burger = document.getElementById('burger');
	if (burger) {
		burger.addEventListener('click', function () {
			var open = nav.classList.toggle('open');
			burger.setAttribute('aria-expanded', open ? 'true' : 'false');
		});
		document.querySelectorAll('#navLinks a').forEach(function (a) {
			a.addEventListener('click', function () { nav.classList.remove('open'); burger.setAttribute('aria-expanded', 'false'); });
		});
	}

	/* ---- scroll reveal ---- */
	var reveals = document.querySelectorAll('.reveal');
	if (reduce || !('IntersectionObserver' in window)) {
		reveals.forEach(function (el) { el.classList.add('in'); });
	} else {
		var io = new IntersectionObserver(function (entries) {
			entries.forEach(function (e) {
				if (e.isIntersecting) { e.target.classList.add('in'); io.unobserve(e.target); }
			});
		}, { threshold: 0.15, rootMargin: '0px 0px -8% 0px' });
		reveals.forEach(function (el) { io.observe(el); });
	}

	/* ---- animated counters ---- */
	var counters = document.querySelectorAll('[data-count]');
	function runCounter(el) {
		var target = parseInt(el.getAttribute('data-count'), 10);
		if (reduce || isNaN(target)) { el.textContent = target || el.textContent; return; }
		var start = null, dur = 1200;
		function tick(ts) {
			if (!start) start = ts;
			var p = Math.min((ts - start) / dur, 1);
			var eased = 1 - Math.pow(1 - p, 3);
			el.textContent = Math.round(target * eased);
			if (p < 1) requestAnimationFrame(tick);
		}
		requestAnimationFrame(tick);
	}
	if ('IntersectionObserver' in window) {
		var cio = new IntersectionObserver(function (entries) {
			entries.forEach(function (e) { if (e.isIntersecting) { runCounter(e.target); cio.unobserve(e.target); } });
		}, { threshold: 0.6 });
		counters.forEach(function (el) { cio.observe(el); });
	} else {
		counters.forEach(runCounter);
	}

	/* ---- operating modes toggle ---- */
	var MODES = {
		classic: { on: ['classic'], title: 'Classic — instant & reliable', desc: 'Pure-PHP agents answer from your content with no AI model at all. Zero cost, zero setup, runs anywhere. This is the floor every other mode builds on.' },
		free: { on: ['classic', 'local'], title: 'Free AI — local & private', desc: 'A local engine you run (llama.cpp / Ollama) or the free Local AI add-on adds natural, streamed prose. Nothing leaves your server, no provider fees.' },
		paid: { on: ['classic', 'cloud'], title: 'Paid AI — your own key', desc: 'Connect your OpenAI or Anthropic key for top-tier answers. You pay the provider directly, with a monthly cost cap and automatic fallback to classic.' },
		cfree: { on: ['classic', 'local'], title: 'Classic + Free AI', desc: 'Deterministic classic answers for facts and navigation, local AI prose for the open-ended questions — the best of both, entirely on your infrastructure.' },
		cpaid: { on: ['classic', 'cloud'], title: 'Classic + Paid AI', desc: 'Classic handles prices, stock and links verbatim; your cloud provider writes the natural language around them. Maximum quality, facts still guaranteed.' }
	};
	var toggle = document.getElementById('modesToggle');
	var mTitle = document.getElementById('modeTitle');
	var mDesc = document.getElementById('modeDesc');
	var engines = document.querySelectorAll('.engine');
	function setMode(mode) {
		var cfg = MODES[mode]; if (!cfg) return;
		document.querySelectorAll('.mode-btn').forEach(function (b) {
			b.setAttribute('aria-selected', b.getAttribute('data-mode') === mode ? 'true' : 'false');
		});
		engines.forEach(function (e) { e.classList.toggle('on', cfg.on.indexOf(e.getAttribute('data-eng')) !== -1); });
		mTitle.textContent = cfg.title;
		mDesc.textContent = cfg.desc;
	}
	if (toggle) {
		toggle.addEventListener('click', function (e) {
			var btn = e.target.closest('.mode-btn'); if (btn) setMode(btn.getAttribute('data-mode'));
		});
		setMode('classic');
	}

	/* ---- chat demo (typewriter) ---- */
	var chat = document.getElementById('chat');
	var demoScript = [
		{ who: 'bot', text: 'Hi! Ask me anything about this site.' },
		{ who: 'user', text: 'How much does express delivery cost?' },
		{ who: 'bot', text: 'Express delivery in 24 hours costs 9 euro and is available for all of Italy. Delivery to Italy takes 2-3 working days.', cite: 'Shipping & Returns' }
	];
	function addBubble(entry, typed) {
		var b = document.createElement('div');
		b.className = 'bubble ' + entry.who;
		chat.appendChild(b);
		if (!typed || reduce) {
			b.textContent = entry.text;
			if (entry.cite) { var c = document.createElement('span'); c.className = 'cite'; c.textContent = '↳ ' + entry.cite; b.appendChild(document.createElement('br')); b.appendChild(c); }
			return Promise.resolve();
		}
		b.classList.add('cursor');
		return new Promise(function (resolve) {
			var i = 0;
			(function step() {
				b.firstChild ? (b.childNodes[0].nodeValue = entry.text.slice(0, i)) : b.appendChild(document.createTextNode(entry.text.slice(0, i)));
				i++;
				if (i <= entry.text.length) { setTimeout(step, 18); }
				else {
					b.classList.remove('cursor');
					if (entry.cite) { var c = document.createElement('span'); c.className = 'cite'; c.textContent = '↳ ' + entry.cite; b.appendChild(document.createElement('br')); b.appendChild(c); }
					resolve();
				}
			})();
		});
	}
	function playDemo() {
		if (!chat) return;
		chat.innerHTML = '';
		var seq = Promise.resolve();
		demoScript.forEach(function (entry) {
			seq = seq.then(function () { return new Promise(function (r) { setTimeout(r, entry.who === 'user' ? 500 : 350); }); })
				.then(function () { return addBubble(entry, entry.who === 'bot'); });
		});
	}
	if (chat) {
		if (reduce || !('IntersectionObserver' in window)) {
			demoScript.forEach(function (e) { addBubble(e, false); });
		} else {
			var dio = new IntersectionObserver(function (entries) {
				entries.forEach(function (e) { if (e.isIntersecting) { playDemo(); dio.unobserve(e.target); } });
			}, { threshold: 0.4 });
			dio.observe(chat);
		}
	}
})();
