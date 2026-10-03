document.addEventListener('DOMContentLoaded',()=>{
 const toggle=document.querySelector('.menu-toggle'),nav=document.querySelector('.main-nav');
 if(toggle&&nav){toggle.addEventListener('click',()=>{const open=nav.classList.toggle('open');toggle.setAttribute('aria-expanded',open?'true':'false');});nav.querySelectorAll('a').forEach(a=>a.addEventListener('click',()=>{nav.classList.remove('open');toggle.setAttribute('aria-expanded','false');}));}

 const dateEl=document.getElementById('nx21-date');
 const timeEl=document.getElementById('nx21-time');
 const updateDateTime=()=>{
   const now=new Date();
   const dateText=new Intl.DateTimeFormat('id-ID',{weekday:'long',day:'numeric',month:'long',year:'numeric',timeZone:'Asia/Jakarta'}).format(now);
   const timeText=new Intl.DateTimeFormat('id-ID',{hour:'2-digit',minute:'2-digit',second:'2-digit',hour12:false,timeZone:'Asia/Jakarta'}).format(now);
   if(dateEl) dateEl.textContent=dateText;
   if(timeEl) timeEl.textContent=timeText+' WIB';
 };
 updateDateTime();
 setInterval(updateDateTime,1000);
 const targets=document.querySelectorAll('.section-head,.results-preview>*,.result-card,.feature-card,.class-card,.poll-card,.timeline-item,.timeline-event-card,.gallery-card,.member,.story-list article,.quote-card,.about-facts div,.contact-card,.stats-grid>div,.empty-card,.rich-copy');
 if('IntersectionObserver' in window){
  const io=new IntersectionObserver((es)=>es.forEach(e=>{if(e.isIntersecting){e.target.classList.add('rv-in');io.unobserve(e.target);}}),{threshold:.12,rootMargin:'0px 0px -40px 0px'});
  targets.forEach((el,i)=>{el.classList.add('rv');el.style.setProperty('--d',((i%4)*0.08)+'s');io.observe(el);});
 }
});
