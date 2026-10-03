/* Toggle tema terang/gelap - dipakai admin, halaman polling, dan nexadipta-21 */
(function(){
  var KEY='sp-theme',root=document.documentElement,saved=null;
  try{saved=localStorage.getItem(KEY);}catch(e){}
  var mq=window.matchMedia?window.matchMedia('(prefers-color-scheme: dark)'):null;
  root.setAttribute('data-theme',saved||(mq&&mq.matches?'dark':'light'));
  var SUN='<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg>';
  var MOON='<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/></svg>';
  function paint(b){var d=root.getAttribute('data-theme')==='dark';b.innerHTML=(d?SUN:MOON)+'<span>'+(d?'Mode Terang':'Mode Malam')+'</span>';b.setAttribute('aria-label',d?'Ganti ke mode terang':'Ganti ke mode malam');}
  document.addEventListener('DOMContentLoaded',function(){
    var b=document.createElement('button');b.type='button';b.className='theme-toggle';paint(b);
    b.addEventListener('click',function(){var n=root.getAttribute('data-theme')==='dark'?'light':'dark';root.setAttribute('data-theme',n);try{localStorage.setItem(KEY,n);}catch(e){}paint(b);});
    document.body.appendChild(b);
  });
})();
