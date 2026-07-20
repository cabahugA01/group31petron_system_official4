

const $ = (q, el=document)=>el.querySelector(q);
const $$ = (q, el=document)=>Array.from(el.querySelectorAll(q));

function fmtMoney(n){
  const x = Number(n||0);
  return '₱' + x.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
}
function fmtInt(n){ return Number(n||0).toLocaleString(); }
function fmtL(n){
  const x = Number(n||0);
  return x.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' L';
}

async function api(url, opts={}){
  // Block backend/inventory.php calls on PHP-rendered inventory page
  const isPHPInventory = document.querySelector('.page-head[data-rendering="php"]');
  if (isPHPInventory && url.includes('backend/inventory.php')) {
    console.warn('BLOCKED: API call to backend/inventory.php on PHP-rendered inventory page');
    return null;
  }

  const res = await fetch(url, {
    headers: {'Content-Type':'application/json'},
    credentials: 'same-origin',
    ...opts
  });
  const data = await res.json().catch(()=>null);
  
  // Silently return null for 404 errors (not found)
  if(res.status === 404) {
    return null;
  }
  
  if(!res.ok || !data || data.ok === false){
    const msg = (data && data.error) ? data.error : `Request failed (${res.status})`;
    throw new Error(msg);
  }
  return data.data;
}

function toast(msg){
  const text = String(msg || '').toLowerCase();
  let type = 'info';
  if (/(error|failed|denied|invalid|cannot|unable)/.test(text)) type = 'error';
  else if (/(warning|required|must|less than|not enough|out of stock|recheck)/.test(text)) type = 'warning';
  else if (/(success|saved|updated|finalized|encoded|approved|completed|deleted|loaded|started|archived)/.test(text)) type = 'success';

  if (window.showPetronFlash) {
    window.showPetronFlash(msg, type);
    return;
  }
  if (window.showToast) {
    window.showToast(msg, type);
    return;
  }
  const t = $('#toast');
  if(!t) return alert(msg);
  t.textContent = msg;
  t.classList.add('show');
  clearTimeout(window.__toastTimer);
  window.__toastTimer = setTimeout(()=>t.classList.remove('show'), 2600);
}

function showModal(id){
  const m = document.getElementById(id);
  if(!m) return;
  m.classList.add('show');
  m.setAttribute('aria-hidden','false');
}
function hideModal(id){
  const m = document.getElementById(id);
  if(!m) return;
  m.classList.remove('show');
  m.setAttribute('aria-hidden','true');
}

document.addEventListener('click', (e)=>{
  const btn = e.target.closest('[data-close]');
  if(btn){ hideModal(btn.getAttribute('data-close')); }
});

function drawSalesChart(canvas, values){
  if(!canvas) return;
  const ctx = canvas.getContext('2d');
  const w = canvas.width = canvas.clientWidth * devicePixelRatio;
  const h = canvas.height = (canvas.getAttribute('height')||120) * devicePixelRatio;
  ctx.clearRect(0,0,w,h);

  ctx.globalAlpha = 0.25;
  ctx.strokeStyle = '#98a2b3';
  ctx.lineWidth = 1 * devicePixelRatio;
  const pad = 18 * devicePixelRatio;
  const gx = 7;
  const gy = 4;
  for(let i=0;i<=gx;i++){
    const x = pad + (w-2*pad) * (i/gx);
    ctx.beginPath(); ctx.moveTo(x, pad); ctx.lineTo(x, h-pad); ctx.stroke();
  }
  for(let i=0;i<=gy;i++){
    const y = pad + (h-2*pad) * (i/gy);
    ctx.beginPath(); ctx.moveTo(pad, y); ctx.lineTo(w-pad, y); ctx.stroke();
  }
  ctx.globalAlpha = 1;

  
  const max = Math.max(1, ...values);
  const pts = values.map((v,i)=>{
    const x = pad + (w-2*pad) * (i/(values.length-1));
    const y = (h-pad) - (h-2*pad) * (v/max);
    return [x,y];
  });
  ctx.strokeStyle = '#12b76a';
  ctx.lineWidth = 2.5 * devicePixelRatio;
  ctx.beginPath();
  pts.forEach(([x,y],i)=> i?ctx.lineTo(x,y):ctx.moveTo(x,y));
  ctx.stroke();

  
  ctx.fillStyle = '#12b76a';
  pts.forEach(([x,y])=>{ ctx.beginPath(); ctx.arc(x,y,3.2*devicePixelRatio,0,Math.PI*2); ctx.fill(); });
}

