/* Cedar Tranquility — dependency-free progressive enhancement. */
(function(){'use strict';var d=document;
function on(el,ev,fn){if(el)el.addEventListener(ev,fn)}
/* Mobile navigation */
var toggle=d.querySelector('.mobile-toggle'),nav=toggle?d.getElementById(toggle.getAttribute('aria-controls')):null,overlay=d.querySelector('.nav-overlay');
var navScrollY=0;
function lockScroll(){
  /* Capture the offset before the body is frozen: making it position:fixed
     removes the document's scroll height, so window.scrollY reads 0 after. */
  navScrollY=window.scrollY||d.documentElement.scrollTop||0;
  d.body.style.top=(-navScrollY)+'px';
  d.body.classList.add('nav-scroll-locked');
}
function unlockScroll(){
  d.body.classList.remove('nav-scroll-locked');
  d.body.style.top='';
  var y=navScrollY;navScrollY=0;
  if(y)window.scrollTo(0,y);
}
function setNavState(open){
  if(!nav)return;
  if(open)lockScroll();
  nav.classList.toggle('open',open);
  if(overlay)overlay.classList.toggle('show',open);
  if(toggle){
    toggle.setAttribute('aria-expanded',open?'true':'false');
    toggle.setAttribute('aria-label',open?'Close navigation':'Open navigation');
  }
  d.documentElement.classList.toggle('nav-open',open);
  d.body.classList.toggle('dialog-open',open);
  if(!open)unlockScroll();
}
function closeNav(){setNavState(false)}
on(toggle,'click',function(){if(!nav)return;setNavState(!nav.classList.contains('open'))});
on(overlay,'click',closeNav);
/* The panel carries its own close button, so it works even when the panel
   covers the header toggle. */
on(nav?nav.querySelector('.main-nav-close'):null,'click',function(e){e.stopPropagation();closeNav()});
/* Close the menu when a destination is chosen. */
if(nav)nav.addEventListener('click',function(e){if(e.target.closest('a'))closeNav()});
/* Returning to a desktop width should not leave the page scroll-locked. */
on(window,'resize',function(){if(window.innerWidth>1024)closeNav()});
d.addEventListener('keydown',function(e){if(e.key==='Escape'){closeNav();closeGallery()}});
/* Back to top */
var topBtn=d.querySelector('.back-top');
function checkTop(){if(topBtn)topBtn.classList.toggle('visible',window.scrollY>650)}
on(window,'scroll',checkTop,{passive:true});checkTop();
on(topBtn,'click',function(){window.scrollTo({top:0,behavior:'smooth'})});
/* Toast */
var toastTimer=null;
function toast(msg){var t=d.querySelector('.toast');if(!t||!msg)return;t.textContent=msg;t.classList.add('show');clearTimeout(toastTimer);toastTimer=setTimeout(function(){t.classList.remove('show')},3200)}
/* Saved properties */
var KEY='cedar_saved',saved={};try{saved=JSON.parse(localStorage.getItem(KEY)||'{}')}catch(e){saved={}}
function persist(){try{localStorage.setItem(KEY,JSON.stringify(saved))}catch(e){}}
d.querySelectorAll('[data-save-property]').forEach(function(btn){var id=btn.getAttribute('data-save-property');if(saved[id])btn.setAttribute('aria-pressed','true');
on(btn,'click',function(){var active=btn.getAttribute('aria-pressed')==='true';btn.setAttribute('aria-pressed',active?'false':'true');if(active){delete saved[id];toast('Removed from your saved homes.')}else{saved[id]=1;toast('Saved. Our advisors can prepare a shortlist for you.')}persist()})});
/* Notice dismissal */
d.querySelectorAll('.notice-close').forEach(function(btn){on(btn,'click',function(){var n=btn.closest('.notice');if(n)n.remove()})});
/* Share + copy link */
d.querySelectorAll('[data-copy-link]').forEach(function(btn){on(btn,'click',function(){var url=btn.getAttribute('data-copy-link');function done(){toast('Property link copied.')}if(navigator.clipboard&&navigator.clipboard.writeText){navigator.clipboard.writeText(url).then(done,function(){fallback()})}else fallback();function fallback(){var i=d.createElement('input');i.value=url;d.body.appendChild(i);i.select();try{d.execCommand('copy');done()}catch(e){toast('Copy this link: '+url)}i.remove()}})});
/* Carousels */
d.querySelectorAll('[data-carousel]').forEach(function(wrap){var track=wrap.querySelector('.property-track');if(!track)return;var step=function(){var card=track.querySelector('.property-card');return card?card.getBoundingClientRect().width+27:320};
wrap.querySelectorAll('[data-scroll]').forEach(function(btn){on(btn,'click',function(){track.scrollBy({left:(btn.getAttribute('data-scroll')==='next'?1:-1)*step(),behavior:'smooth'})})})});
/* Password visibility */
d.querySelectorAll('.password-toggle').forEach(function(btn){on(btn,'click',function(){var input=d.getElementById(btn.getAttribute('data-target'));if(!input)return;var show=input.type==='password';input.type=show?'text':'password';btn.setAttribute('aria-label',show?'Hide password':'Show password');btn.setAttribute('aria-pressed',show?'true':'false')})});
/* Gallery dialog */
var gallery=d.querySelector('[data-gallery]'),dialog=d.getElementById('galleryDialog'),gImg=null,gCap=null,gIndex=0,gItems=[];
if(dialog){gImg=dialog.querySelector('img');gCap=dialog.querySelector('.gallery-caption')}
if(gallery&&dialog){gItems=Array.prototype.slice.call(gallery.querySelectorAll('[data-gallery-item]'));
function show(i){gIndex=(i+gItems.length)%gItems.length;var item=gItems[gIndex];if(gImg){gImg.src=item.getAttribute('data-full')||item.getAttribute('data-src')||'';gImg.alt=item.getAttribute('data-alt')||'Property photo'}if(gCap)gCap.innerHTML='<span>'+(gIndex+1)+' / '+gItems.length+'</span><small></small>',gCap.querySelector('small').textContent=item.getAttribute('data-alt')||''}
gItems.forEach(function(item,i){on(item,'click',function(e){if(item.tagName==='A')e.preventDefault();show(i);if(typeof dialog.showModal==='function'){dialog.showModal()}else{dialog.setAttribute('open','')}d.body.classList.add('dialog-open')})});
on(dialog.querySelector('.gallery-prev'),'click',function(){show(gIndex-1)});
on(dialog.querySelector('.gallery-next'),'click',function(){show(gIndex+1)});
on(dialog.querySelector('.gallery-close'),'click',closeGallery);
on(dialog,'click',function(e){if(e.target===dialog)closeGallery()});
dialog.addEventListener('keydown',function(e){if(e.key==='ArrowRight')show(gIndex+1);if(e.key==='ArrowLeft')show(gIndex-1)})}
function closeGallery(){if(!dialog||!dialog.hasAttribute('open'))return;if(typeof dialog.close==='function'){dialog.close()}else{dialog.removeAttribute('open')}d.body.classList.remove('dialog-open')}
/* Confirm destructive admin actions */
d.querySelectorAll('[data-confirm]').forEach(function(el){on(el,'submit',function(e){if(!window.confirm(el.getAttribute('data-confirm')))e.preventDefault()});if(el.tagName==='A'){on(el,'click',function(e){if(!window.confirm(el.getAttribute('data-confirm')))e.preventDefault()})}});
/* Enquiry intent preselect sync */
var intent=d.getElementById('enquiryIntent'),etype=d.getElementById('enquiryType');
on(intent,'change',function(){if(etype)etype.value=intent.value});
/* Current year */
var year=d.getElementById('currentYear');if(year)year.textContent=new Date().getFullYear();
})();
/* Admin sidebar drawer (phones and small tablets). */
(function(){'use strict';var d=document;
var toggle=d.querySelector('.admin-nav-toggle'),sidebar=d.getElementById('adminSidebar'),overlay=d.querySelector('.admin-sidebar-overlay'),closeBtn=d.querySelector('.admin-sidebar-close');
if(!toggle||!sidebar)return;
function setOpen(open){
  sidebar.classList.toggle('open',open);
  if(overlay){overlay.hidden=false;/* allow the transition to run */requestAnimationFrame(function(){overlay.classList.toggle('show',open)});}
  toggle.setAttribute('aria-expanded',open?'true':'false');
  toggle.setAttribute('aria-label',open?'Close admin menu':'Open admin menu');
  d.body.classList.toggle('dialog-open',open);
  d.documentElement.classList.toggle('admin-drawer-open',open);
  if(open&&closeBtn)closeBtn.focus();
}
function close(){setOpen(false);toggle.focus();}
toggle.addEventListener('click',function(){setOpen(!sidebar.classList.contains('open'))});
if(closeBtn)closeBtn.addEventListener('click',close);
if(overlay)overlay.addEventListener('click',close);
/* Choosing a destination closes the drawer. */
sidebar.addEventListener('click',function(e){if(e.target.closest('a'))setOpen(false);});
d.addEventListener('keydown',function(e){if(e.key==='Escape'&&sidebar.classList.contains('open'))close();});
/* Leaving the mobile range restores the normal layout. */
window.addEventListener('resize',function(){if(window.innerWidth>640&&sidebar.classList.contains('open'))setOpen(false);});
})();
/* Admin taxonomy inline editing (CSP-safe: no inline handlers in views). */
(function(){'use strict';var d=document;
d.querySelectorAll('[data-edit-taxonomy]').forEach(function(btn){btn.addEventListener('click',function(){
try{var item=JSON.parse(btn.getAttribute('data-edit-taxonomy'));
var set=function(id,val){var el=d.getElementById(id);if(el)el.value=val||''};
set('taxId',item.id);set('taxName',item.name);set('taxSlug',item.slug);set('taxDesc',item.description);set('taxIcon',item.icon);set('taxCity',item.city);
var title=d.getElementById('taxonomyFormTitle'),submit=d.getElementById('taxonomySubmit'),reset=d.getElementById('taxonomyReset');
if(title)title.textContent='Edit '+item.name;if(submit)submit.textContent='Save changes';if(reset)reset.hidden=false;
var name=d.getElementById('taxName');if(name){name.focus();name.scrollIntoView({behavior:'smooth',block:'center'})}
}catch(e){/* leave the add form untouched */}})});
var reset=d.getElementById('taxonomyReset');
if(reset)reset.addEventListener('click',function(){window.location.reload()});
})();

