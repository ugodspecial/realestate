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
window.addEventListener('resize',function(){if(window.innerWidth>1024&&sidebar.classList.contains('open'))setOpen(false);});
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