async function initDashboard(){
  // Skip legacy JS dashboard init on PHP-rendered dashboards
  // (e.g., admin/staff dashboards that already render metrics server-side).
  const requiredLegacyNodes = [
    '#mTodaySales',
    '#mSalesDelta',
    '#mTotalFuel',
    '#mMerchCount',
    '#aTotal',
    '#aFuel',
    '#aMerch',
    '#aServices',
    '#fuelCards',
    '#salesChart'
  ];
  const hasLegacyDashboard = requiredLegacyNodes.every((sel) => !!$(sel));
  if (!hasLegacyDashboard) {
    return;
  }

  const [products, sales] = await Promise.all([
    api('../backend/inventory.php', {method:'GET'}),
    api('../backend/sales.php', {method:'GET'}).catch(()=>[])
  ]);

  const today = new Date().toISOString().slice(0,10);
  const todaySales = sales.filter(s=>s.date===today);
  const totalToday = todaySales.reduce((a,s)=>a+Number(s.total||0),0);

  
  const d = new Date(); d.setDate(d.getDate()-1);
  const yKey = d.toISOString().slice(0,10);
  const ySales = sales.filter(s=>s.date===yKey).reduce((a,s)=>a+Number(s.total||0),0);
  const delta = ySales ? ((totalToday - ySales)/ySales)*100 : (totalToday?100:0);

  $('#mTodaySales').textContent = fmtMoney(totalToday);
  $('#mSalesDelta').textContent = `↗ ${delta>=0?'+':''}${delta.toFixed(0)}% vs yesterday`;

  const totalFuel = (products.fuel||[]).reduce((a,f)=>a+Number(f.level_l||0),0);
  $('#mTotalFuel').textContent = fmtL(totalFuel);
  $('#mMerchCount').textContent = fmtInt((products.merchandise||[]).length);

  let fuelAmt=0, merchAmt=0, servAmt=0;
  todaySales.forEach(s=>{
    (s.items||[]).forEach(it=>{
      const amt = Number(it.amount||0);
      if(it.type==='fuel') fuelAmt+=amt;
      if(it.type==='merchandise') merchAmt+=amt;
      if(it.type==='services') servAmt+=amt;
    });
  });
  $('#aTotal').textContent = fmtMoney(totalToday);
  $('#aFuel').textContent = fmtMoney(fuelAmt);
  $('#aMerch').textContent = fmtMoney(merchAmt);
  $('#aServices').textContent = fmtMoney(servAmt);

  const fc = $('#fuelCards');
  fc.innerHTML = '';
  (products.fuel||[]).forEach((f, idx)=>{
    const pct = f.capacity_l ? Math.round((Number(f.level_l)/Number(f.capacity_l))*100) : 0;
    const el = document.createElement('div');
    el.className = 'card metric';
    el.style.padding = '14px';
    el.innerHTML = `
      <div class="metric-label">${f.id}</div>
      <div class="metric-value" style="font-size:16px">${f.name}</div>
      <div class="metric-sub">Current Level <b style="color:#101828">${fmtL(f.level_l)}</b></div>
      <div class="metric-sub">Capacity: ${fmtL(f.capacity_l)} <span style="float:right">${pct}%</span></div>
      <div class="metric-sub">Price/Liter <b style="color:var(--green)">${fmtMoney(f.price).replace('₱','₱')}</b></div>
    `;
    fc.appendChild(el);
  });

  const buckets = new Array(11).fill(0);
  todaySales.forEach(s=>{
    const hh = parseInt((s.time||'00:00:00').slice(0,2),10) || 0;
    const bi = Math.min(10, Math.floor(hh/2));
    buckets[bi] += Number(s.total||0);
  });
  drawSalesChart($('#salesChart'), buckets);
}

function productCard(p, type){
  const role = window.userRole || document.body.getAttribute('data-role') || 'staff';
  let style = '';
  if (role === 'admin' && (type === 'fuel' || type === 'services')) {
    style = 'opacity: 0.6; cursor: not-allowed;';
  }

  let tag = p.category || 'merch';
  let sub = '';
  let icon = '📦';

  if(type === 'fuel'){
    tag = 'fuel';
    icon = '⛽';
    sub = `Level: ${fmtL(p.level_l)}`;
  } else if(type === 'services'){
    tag = 'service';
    icon = '🛠';
    sub = p.desc || '';
  } else {
    sub = `Stock: ${fmtInt(p.stock || p.stock_level)}`;
  }

  return `
    <div class="pcard" data-id="${p.id}" data-type="${type}" style="${style}">
      <div class="picon">${icon}</div>
      <div>
        <div class="pname">${p.name}</div>
        <div class="psub">${sub}</div>
        <div class="ptag">${tag}</div>
      </div>
      <div class="pprice">${fmtMoney(p.price)}</div>
    </div>
  `;
}