/* Enquiry confirmation dialog.
   Opens on load, fades in, and closes from the Okay button, the X, Escape, or
   a backdrop click. Focus is moved into the dialog and trapped there while it
   is open, then handed back to where it came from. CSP-safe: listeners only,
   no inline handlers. */
(function(){'use strict';
var dialog=document.getElementById('enquiryDialog');
if(!dialog)return;
var panel=dialog.querySelector('.confirm-dialog-panel');
var lastFocused=null,closing=false;
var FOCUSABLE='button,[href],input,select,textarea,[tabindex]:not([tabindex="-1"])';
function focusable(){return Array.prototype.slice.call(dialog.querySelectorAll(FOCUSABLE)).filter(function(el){return !el.disabled&&el.offsetParent!==null})}
function open(){
  lastFocused=document.activeElement;
  dialog.hidden=false;
  /* Force a reflow so the transition runs from the closed state. */
  void dialog.offsetWidth;
  dialog.classList.add('is-open');
  document.body.classList.add('dialog-open');
  /* Focus once the browser has finished settling the page: a deferred script
     runs before load completes, and the browser's own focus reset would
     otherwise take focus straight back to <body>. */
  var moveFocus=function(){
    var first=dialog.querySelector('.confirm-dialog-ok')||focusable()[0];
    if(first)first.focus();
  };
  if(document.readyState==='complete'){requestAnimationFrame(moveFocus)}
  else{window.addEventListener('load',function(){requestAnimationFrame(moveFocus)},{once:true})}
}
function close(){
  if(closing||dialog.hidden)return;
  closing=true;
  dialog.classList.remove('is-open');
  document.body.classList.remove('dialog-open');
  var done=function(){
    dialog.hidden=true;
    closing=false;
    if(lastFocused&&typeof lastFocused.focus==='function')lastFocused.focus();
  };
  /* Wait for the fade-out, but never leave the dialog stuck open. */
  var finished=false;
  var finish=function(){if(finished)return;finished=true;done()};
  panel.addEventListener('transitionend',finish,{once:true});
  setTimeout(finish,400);
}
dialog.querySelectorAll('[data-dialog-dismiss]').forEach(function(el){
  el.addEventListener('click',close);
});
document.addEventListener('keydown',function(e){
  if(dialog.hidden)return;
  if(e.key==='Escape'){e.preventDefault();close();return}
  if(e.key!=='Tab')return;
  var items=focusable();
  if(!items.length)return;
  var first=items[0],last=items[items.length-1];
  if(e.shiftKey&&document.activeElement===first){e.preventDefault();last.focus()}
  else if(!e.shiftKey&&document.activeElement===last){e.preventDefault();first.focus()}
});
open();
})();

