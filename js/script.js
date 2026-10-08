// GameMatch theme preference — robust toggle with persistence.
(function(){const key='gamematch-theme';function apply(){let saved='dark';try{saved=localStorage.getItem(key)||'dark'}catch(e){}document.documentElement.classList.toggle('light-theme',saved==='light');const btn=document.getElementById('themeToggle');if(btn){const light=document.documentElement.classList.contains('light-theme');btn.textContent=light?'🌙':'☀️';btn.title=light?'Switch to dark theme':'Switch to light theme';btn.setAttribute('aria-label',btn.title)}}apply();document.addEventListener('DOMContentLoaded',()=>{apply();const btn=document.getElementById('themeToggle');if(btn&&!btn.dataset.bound){btn.dataset.bound='1';btn.addEventListener('click',()=>{const light=!document.documentElement.classList.contains('light-theme');document.documentElement.classList.toggle('light-theme',light);try{localStorage.setItem(key,light?'light':'dark')}catch(e){}apply()})}})})();

document.addEventListener('DOMContentLoaded', () => {
    const password = document.querySelector('#password');
    if (password) {
        password.addEventListener('input', () => {
            // Native HTML minlength validation handles the password requirement.
            password.setCustomValidity('');
        });
    }
    document.querySelectorAll('img[data-fallback]').forEach(img => {
        img.addEventListener('error', () => {
            const fallback = img.dataset.fallback;
            if (fallback && img.src !== fallback) img.src = fallback;
        }, {once:true});
    });
});


// GameMatch live Steam India price
(async function loadLiveSteamPrices(){
  const cards = document.querySelectorAll('[data-steam-app-id]');
  for (const card of cards) {
    const appid = card.getAttribute('data-steam-app-id');
    const priceEl = card.querySelector('.live-steam-price');
    if (!appid || !priceEl) continue;
    try {
      const res = await fetch('api/steam-price.php?appid=' + encodeURIComponent(appid), {headers:{'Accept':'application/json'}});
      const data = await res.json();
      if (data.ok && data.available) {
        priceEl.textContent = data.final_formatted || (data.free ? 'Free' : 'Price unavailable');
        const note = card.querySelector('.live-steam-note');
        if (note && data.discount_percent > 0 && data.initial_formatted) {
          note.textContent = data.initial_formatted + ' • ' + data.discount_percent + '% off • Live Steam India price';
        }
        const link = card.querySelector('.live-steam-link');
        if (link && data.url) link.href = data.url;
      } else {
        priceEl.textContent = 'Price unavailable';
      }
    } catch (err) {
      priceEl.textContent = 'Price unavailable';
    }
  }
})();


// Refresh normalized store offers in the background. The existing catalog offers
// remain visible immediately; this replaces them when live sources respond.
(async function refreshGameOffers(){
  const section=document.querySelector('.buy-section[data-game-id]');
  const grid=section?.querySelector('.live-offers-grid');
  if(!section||!grid)return;
  const gameId=section.getAttribute('data-game-id');
  try{
    const res=await fetch('api/refresh-offers.php?game_id='+encodeURIComponent(gameId),{headers:{'Accept':'application/json'}});
    const data=await res.json();
    if(!data.ok||!Array.isArray(data.offers)||!data.offers.length)return;
    const icon=(name)=>({Steam:'🟦','Epic Games Store':'⬛','PlayStation Store':'🔵','Xbox Store':'🟩','Google Play':'▶️'}[name]||'🛒');
    const money=(n)=>Number(n)<=0?'Free':'₹'+Number(n).toLocaleString('en-IN',{maximumFractionDigits:0});
    grid.innerHTML=data.offers.map((o,i)=>{
      const cheapest=i===0;
      const original=(o.original_price&&Number(o.original_price)>Number(o.price))?`<del>${money(o.original_price)}</del>`:'';
      return `<div class="offer-card ${cheapest?'cheapest':''}"><div><span>${icon(o.store_name)} ${escapeHtml(o.store_name)}</span><small>${escapeHtml(o.platform||'')}</small></div><strong>${money(o.price)}</strong>${original}<a class="btn btn-small btn-ghost" href="${escapeAttr(o.url)}" target="_blank" rel="noopener">Open Store ↗</a></div>`;
    }).join('');
    const best=data.offers[0], bestBox=section.querySelector('.live-best-deal');
    if(bestBox){bestBox.hidden=false;bestBox.innerHTML=`<div><span>🏆 Live cheapest listed offer</span><strong>${icon(best.store_name)} ${escapeHtml(best.store_name)} — ${money(best.price)}</strong><small>Updated from available store-price sources.</small></div><a class="btn" href="${escapeAttr(best.url)}" target="_blank" rel="noopener">Buy at cheapest store ↗</a>`;}
  }catch(e){/* Keep the existing catalog snapshot visible if live refresh fails. */}
  function escapeHtml(v){return String(v??'').replace(/[&<>'"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[c]));}
  function escapeAttr(v){return escapeHtml(v);}
})();