async function initPOS(){
  const grid = $('#productGrid');
  const search = $('#posSearch');
  const tabs = $$('.tab[data-tab]');
  const btnPay = $('#btnPay');
  const btnClear = $('#btnClear');
  const btnArchive = $('#btnArchive'); 


  // Exit early if required elements don't exist - check for cart-based POS system
  // If grid or cartItems don't exist, this is the simple form-based POS page, not the advanced system
  if (!grid || !$('#cartItems')) {
    console.warn('Advanced POS system not found - skipping initPOS');
    return;
  }

  const userRole = window.userRole || document.body.getAttribute('data-role') || 'staff';
  let currentSaleId = null; 
  let originalCashier = null;

  if (userRole === 'staff') {
    btnPay.textContent = 'Submit Encoding';
    btnPay.classList.remove('primary');
    btnPay.classList.add('dark'); 
  } else {
    if(!document.getElementById('btnReview')){
        const btnReview = document.createElement('button');
        btnReview.id = 'btnReview';
        btnReview.className = 'btn';
        btnReview.textContent = 'Review & Save';
        btnReview.style.marginRight = '8px';
        btnReview.style.backgroundColor = '#6c757d'; 
        btnReview.style.color = 'white';
        btnPay.parentNode.insertBefore(btnReview, btnPay);
        btnReview.addEventListener('click', async ()=>{
             if(cart.length === 0) return;
             if(confirm('Save changes as "For Approval"?')){
                 await submitSale('For Approval (Admin)');
             }
        });
    }
  }

  let products = await api('../backend/products.php', {method:'GET'});
  let active = userRole === 'admin' ? 'pending' : 'fuel';
  let q = '';
  let cart = []; 

  const lookup = {};
  ['fuel','merchandise','services'].forEach(t=>{
    (products[t]||[]).forEach(p=> lookup[p.id] = {type:t, p});
  });

  function renderProducts(){
    if(active === 'pending'){

        api('../backend/sales.php?action=pending').then(pendingSales => {
            if(!pendingSales || pendingSales.length === 0){
                grid.innerHTML = `<div class="card" style="padding:18px;color:var(--muted);grid-column:1/-1">No pending transactions from staff.</div>`;
                return;
            }
            grid.innerHTML = pendingSales.map(s => `
                <div class="pcard pending-sale" data-sale-id="${s.id}" style="border-left:4px solid orange;">
                    <div class="picon">🕒</div>
                    <div>
                        <div class="pname">Transaction #${s.id.substring(0,8)}...</div>
                        <div class="psub">By: ${s.cashier || 'Staff'} | ${s.date}</div>
                        <div class="ptag">${s.items.length} Items</div>
                    </div>
                    <div class="pprice">${fmtMoney(s.total)}</div>
                </div>
            `).join('');
        });
        return;
    }

    let list = (products[active]||[]).filter(p=>{
      if(!q) return true;
      return (p.name||'').toLowerCase().includes(q) || (p.sku||'').toLowerCase().includes(q);
    });
    grid.innerHTML = list.map(p=>productCard(p, active)).join('') || `
      <div class="card" style="padding:18px;color:var(--muted);grid-column:1/-1">No items found.</div>`;
  }

  function loadPendingSale(sale){
    cart = [];
    (sale.items||[]).forEach(it=>{
      cart.push({id:it.id, qty:Number(it.qty), type:it.type});
    });
    currentSaleId = sale.id;
    originalCashier = sale.cashier;
    $('#saleIdDisplay').textContent = `Reviewing #${sale.id} (By: ${sale.cashier})`;
    if($('#cartCustomer')) $('#cartCustomer').value = sale.customer || 'Walk-in';
    renderCart();
    toast('Transaction loaded for review');
  }

  function cartTotal(){
    let total = 0;
    cart.forEach(it=>{
      const ref = lookup[it.id];
      if(!ref) return;
      total += Number(ref.p.price||0) * it.qty;
    });
    return total;
  }

  function renderCart(){
    const wrap = $('#cartItems');
    const isAdmin = userRole === 'admin';
    if(cart.length===0){
      wrap.innerHTML = `<div class="empty small"><div class="empty-ico"><i class="fas fa-receipt"></i></div><div class="empty-text">No items in cart</div></div>`;
      $('#cartTotal').textContent = fmtMoney(0);
      btnPay.disabled = true;
      btnClear.disabled = true;
      return;
    }
    wrap.innerHTML = cart.map(it=>{
      const ref = lookup[it.id];
      const name = ref?.p?.name || it.id;
      const price = Number(ref?.p?.price||0);
      const disabledAttr = isAdmin ? 'disabled' : '';
      return `
        <div class="cart-item" data-id="${it.id}">
          <div>
            <div class="cart-name">${name}</div>
            <div class="cart-sub">${fmtMoney(price)} × ${it.qty}</div>
          </div>
          <div class="qty">
            <button data-act="dec" ${disabledAttr}>−</button>
            <div class="n">${it.qty}</div>
            <button data-act="inc" ${disabledAttr}>+</button>
          </div>
          <div style="min-width:74px;text-align:right;font-weight:900">${fmtMoney(price*it.qty)}</div>
          <button class="remove" title="Remove" data-act="rm" ${disabledAttr}>×</button>
        </div>
      `;
    }).join('');
    $('#cartTotal').textContent = fmtMoney(cartTotal());
    btnPay.disabled = false;
    btnClear.disabled = isAdmin; 
    if(document.getElementById('btnReview')){
        document.getElementById('btnReview').disabled = false;
    }
    if(btnArchive) btnArchive.classList.toggle('hidden', !currentSaleId); 
  }

  function addToCart(id, type){
    const ref = lookup[id];
    if(!ref) return;
    const stock = Number(ref.p.stock || ref.p.stock_level || 0);
    if(ref.type === 'merchandise' && stock <= 0){
      toast('Out of stock');
      return;
    }
    const found = cart.find(x=>x.id===id);
    if(found) found.qty += 1;
    else cart.push({id, qty:1, type});
    renderCart();
  }

  if (tabs && tabs.length > 0) {
    tabs.forEach(t=>{
      t.addEventListener('click', ()=>{
        const tabType = t.dataset.tab;
        tabs.forEach(x=>x.classList.remove('active'));
        t.classList.add('active');
        active = tabType;

        if(active === 'pending') { }

        renderProducts();
      });
    });
  }

  if (search) {
    search.addEventListener('input', ()=>{
      q = search.value.trim().toLowerCase();
      renderProducts();
    });
  }

  if (grid) {
    grid.addEventListener('click', (e)=>{
      const c = e.target.closest('.pcard');
      if(!c) return;

      if(active === 'pending' && c.dataset.saleId){
          const saleId = c.dataset.saleId;
          api('../backend/sales.php?action=pending').then(list => {
              const sale = list.find(s => s.id === saleId);
              if(sale){
                  loadPendingSale(sale);
              }
          });
          return;
      }

      const type = c.dataset.type;
      if (userRole === 'admin' && (type === 'fuel' || type === 'services')) {
        toast('Admins cannot add Fuel/Services directly.');
        return; // Silently ignore click for admin on fuel/services
      }

      addToCart(c.dataset.id, type);
    });
  }

  const cartItems = $('#cartItems');
  if (cartItems) {
    cartItems.addEventListener('click', (e)=>{
      const row = e.target.closest('.cart-item');
      if(!row) return;
      const id = row.dataset.id;
      const act = e.target.getAttribute('data-act');
      const it = cart.find(x=>x.id===id);
      if(!it) return;
      if(act==='inc'){
        // enforce merch stock
        const ref = lookup[id];
        const stock = Number(ref.p.stock || ref.p.stock_level || 0);
        if(ref.type==='merchandise' && it.qty+1 > stock){
          toast('Not enough stock'); return;
        }
        it.qty++;
      }
      if(act==='dec'){ it.qty = Math.max(1, it.qty-1); }
      if(act==='rm'){ cart = cart.filter(x=>x.id!==id); }
      renderCart();
    });
  }

  if (btnClear) {
    btnClear.addEventListener('click', ()=>{
      cart = [];
      renderCart();
    });
  }

  // Payment modal
  const payMethods = $('#payMethods');
  let payMethod = 'Cash';

  function updatePayUI(){
    $('#payTotal').textContent = fmtMoney(cartTotal());
    $('#qrAmt').textContent = fmtMoney(cartTotal());
    $('#qrSub').textContent = `${payMethod} Payment`;
    const isCash = payMethod === 'Cash';
    $('#payCashBox').classList.toggle('hidden', !isCash);
    $('#payQrBox').classList.toggle('hidden', isCash);
    $('#payChange').textContent = fmtMoney(0);
    $('#amountReceived').value = '';
  }

  if (btnPay) {
    btnPay.addEventListener('click', ()=>{
      if(userRole === 'staff'){
          // Staff Workflow: Submit for Approval
          if(confirm('Submit this transaction for encoding?')){
              submitSale('Pending (Staff)');
          }
          return;
      }
      
      // Admin Workflow: Process Payment/Finalize
      updatePayUI();
      showModal('payModal');
    });
  }

  if (payMethods) {
    payMethods.addEventListener('click', (e)=>{
      const b = e.target.closest('.seg');
      if(!b) return;
      $$('.seg', payMethods).forEach(x=>x.classList.remove('active'));
      b.classList.add('active');
      payMethod = b.dataset.method;
      updatePayUI();
    });
  }

  const amountReceived = $('#amountReceived');
  if (amountReceived) {
    amountReceived.addEventListener('input', ()=>{
      const recv = Number(amountReceived.value||0);
      const ch = Math.max(0, recv - cartTotal());
      $('#payChange').textContent = fmtMoney(ch);
    });
  }

  // Admin Archive/Void
  if(btnArchive){
      btnArchive.addEventListener('click', async ()=>{
          if(!currentSaleId) return;
          if(confirm('Are you sure you want to ARCHIVE/VOID this transaction? This cannot be undone.')){
              await submitSale('Archived');
          }
      });
  }

  const btnComplete = $('#btnComplete');
  if (btnComplete) {
    btnComplete.addEventListener('click', async ()=>{
        await submitSale('Completed (Admin)');
    });
  }

  async function submitSale(status){
    try{
      const total = cartTotal();
      const customer = $('#cartCustomer').value || 'Walk-in';
      const recv = Number($('#amountReceived').value||0);
      
      // Only validate payment if completing
      if(status.includes('Completed') && payMethod==='Cash' && recv < total){
        toast('Amount received is less than total'); return;
      }

      const sale = await api('../backend/sales.php', {
        method:'POST',
        body: JSON.stringify({
          id: currentSaleId, // Send ID if updating/approving
          customer,
          payment_method: payMethod,
          amount_received: recv,
          status: status,
          cashier: originalCashier, // Preserve original cashier name
          cart: cart.map(x=>({id:x.id, qty:x.qty}))
        })
      });

      // refresh products to reflect stock changes (only if completed)
      products = await api('../backend/products.php', {method:'GET'});
      ['fuel','merchandise','services'].forEach(t=>{
        (products[t]||[]).forEach(p=> lookup[p.id] = {type:t, p});
      });

      hideModal('payModal');
      cart = [];
      currentSaleId = null;
      originalCashier = null;
      $('#saleIdDisplay').textContent = '';
      renderProducts();
      renderCart();

      if(status.includes('Completed')){
          // Build receipt HTML
          renderReceipt(sale);
          showModal('receiptModal');
          toast('Transaction Finalized');
      } else if (status.includes('Pending')) {
          toast('Encoded Successfully');
      } else if (status.includes('For Approval')) {
          toast('Saved for Approval');
      } else {
          toast('Transaction Archived');
      }

    }catch(err){
      toast(err.message);
    }
  }

  const btnPrint = $('#btnPrint');
  if (btnPrint) {
    btnPrint.addEventListener('click', ()=>{
      // Open a print-only window using receipt.php
      const rid = $('#receiptPaper').getAttribute('data-id');
      if(rid){
        window.open(`receipt.php?id=${encodeURIComponent(rid)}`, '_blank');
      }else{
        window.print();
      }
    });
  }

  function renderReceipt(sale){
    const paper = $('#receiptPaper');
    paper.setAttribute('data-id', sale.id);
    const rows = (sale.items||[]).map(it=>`
      <tr>
        <td>${escapeHtml(it.name)}</td>
        <td class="right">${Number(it.qty).toFixed(2)}</td>
        <td class="right">${fmtMoney(it.price)}</td>
        <td class="right">${fmtMoney(it.amount)}</td>
      </tr>`).join('');
    paper.innerHTML = `
      <div class="r-head">
        <div class="r-logo">PETRON</div>
        <div class="r-sub">PETRON CORPORATION</div>
        <div class="r-meta">Dealer: PETRON CORPORATION</div>
        <div class="r-meta">VAT REG TIN: 000-168-801-00289</div>
        <div class="r-hr"></div>
        <div class="r-title">SALES INVOICE</div>
      </div>

      <div class="r-row"><div>Date: ${sale.date}</div><div class="right">Time: ${sale.time}</div></div>
      <div class="r-row"><div>POS S/N: ${sale.id}</div></div>
      <div class="r-row"><div>Name: ${escapeHtml(sale.customer||'Walk-in')}</div></div>
      <div class="r-hr"></div>

      <table class="r-table">
        <thead><tr><th>Description</th><th class="right">Qty.</th><th class="right">Price</th><th class="right">Amount</th></tr></thead>
        <tbody>${rows}</tbody>
      </table>

      <div class="r-hr"></div>
      <div class="r-row"><div>Total (incl. VAT)</div><div class="right">${fmtMoney(sale.total)}</div></div>
      <div class="r-row"><div>Payment:</div><div class="right">${escapeHtml(String(sale.payment_method||'').toUpperCase())}</div></div>
      ${String(sale.payment_method||'').toLowerCase()==='cash' ? `
        <div class="r-row"><div>Cash</div><div class="right">${fmtMoney(sale.amount_received)}</div></div>
        <div class="r-row"><div>Change</div><div class="right">${fmtMoney(sale.change)}</div></div>
      ` : `
        <div class="r-row"><div>Amount</div><div class="right">${fmtMoney(sale.total)}</div></div>
      `}
      <div class="r-hr"></div>
      <div class="r-foot">
        <div>Cashier: ${escapeHtml(sale.cashier||'Staff')}</div>
        ${sale.approved_by ? `<div>Approved By: ${escapeHtml(sale.approved_by)}</div>` : ''}
        <div class="r-mini">Thank you! This is a demo receipt.</div>
      </div>
    `;
  }

  function escapeHtml(s){
    return String(s).replace(/[&<>"']/g, (c)=>({
      '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
    }[c]));
  }

  renderProducts();
  renderCart();
}