/* ===========================================================================
   Motion system
   ---------------------------------------------------------------------------
   Dependency-free and progressive. Everything here is an enhancement: the
   document is complete and readable without it, and every effect switches
   itself off when the visitor has asked for reduced motion.

   Design notes:
   - Reveals use one shared IntersectionObserver and unobserve on first entry,
     so the cost falls as the page is read rather than rising.
   - Parallax is batched into a single requestAnimationFrame per scroll and
     writes only `transform`, so it never triggers layout.
   - Nothing here queries the DOM on every frame.
   =========================================================================== */
(function () {
  'use strict';
  var d = document;
  /* matchMedia is present in every browser we support, but partial or shimmed
     implementations can return undefined. Resolve to a harmless stub so the
     module never dereferences a missing MediaQueryList. */
  function media(query) {
    try {
      var mql = window.matchMedia && window.matchMedia(query);
      if (mql && typeof mql.matches === 'boolean') return mql;
    } catch (err) { /* fall through to the stub */ }
    return { matches: false, addEventListener: null, addListener: null };
  }
  var reduce = media('(prefers-reduced-motion: reduce)');

  /* Mark the document as scripted. The stylesheet only hides [data-reveal]
     under `.js`, so a visitor without JavaScript sees the finished page. */
  d.documentElement.classList.add('js');

  var supportsIO = 'IntersectionObserver' in window;

  /* One scroll listener for the whole module, batched into a single frame.
     Registered here so everything below can call onScroll() safely. */
  var scrollHandlers = [];
  function onScroll(fn) { scrollHandlers.push(fn); }

  /* ------------------------------------------------------- Scroll reveals */
  var revealItems = Array.prototype.slice.call(d.querySelectorAll('[data-reveal]'));

  /* A group staggers its own children, so markup only has to say "group". */
  d.querySelectorAll('[data-reveal-group]').forEach(function (group) {
    Array.prototype.forEach.call(group.children, function (child, i) {
      if (!child.hasAttribute('data-reveal')) child.setAttribute('data-reveal', group.getAttribute('data-reveal-group') || 'up');
      if (!child.hasAttribute('data-reveal-delay')) child.setAttribute('data-reveal-delay', String(i * 90));
      revealItems.push(child);
    });
  });

  function applyDelay(el) {
    var delay = el.getAttribute('data-reveal-delay');
    if (delay) el.style.setProperty('--reveal-delay', delay + 'ms');
  }
  revealItems.forEach(applyDelay);

  function showNow(el) {
    el.classList.add('is-in');
    var wrap = el.closest('.img-reveal');
    if (wrap) wrap.classList.add('is-in');
  }

  if (!reduce.matches && supportsIO) {
    var revealObserver = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (!entry.isIntersecting) return;
        showNow(entry.target);
        revealObserver.unobserve(entry.target);
      });
    }, { rootMargin: '0px 0px -12% 0px', threshold: 0.06 });
    revealItems.forEach(function (el) {
      /* Anything already on screen at load is revealed immediately: waiting for
         a scroll that may never come would leave the first screen blank. */
      var box = el.getBoundingClientRect();
      if (box.top < window.innerHeight && box.bottom > 0) { showNow(el); return; }
      revealObserver.observe(el);
    });
  } else {
    revealItems.forEach(showNow);
  }

  /* --------------------------------------------------- Reading progress bar */
  var progress = d.querySelector('.read-progress > span');
  var progressWrap = d.querySelector('.read-progress');
  if (progress && progressWrap) {
    var tickProgress = function () {
      var doc = d.documentElement;
      var max = doc.scrollHeight - window.innerHeight;
      var y = window.scrollY || doc.scrollTop || 0;
      var ratio = max > 0 ? Math.min(1, Math.max(0, y / max)) : 0;
      progress.style.transform = 'scaleX(' + ratio.toFixed(4) + ')';
      progressWrap.classList.toggle('is-visible', y > window.innerHeight * 0.6);
    };
    onScroll(tickProgress);
    tickProgress();
  }

  /* ----------------------------------------------------------- Header state */
  var header = d.querySelector('.site-header');
  if (header) {
    var lastState = null;
    var tickHeader = function () {
      var scrolled = (window.scrollY || 0) > 12;
      if (scrolled === lastState) return;
      lastState = scrolled;
      header.classList.toggle('is-scrolled', scrolled);
    };
    onScroll(tickHeader);
    tickHeader();
  }

  /* -------------------------------------------------------------- Parallax
     Elements may declare data-parallax="0.12" (positive drifts down, negative
     drifts up as the page scrolls). The offset is relative to the element's
     position in the viewport, so items settle rather than drift forever. */
  var parallaxItems = Array.prototype.slice.call(d.querySelectorAll('[data-parallax]'));
  if (parallaxItems.length && !reduce.matches) {
    var ticking = false;
    var applyParallax = function () {
      var vh = window.innerHeight;
      for (var i = 0; i < parallaxItems.length; i++) {
        var el = parallaxItems[i];
        var box = el.getBoundingClientRect();
        /* Skip anything far off screen: no point computing it. */
        if (box.bottom < -200 || box.top > vh + 200) continue;
        var strength = parseFloat(el.getAttribute('data-parallax')) || 0.1;
        var centre = box.top + box.height / 2 - vh / 2;
        var shift = centre * strength * -0.35;
        el.style.transform = 'translate3d(0,' + shift.toFixed(2) + 'px,0)';
      }
      ticking = false;
    };
    var requestParallax = function () {
      if (ticking) return;
      ticking = true;
      window.requestAnimationFrame(applyParallax);
    };
    window.addEventListener('scroll', requestParallax, { passive: true });
    window.addEventListener('resize', requestParallax, { passive: true });
    requestParallax();
  }

  /* --------------------------------------------------- Anchor smooth scroll
     Native `scroll-behavior: smooth` covers most cases; this adds the header
     offset for in-page links and moves focus for keyboard users, which a
     native smooth scroll does not do. */
  d.addEventListener('click', function (event) {
    var link = event.target.closest && event.target.closest('a[href^="#"]');
    if (!link) return;
    var id = link.getAttribute('href');
    if (!id || id === '#' || id.length < 2) return;
    var target = d.querySelector(id);
    if (!target) return;
    event.preventDefault();
    var top = target.getBoundingClientRect().top + window.scrollY - (header ? header.offsetHeight + 16 : 90);
    window.scrollTo({ top: top, behavior: reduce.matches ? 'auto' : 'smooth' });
    /* Focus without a second scroll, so screen readers land where the eye does. */
    if (!target.hasAttribute('tabindex')) target.setAttribute('tabindex', '-1');
    target.focus({ preventScroll: true });
    if (history.replaceState) history.replaceState(null, '', id);
  });

  /* ------------------------------------------------ Pointer-capable hovers
     Card tilt is skipped on touch, where there is no hover to respond to and
     the motion would fight the scroll. */
  var finePointer = media('(hover: hover) and (pointer: fine)');
  if (finePointer.matches && !reduce.matches) {
    d.querySelectorAll('[data-tilt]').forEach(function (card) {
      var rect = null;
      var raf = null;
      var move = function (event) {
        if (raf) return;
        raf = window.requestAnimationFrame(function () {
          raf = null;
          if (!rect) rect = card.getBoundingClientRect();
          var px = (event.clientX - rect.left) / rect.width - 0.5;
          var py = (event.clientY - rect.top) / rect.height - 0.5;
          var max = parseFloat(card.getAttribute('data-tilt')) || 5;
          card.style.transform =
            'perspective(900px) rotateX(' + (-py * max).toFixed(2) + 'deg) rotateY(' + (px * max).toFixed(2) + 'deg) translate3d(0,-6px,0)';
        });
      };
      card.addEventListener('pointerenter', function () { rect = card.getBoundingClientRect(); });
      card.addEventListener('pointermove', move);
      card.addEventListener('pointerleave', function () {
        if (raf) { window.cancelAnimationFrame(raf); raf = null; }
        card.style.transform = '';
        rect = null;
      });
    });
  }

  /* -------------------------------------------------- Scroll spy for in-page
     navigation: marks the link whose section is currently in view. */
  var spyLinks = Array.prototype.slice.call(d.querySelectorAll('[data-spy] a[href^="#"]'));
  if (spyLinks.length && supportsIO) {
    var sections = spyLinks.map(function (a) { return d.querySelector(a.getAttribute('href')); }).filter(Boolean);
    if (sections.length) {
      var spy = new IntersectionObserver(function (entries) {
        entries.forEach(function (entry) {
          if (!entry.isIntersecting) return;
          spyLinks.forEach(function (a) {
            a.classList.toggle('active', a.getAttribute('href') === '#' + entry.target.id);
          });
        });
      }, { rootMargin: '-45% 0px -50% 0px' });
      sections.forEach(function (s) { spy.observe(s); });
    }
  }


  /* One rAF-batched listener drives every scroll-dependent effect above. */
  if (scrollHandlers.length) {
    var scrollTick = false;
    window.addEventListener('scroll', function () {
      if (scrollTick) return;
      scrollTick = true;
      window.requestAnimationFrame(function () {
        scrollTick = false;
        for (var i = 0; i < scrollHandlers.length; i++) scrollHandlers[i]();
      });
    }, { passive: true });
    window.addEventListener('resize', function () {
      for (var i = 0; i < scrollHandlers.length; i++) scrollHandlers[i]();
    }, { passive: true });
  }

  /* Re-check when the visitor changes the preference mid-session. */
  if (reduce.addEventListener) {
    reduce.addEventListener('change', function (e) {
      if (e.matches) revealItems.forEach(showNow);
    });
  }
})();