async function initInventory(){
  // Check if required elements exist (page might not have inventory UI)
  if (!$('#fuelInv') && !$('#merchInv')) return;

  let products = await api('../backend/inventory.php', {method:'GET'});
  const invTabs = $$('[data-invtab]');
  const fuelInv = $('#fuelInv');
  const merchInv = $('#merchInv');

  function statusBadgeFuel(f){
    const pct = f.capacity_l ? (Number(f.level_l)/Number(f.capacity_l))*100 : 0;
    const low = pct < 20;
    return `<span class="badge ${low?'low':'normal'}">${low?'Low':'Normal'}</span>`;
  }
  function statusBadgeMerch(m){
    const low = Number(m.stock||0) <= 5;
    return `<span class="badge ${low?'low':'normal'}">${low?'Low':'Normal'}</span>`;
  }

  function renderFuel(){
    const tb = $('#fuelTable tbody');
    tb.innerHTML = (products.fuel||[]).map(f=>{
      return `
        <tr>
          <td><b>${f.name}</b><div style="color:var(--muted);font-size:12px">${f.id}</div></td>
          <td><div style="font-weight:900">${fmtL(f.level_l)}</div></td>
          <td>${fmtL(f.capacity_l)}</td>
          <td>${statusBadgeFuel(f)}</td>
          <td>${fmtMoney(f.price)}</td>
          <td class="right">
            <div class="actions">
              <button class="icon" data-fuel="${f.id}" title="Stock In">↗</button>
            </div>
          </td>
        </tr>
      `;
    }).join('');

    // fuel select
    const sel = $('#fuelSelect');
    sel.innerHTML = (products.fuel||[]).map(f=>`<option value="${f.id}">${f.name} (${f.id})</option>`).join('');
  }

  function renderMerch(){
    const tb = $('#merchTable tbody');
    const q = ($('#merchSearch').value||'').trim().toLowerCase();
    const list = (products.merchandise||[]).filter(m=>{
      if(!q) return true;
      return (m.name||'').toLowerCase().includes(q) || (m.sku||'').toLowerCase().includes(q);
    });
    tb.innerHTML = list.map(m=>{
      return `
        <tr>
          <td><b>${m.name}</b></td>
          <td style="color:var(--muted)">${m.sku||m.id}</td>
          <td><span class="badge">${m.category||'-'}</span></td>
          <td>${statusBadgeMerch(m)} <span style="margin-left:8px;font-weight:900">${m.stock||0}</span></td>
          <td>${fmtMoney(m.cost||0)}</td>
          <td style="color:var(--green);font-weight:900">${fmtMoney(m.price||0)}</td>
          <td class="right">
            <div class="actions">
              <button class="icon" data-edit="${m.id}" title="Edit"><i class="fas fa-edit"></i></button>
              <button class="icon danger" data-del="${m.id}" title="Delete">🗑</button>
            </div>
          </td>
        </tr>
      `;
    }).join('');
  }

  invTabs.forEach(t=>{
    t.addEventListener('click', ()=>{
      invTabs.forEach(x=>x.classList.remove('active'));
      t.classList.add('active');
      const k = t.dataset.invtab;
      fuelInv.classList.toggle('hidden', k!=='fuel');
      merchInv.classList.toggle('hidden', k!=='merch');
    });
  });

  // Fuel stock in
  if ($('#btnFuelStockIn')) {
    $('#btnFuelStockIn').addEventListener('click', ()=>{
      $('#fuelLiters').value = '';
      showModal('fuelModal');
    });
  }

  if ($('#fuelTable')) {
    $('#fuelTable').addEventListener('click', (e)=>{
      const b = e.target.closest('[data-fuel]');
      if(!b) return;
      $('#fuelSelect').value = b.dataset.fuel;
      $('#fuelLiters').value = '';
      showModal('fuelModal');
    });
  }

  if ($('#fuelSave')) {
    $('#fuelSave').addEventListener('click', async ()=>{
      try{
        const id = $('#fuelSelect').value;
        const liters = Number($('#fuelLiters').value||0);
        if(liters<=0){ toast('Liters must be > 0'); return; }
        await api('../backend/inventory.php', {
          method:'POST',
          body: JSON.stringify({action:'fuel_stock_in', id, liters})
        });
        products = await api('../backend/inventory.php', {method:'GET'});
        renderFuel();
        hideModal('fuelModal');
        toast('Fuel stock updated');
      }catch(err){ toast(err.message); }
    });
  }

  // Merch add/edit/delete
  let editing = null;
  if ($('#btnAddMerch')) {
    $('#btnAddMerch').addEventListener('click', ()=>{
      editing = null;
      $('#merchModalTitle').textContent = 'Add Item';
      $('#mId').value = '';
      $('#mName').value = '';
      $('#mSku').value = '';
      $('#mCategory').value = '';
      $('#mStock').value = 0;
      $('#mCost').value = 0;
      $('#mPrice').value = 0;
      showModal('merchModal');
    });
  }

  if ($('#merchSearch')) {
    $('#merchSearch').addEventListener('input', renderMerch);
  }

  if ($('#merchTable')) {
    $('#merchTable').addEventListener('click', async (e)=>{
      const edit = e.target.closest('[data-edit]');
      const del = e.target.closest('[data-del]');
      if(edit){
        const id = edit.dataset.edit;
        const item = (products.merchandise||[]).find(x=>x.id===id);
        if(!item) return;
        editing = id;
        $('#merchModalTitle').textContent = 'Edit Item';
        $('#mId').value = item.id;
        $('#mName').value = item.name||'';
        $('#mSku').value = item.sku||item.id;
        $('#mCategory').value = item.category||'';
        $('#mStock').value = item.stock||0;
        $('#mCost').value = item.cost||0;
        $('#mPrice').value = item.price||0;
        showModal('merchModal');
      }
      if(del){
        const id = del.dataset.del;
        if(!confirm('Delete this item?')) return;
        try{
          await api('../backend/inventory.php', {method:'POST', body: JSON.stringify({action:'merch_delete', id})});
          products = await api('../backend/inventory.php', {method:'GET'});
          renderMerch();
          toast('Item deleted');
        }catch(err){ toast(err.message); }
      }
    });
  }

  if ($('#merchSave')) {
    $('#merchSave').addEventListener('click', async ()=>{
      try{
        const item = {
          id: ($('#mId').value||'').trim() || ($('#mSku').value||'').trim(),
          name: ($('#mName').value||'').trim(),
          sku: ($('#mSku').value||'').trim(),
          category: ($('#mCategory').value||'').trim(),
          stock: Number($('#mStock').value||0),
          cost: Number($('#mCost').value||0),
          price: Number($('#mPrice').value||0),
        };
        if(!item.name || !item.sku){ toast('Name and SKU are required'); return; }
        const action = editing ? 'merch_update' : 'merch_add';
        await api('../backend/inventory.php', {method:'POST', body: JSON.stringify({action, item})});
        products = await api('../backend/inventory.php', {method:'GET'});
        renderMerch();
        hideModal('merchModal');
        toast('Saved');
      }catch(err){ toast(err.message); }
    });
  }

  renderFuel();
  renderMerch();
}


// -------- Job Order (Labor) --------
let laborCache = {staff:[], sessions:[], logs:[]};

function roleBadge(role){
  return `<span class="pill">${String(role||'').toLowerCase()}</span>`;
}

function statusBadge(status){
  const s = String(status||'active').toLowerCase();
  const cls = s==='active'?'badge green':(s==='suspended'?'badge amber':(s==='inactive'?'badge danger':'badge'));
  return `<span class="${cls}">${s}</span>`;
}

function findStaff(id){
  return laborCache.staff.find(s=>s.id===id);
}

function formatElapsed(startIso){
  const ms = Date.now() - new Date(startIso).getTime();
  const totalSec = Math.max(0, Math.floor(ms/1000));
  const h = Math.floor(totalSec/3600);
  const m = Math.floor((totalSec%3600)/60);
  const s = totalSec%60;
  if(h>0) return `${h}h ${m}m ${s}s`;
  if(m>0) return `${m}m ${s}s`;
  return `${s}s`;
}

async function loadLaborSummary(){
  try {
    // Skip if not on labor management page
    if (!document.getElementById('sessionsTableWrap')) {
      return;
    }
    
    const res = await api('../backend/labor.php?view=summary');
    if (!res) return; // Handle null response
    
    laborCache.staff = res.staff||[];
    laborCache.sessions = res.sessions||[];
    laborCache.logs = res.logs||[];
    // metrics
    $('#mActiveNow').textContent = String(res.active_now ?? laborCache.sessions.length);
    $('#mHoursToday').textContent = String(res.hours_today ?? 0);
    $('#mLogsToday').textContent = String(res.logs_today ?? 0);
    $('#mChargesToday').textContent = fmtMoney(res.charges_today ?? 0);
  } catch (err) {
    // Silently fail on non-labor pages
    if (!document.getElementById('sessionsTableWrap')) {
      return;
    }
    console.error('Labor summary error:', err);
  }
}

function renderSessions(){
  const wrap = $('#sessionsTableWrap');
  const empty = $('#sessionsEmpty');
  const tb = $('#sessionsTbody');
  tb.innerHTML = '';
  if(!laborCache.sessions.length){
    wrap.classList.add('hidden'); empty.classList.remove('hidden');
    return;
  }
  empty.classList.add('hidden'); wrap.classList.remove('hidden');
  laborCache.sessions.forEach(ss=>{
    const st = findStaff(ss.staff_id) || {};
    const tr = document.createElement('tr');
    tr.innerHTML = `
      <td><strong>${st.name||'Staff'}</strong></td>
      <td>${roleBadge(st.role||'')}</td>
      <td>${new Date(ss.start).toLocaleTimeString([], {hour:'2-digit', minute:'2-digit'})}</td>
      <td class="elapsed" data-start="${ss.start}">${formatElapsed(ss.start)}</td>
      <td>
        <button class="btn small" data-out="${ss.id}">Clock Out</button>
      </td>
    `;
    tb.appendChild(tr);
  });

  // Update elapsed in real-time (no full re-render needed)
  if(!window.__laborTimer){
    window.__laborTimer = setInterval(()=>{
      $$('.elapsed', wrap).forEach(cell=>{
        const start = cell.getAttribute('data-start');
        if(start) cell.textContent = formatElapsed(start);
      });
    }, 1000);
  }
}

function renderLogs(){
  const q = ($('#logSearch')?.value||'').toLowerCase();
  const tb = $('#logsTbody'); tb.innerHTML='';
  const rows = laborCache.logs.slice().reverse().filter(l=>{
    const st = (l.staff_name||'') + ' ' + (l.date||'') + ' ' + (l.status||'');
    return st.toLowerCase().includes(q);
  });
  rows.forEach(l=>{
    const tr=document.createElement('tr');
    tr.innerHTML = `
      <td><strong>${l.staff_name||''}</strong></td>
      <td>${l.date||''}</td>
      <td>${l.time_in||''}</td>
      <td>${l.time_out||''}</td>
      <td>${Number(l.hours||0).toFixed(1)}</td>
      <td class="green">${fmtMoney(l.charge||0)}</td>
      <td><span class="badge">${l.status||''}</span></td>
    `;
    tb.appendChild(tr);
  });
}

function renderStaff(){
  const q = ($('#staffSearch')?.value||'').toLowerCase();
  const tb = $('#staffTbody'); tb.innerHTML='';
  laborCache.staff.filter(s=>{
    const st = `${s.emp_id||''} ${s.name||''} ${s.role||''} ${s.phone||''}`;
    return st.toLowerCase().includes(q);
  }).forEach(s=>{
    const tr=document.createElement('tr');
    tr.innerHTML = `
      <td>${s.emp_id||''}</td>
      <td><strong>${s.name||''}</strong></td>
      <td>${roleBadge(s.role||'')}</td>
      <td>${fmtMoney(s.rate||0)}/hr</td>
      <td class="muted">${s.phone||''}</td>
      <td>${statusBadge(s.status||'active')}</td>
      <td>
        <button class="icon-btn" data-edit-staff="${s.id}" title="Edit"><i class="fas fa-edit"></i></button>
        <button class="icon-btn danger" data-del-staff="${s.id}" title="Delete"><i class="fas fa-trash"></i></button>
      </td>
    `;
    tb.appendChild(tr);
  });
}

function fillClockInOptions(){
  const sel = $('#clockStaffSelect');
  sel.innerHTML = `<option value="">Choose staff member</option>`;
  laborCache.staff.forEach(s=>{
    sel.insertAdjacentHTML('beforeend', `<option value="${s.id}">${s.name} (${s.role})</option>`);
  });
}

function resetStaffModal(){
  $('#staffId').value='';
  $('#staffEmpId').value='';
  $('#staffName').value='';
  $('#staffRole').value='Mechanic';
  $('#staffRate').value='';
  $('#staffPhone').value='';
  $('#staffEmail').value='';
  $('#staffModalTitle').textContent='Add New Staff';
  $('#btnSaveStaff').textContent='Add Staff';
}

async function initJobOrder(){
  // Skip if on new job order page (not old labor management page)
  if (!document.getElementById('sessionsTableWrap')) {
    console.log('Skipping labor init - not on labor management page');
    return;
  }
  
  // tabs
  $$('.tab').forEach(t=>t.addEventListener('click', ()=>{
    $$('.tab').forEach(x=>x.classList.remove('active'));
    t.classList.add('active');
    const name=t.dataset.tab;
    ['sessions','logs','staff'].forEach(k=>{
      $('#tab-'+k).classList.toggle('hidden', k!==name);
    });
  }));

  $('#btnAddStaff')?.addEventListener('click', ()=>{ resetStaffModal(); showModal('modalStaff'); });
  $('#btnClockIn')?.addEventListener('click', ()=>{ fillClockInOptions(); showModal('modalClockIn'); });
  $('#btnStartSession')?.addEventListener('click', ()=>{ fillClockInOptions(); showModal('modalClockIn'); });

  $('#btnSaveStaff')?.addEventListener('click', async ()=>{
    try{
      const payload = {
        action:'staff_save',
        id: $('#staffId').value || undefined,
        emp_id: $('#staffEmpId').value.trim(),
        name: $('#staffName').value.trim(),
        role: $('#staffRole').value.trim(),
        rate: Number($('#staffRate').value||0),
        phone: $('#staffPhone').value.trim(),
        email: $('#staffEmail').value.trim(),
        status: 'active'
      };
      await api('../backend/labor.php', {method:'POST', body: JSON.stringify(payload)});
      hideModal('modalStaff');
      await loadLaborSummary();
      renderStaff(); fillClockInOptions();
      toast('Saved staff');
    }catch(err){ toast(err.message); }
  });

  $('#btnStartClock')?.addEventListener('click', async ()=>{
    try{
      const staff_id = $('#clockStaffSelect').value;
      await api('../backend/labor.php', {method:'POST', body: JSON.stringify({action:'clock_in', staff_id})});
      hideModal('modalClockIn');
      await loadLaborSummary();
      renderSessions();
      toast('Session started');
    }catch(err){ toast(err.message); }
  });

  // Only attach document click listener if we're on the joborder page
  const jobOrderPage = document.getElementById('sessionsTableWrap');
  if (jobOrderPage) {
    document.addEventListener('click', async (e)=>{
      const outBtn = e.target.closest('[data-out]');
      const editBtn = e.target.closest('[data-edit-staff]');
      const delBtn = e.target.closest('[data-del-staff]');
      // Only handle these specific buttons, don't interfere with other clicks
      if(!outBtn && !editBtn && !delBtn) return;

      if(outBtn){
        try{
          const id = outBtn.getAttribute('data-out');
          await api('../backend/labor.php', {method:'POST', body: JSON.stringify({action:'clock_out', session_id:id})});
          await loadLaborSummary();
          renderSessions(); renderLogs();
          toast('Session completed');
        }catch(err){ toast(err.message); }
      }
      if(editBtn){
        const id = editBtn.getAttribute('data-edit-staff');
        const s = laborCache.staff.find(x=>x.id===id);
        if(!s) return;
        $('#staffId').value = s.id;
        $('#staffEmpId').value = s.emp_id||'';
        $('#staffName').value = s.name||'';
        $('#staffRole').value = (s.role||'Mechanic').charAt(0).toUpperCase()+String(s.role||'').slice(1);
        $('#staffRate').value = s.rate||0;
        $('#staffPhone').value = s.phone||'';
        $('#staffEmail').value = s.email||'';
        $('#staffModalTitle').textContent='Edit Staff';
        $('#btnSaveStaff').textContent='Save';
        showModal('modalStaff');
      }
      if(delBtn){
        const id = delBtn.getAttribute('data-del-staff');
        if(!confirm('Delete this staff member?')) return;
        try{
          await api('../backend/labor.php', {method:'POST', body: JSON.stringify({action:'staff_delete', id})});
          await loadLaborSummary();
          renderStaff(); renderSessions();
          toast('Deleted');
        }catch(err){ toast(err.message); }
      }
    });
  }

  $('#logSearch')?.addEventListener('input', renderLogs);
  $('#staffSearch')?.addEventListener('input', renderStaff);

  // Only load labor summary if labor elements exist
  if (document.getElementById('sessionsTableWrap')) {
    await loadLaborSummary();
    renderSessions();
    renderLogs();
    renderStaff();
    fillClockInOptions();
  }
}

// -------- Customers --------
let customerCache = [];

async function loadCustomers(){
  const res = await api('../backend/customers.php');
  customerCache = res.data?.customers || res.customers || [];
  // metrics
  $('#cTotal').textContent = String(customerCache.length);
  const credits = customerCache.filter(c=>String(c.type||'').toLowerCase()==='credit');
  $('#cCredit').textContent = String(credits.length);
  const outstanding = customerCache.reduce((a,c)=>a+Number(c.current_balance||c.balance||0),0);
  $('#cOutstanding').textContent = fmtMoney(outstanding);
}

function renderCustomers(){
  const q = ($('#custSearch')?.value||'').toLowerCase();
  const tb = $('#custTbody'); tb.innerHTML='';
  const merchLabels = {
    'oil_lube_grease': 'A. Oil/Lube/Grease',
    'car_accessories': 'B. Car Accessories',
    'oil_fuel_filter': 'C. Oil/Fuel Filter',
    'others': 'D. Others',
    'multiple': 'Multiple Types'
  };
  customerCache.filter(c=>{
    const st = `${c.name||c.company||''} ${c.contact_person||c.contact||''} ${c.phone||''} ${c.email||''} ${c.type||''}`;
    return st.toLowerCase().includes(q);
  }).forEach(c=>{
    const customerName = c.name || c.company || '';
    const contactPerson = c.contact_person || c.contact || '';
    const balance = c.current_balance || c.balance || 0;
    const merchLabel = merchLabels[c.merchandise_type] || '';
    const creditLimit = (String(c.type||'')==='credit') ? '₱' + fmtMoney(c.credit_limit||0) : '₱0.00';
    const balanceColor = balance > 0 ? 'red' : 'green';
    const tr=document.createElement('tr');
    tr.innerHTML = `
      <td><b>${customerName}</b></td>
      <td>${contactPerson}<br>${c.phone||''}<br>${c.email||''}</td>
      <td><span style="text-transform:capitalize;">${c.type||'cash'}</span></td>
      <td>${merchLabel ? merchLabel : '<span class="muted">—</span>'}</td>
      <td>${creditLimit}</td>
      <td style="color:${balanceColor}">₱${fmtMoney(balance)}</td>
      <td>${c.status||'active'}</td>
      <td>
        <button class="btn ghost small" data-edit-cust="${c.id}" title="Edit">Edit</button>
        <button class="btn ghost small red" data-del-cust="${c.id}" title="Delete">Delete</button>
      </td>
    `;
    tb.appendChild(tr);
  });
}

function resetCustomerModal(){
  $('#custId').value='';
  $('#custCompany').value='';
  $('#custContact').value='';
  $('#custPhone').value='';
  $('#custEmail').value='';
  $('#custAddress').value='';
  $('#custType').value='cash';
  $('#custLimit').value=0;
  $('#custStatus').value='active';
  $('#custModalTitle').textContent='Add New Customer';
  $('#btnSaveCustomer').textContent='Add Customer';
}

function toggleLimitField(){
  const isCredit = ($('#custType').value === 'credit');
  $('#custLimit').disabled = !isCredit;
  if(!isCredit) $('#custLimit').value = 0;
}

async function initCustomers(){
  $('#btnAddCustomer')?.addEventListener('click', ()=>{
    resetCustomerModal();
    toggleLimitField();
    showModal('modalCustomer');
  });

  $('#custType')?.addEventListener('change', toggleLimitField);

  $('#btnSaveCustomer')?.addEventListener('click', async ()=>{
    try{
      const payload = {
        id: $('#custId').value || undefined,
        name: $('#custCompany').value.trim(),
        contact_person: $('#custContact').value.trim(),
        phone: $('#custPhone').value.trim(),
        email: $('#custEmail').value.trim(),
        address: $('#custAddress').value.trim(),
        type: $('#custType').value,
        credit_limit: Number($('#custLimit').value||0),
        status: $('#custStatus').value,
      };
      await api('../backend/customers.php', {method:'POST', body: JSON.stringify(payload)});
      hideModal('modalCustomer');
      await loadCustomers();
      renderCustomers();
      toast('Saved customer');
    }catch(err){ toast(err.message); }
  });

  $('#custSearch')?.addEventListener('input', renderCustomers);

  document.addEventListener('click', async (e)=>{
    const edit = e.target.closest('[data-edit-cust]');
    const del = e.target.closest('[data-del-cust]');
    if(edit){
      const id = edit.getAttribute('data-edit-cust');
      const c = customerCache.find(x=>String(x.id)===String(id));
      if(!c) return;
      $('#custId').value=c.id;
      $('#custCompany').value=c.name||c.company||'';
      $('#custContact').value=c.contact_person||c.contact||'';
      $('#custPhone').value=c.phone||'';
      $('#custEmail').value=c.email||'';
      $('#custAddress').value=c.address||'';
      $('#custType').value=c.type||'cash';
      $('#custLimit').value=c.credit_limit||0;
      $('#custStatus').value=c.status||'active';
      $('#custModalTitle').textContent='Edit Customer';
      $('#btnSaveCustomer').textContent='Save';
      toggleLimitField();
      showModal('modalCustomer');
    }
    if(del){
      const id = del.getAttribute('data-del-cust');
      if(!confirm('Delete this customer?')) return;
      try{
        await api('../backend/customers.php?id='+encodeURIComponent(id), {method:'DELETE'});
        await loadCustomers();
        renderCustomers();
        toast('Deleted');
      }catch(err){ toast(err.message); }
    }
  });

  await loadCustomers();
  renderCustomers();
}
(async function boot(){
  window.userRole = document.body.getAttribute('data-role');
  const page = document.body.getAttribute('data-page');
  try{
    if(page === 'dashboard') await initDashboard();
    if(page === 'pos') await initPOS();
    // Only call initInventory if page is NOT PHP-rendered
    if(page === 'inventory' && !document.querySelector('.page-head[data-rendering="php"]')) {
      await initInventory();
    }
    if(page === 'joborder') await initJobOrder().catch(()=>{});
    // Skip initCustomers for PHP-rendered customers page - the table is already rendered
    // if(page === 'customers') await initCustomers();
  }catch(err){
    // Don't show errors on joborder page
    const page = document.body.getAttribute('data-page');
    if(page !== 'joborder') {
      console.error(err);
      toast(err.message || 'Something went wrong');
    }
  }

  // Additional CSS for disabling product grid for admin
  const style = document.createElement('style');

})();
