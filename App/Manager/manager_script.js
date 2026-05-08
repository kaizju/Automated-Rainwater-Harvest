/* ============================================================
   ECORAIN — MANAGER MASTER SCRIPT
   Consolidates JS from:
     - manager.php        (Dashboard)
     - manager_oversight.php (Oversight)
     - map.php            (Tank Map)
     - settings.php       (Settings)
     - usage.php          (Usage Stats)
     - weather.php        (Weather Monitor)

   Chart.js is loaded via CDN in the <head>.
   Leaflet is loaded via CDN only on the Map page.
   Bootstrap/jQuery are loaded via CDN only on the User page.
   ============================================================ */

/* ============================================================
   SECTION: SIDEBAR TOGGLE (shared across all pages)
   ============================================================ */

function toggleSidebar() {
  document.getElementById('sidebar').classList.toggle('open');
  document.getElementById('overlay').classList.toggle('show');
}
function closeSidebar() {
  document.getElementById('sidebar').classList.remove('open');
  document.getElementById('overlay').classList.remove('show');
}


/* ============================================================
   SECTION: OVERSIGHT PAGE — TAB SWITCHER
   ============================================================ */

function switchTab(id, el) {
  /* Hide all tab panes */
  document.querySelectorAll('[id^="tab-"]').forEach(t => t.style.display = 'none');
  /* Show selected pane */
  document.getElementById('tab-' + id).style.display = '';
  /* Toggle active class on tab buttons */
  document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
  el.classList.add('active');
}


/* ============================================================
   SECTION: SETTINGS PAGE — SLIDER
   ============================================================ */

function updateSlider(el, valId) {
  const max = parseInt(el.max) || 5000;
  const val = parseInt(el.value);
  el.style.setProperty('--val', Math.round(val / max * 100) + '%');
  document.getElementById(valId).textContent = val.toLocaleString() + 'L';
}


/* ============================================================
   SECTION: SETTINGS PAGE — ADD TANK FORM TOGGLE
   ============================================================ */

function showAddTankForm() {
  const wrap = document.getElementById('addTankForm');
  if (!wrap) return;
  wrap.style.display = 'block';
  void wrap.offsetWidth; /* force reflow for animation */
  wrap.style.animation = 'none';
  requestAnimationFrame(() => { wrap.style.animation = ''; });
}

function hideAddTankForm() {
  const wrap = document.getElementById('addTankForm');
  if (!wrap) return;
  wrap.classList.add('fgen-collapsing');
  wrap.addEventListener('animationend', () => {
    wrap.style.display = 'none';
    wrap.classList.remove('fgen-collapsing');
  }, { once: true });
}


/* ============================================================
   SECTION: SETTINGS PAGE — DELETE TANK MODAL
   ============================================================ */

function confirmDelete(tankId, tankName) {
  const modal = document.getElementById('deleteModal');
  if (!modal) return;
  document.getElementById('deleteTankId').value        = tankId;
  document.getElementById('modalTankName').textContent = '"' + tankName + '"';
  modal.classList.add('show');
}

function closeDeleteModal() {
  const modal = document.getElementById('deleteModal');
  if (modal) modal.classList.remove('show');
}

function submitDelete() {
  const form = document.getElementById('deleteTankForm');
  if (form) form.submit();
}


/* ============================================================
   SECTION: SETTINGS PAGE — TOAST & AUTO-HIDE FLASH
   ============================================================ */

function initSettingsToast(hasSuccess) {
  if (hasSuccess) {
    const t = document.getElementById('toast');
    if (t) {
      t.classList.add('show');
      setTimeout(() => t.classList.remove('show'), 3200);
    }
  }

  /* Auto-fade flash alerts after 4 s */
  setTimeout(() => {
    document.querySelectorAll('.flash').forEach(a => {
      a.style.transition = 'opacity .5s';
      a.style.opacity = '0';
      setTimeout(() => a.remove(), 500);
    });
  }, 4000);
}


/* ============================================================
   SECTION: SETTINGS PAGE — MISC EVENT WIRES
   ============================================================ */

function initSettings() {
  /* Sync slider max when capacity input changes */
  const cap = document.getElementById('tankCapacity');
  if (cap) {
    cap.addEventListener('input', function () {
      const s = document.getElementById('threshold');
      if (!s) return;
      const newMax = parseInt(this.value) || 5000;
      if (parseInt(s.value) > newMax) s.value = newMax;
      s.max = newMax;
      updateSlider(s, 'thresholdVal');
    });
  }

  /* Delete modal backdrop click closes it */
  const delModal = document.getElementById('deleteModal');
  if (delModal) {
    delModal.addEventListener('click', function(e) {
      if (e.target === this) closeDeleteModal();
    });
  }

  /* Intercept add-tank form submit — animate out first */
  const addForm = document.querySelector('#addTankForm .fgen-form');
  if (addForm) {
    addForm.addEventListener('submit', function(e) {
      e.preventDefault();
      const wrap = document.getElementById('addTankForm');
      wrap.classList.add('fgen-collapsing');
      wrap.addEventListener('animationend', () => { this.submit(); }, { once: true });
    });
  }
}


/* ============================================================
   SECTION: DASHBOARD — WEATHER / FORECAST WIDGET
   ============================================================ */

const WX = { key: 'a5712e740541248ce7883f0af8581be4', lat: 8.360015, lon: 124.868419 };

function wxIcon(desc, rain) {
  if (rain > 5)                             return '🌧️';
  if (rain > 0)                             return '🌦️';
  if (desc.includes('cloud'))               return '☁️';
  if (desc.includes('clear') || desc.includes('sun')) return '☀️';
  return '🌤️';
}

function rainChance(item) {
  const hr = item.rain && item.rain['3h'] > 0;
  const h = item.main.humidity, c = item.clouds.all;
  if (hr) return Math.min(Math.round(h * 0.7 + c * 0.3), 95);
  if (h > 80 && c > 70) return Math.round((h + c) / 2 * 0.5);
  if (h > 70)           return Math.round(h * 0.3);
  return Math.round(c * 0.2);
}

async function loadForecast() {
  const locationEl = document.getElementById('wx-location');
  const loadingEl  = document.getElementById('wx-loading');
  const errorEl    = document.getElementById('wx-error');
  const sectionEl  = document.getElementById('forecastSection');
  const rainfallEl = document.getElementById('rainfallForecast');
  if (!locationEl) return; /* not on dashboard page */

  try {
    const res = await fetch(
      `https://api.openweathermap.org/data/2.5/forecast?lat=${WX.lat}&lon=${WX.lon}&appid=${WX.key}&units=metric`
    );
    if (!res.ok) throw new Error(`HTTP ${res.status}`);
    const data = await res.json();

    locationEl.textContent = `Rainfall Forecast — ${data.city.name}, ${data.city.country}`;
    if (loadingEl) loadingEl.style.display = 'none';

    const daily = {};
    data.list.forEach(item => {
      const key = new Date(item.dt * 1000).toLocaleDateString('en-US', {
        weekday: 'long', month: 'short', day: 'numeric'
      });
      if (!daily[key]) {
        daily[key] = {
          name:  new Date(item.dt * 1000).toLocaleDateString('en-US', { weekday: 'long' }),
          rain:  [],
          chance:[],
          desc:  item.weather[0].description
        };
      }
      daily[key].rain.push(item.rain ? (item.rain['3h'] || 0) : 0);
      daily[key].chance.push(rainChance(item));
    });

    const html = Object.keys(daily).slice(0, 3).map((k, i) => {
      const total = daily[k].rain.reduce((a, b) => a + b, 0);
      const avg   = Math.round(daily[k].chance.reduce((a, b) => a + b, 0) / daily[k].chance.length);
      const label = i === 0 ? 'Today' : i === 1 ? 'Tomorrow' : daily[k].name.slice(0, 3);
      return `<div class="forecast-row">
        <div class="forecast-icon">${wxIcon(daily[k].desc, total)}</div>
        <div>
          <div class="forecast-day">${label}</div>
          <div class="forecast-pct">${avg}% chance of rain</div>
        </div>
        <div class="forecast-right">
          <div class="forecast-predicted">+${Math.round(total * 10)}L</div>
          <div class="forecast-lbl">predicted</div>
        </div>
      </div>`;
    }).join('');

    if (rainfallEl) rainfallEl.innerHTML = html;
    if (sectionEl)  sectionEl.style.display = 'block';

  } catch (e) {
    if (loadingEl) loadingEl.style.display = 'none';
    if (errorEl)   { errorEl.style.display = 'block'; errorEl.textContent = 'Weather unavailable: ' + e.message; }
  }
}


/* ============================================================
   SECTION: DASHBOARD — BAR CHART
   Uses data injected by PHP as window.dashChartLabels / dashChartData
   ============================================================ */

function initDashboardChart() {
  const el = document.getElementById('bar-chart');
  if (!el || typeof window.dashChartLabels === 'undefined') return;

  Chart.defaults.font.family = "'DM Sans', sans-serif";
  Chart.defaults.color = '#94a3b8';
  Chart.defaults.font.size = 11;

  new Chart(el.getContext('2d'), {
    type: 'bar',
    data: {
      labels: window.dashChartLabels,
      datasets: [{
        label: 'Rainwater Collection (L)',
        data:  window.dashChartData,
        backgroundColor:      '#3b82f6',
        hoverBackgroundColor: '#2563eb',
        borderWidth: 0, borderRadius: 5, borderSkipped: false
      }]
    },
    options: {
      responsive: true, maintainAspectRatio: false,
      plugins: {
        legend: { display: true, position: 'top', align: 'end',
          labels: { font: { size: 10, family: 'DM Sans' }, color: '#94a3b8', boxWidth: 18, boxHeight: 7, borderRadius: 3, useBorderRadius: true }
        },
        tooltip: { backgroundColor: '#0f172a', titleFont: { family: 'Sora', size: 11 }, bodyFont: { family: 'DM Sans', size: 11 }, padding: 10, cornerRadius: 8 }
      },
      scales: {
        x: { grid: { display: false }, ticks: { color: '#94a3b8', font: { family: 'DM Sans', size: 11 } } },
        y: { beginAtZero: true, grid: { color: '#f1f5f9' }, ticks: { color: '#94a3b8', font: { family: 'DM Sans', size: 11 } } }
      }
    }
  });
}


/* ============================================================
   SECTION: OVERSIGHT PAGE — USAGE CHART (7 days)
   Uses window.oversightChartLabels / oversightChartData
   ============================================================ */

function initOversightChart() {
  const el = document.getElementById('usageChart');
  if (!el || typeof window.oversightChartLabels === 'undefined') return;

  new Chart(el.getContext('2d'), {
    type: 'bar',
    data: {
      labels: window.oversightChartLabels,
      datasets: [{
        label: 'Liters',
        data:  window.oversightChartData,
        backgroundColor:      '#a78bfa',
        hoverBackgroundColor: '#7c3aed',
        borderWidth: 0, borderRadius: 5, borderSkipped: false
      }]
    },
    options: {
      responsive: true, maintainAspectRatio: false,
      plugins: { legend: { display: false } },
      scales: {
        x: { grid: { display: false }, ticks: { color: '#94a3b8', font: { family: 'DM Sans', size: 10 } } },
        y: { beginAtZero: true, grid: { color: '#f1f5f9' }, ticks: { color: '#94a3b8', font: { family: 'DM Sans', size: 10 } } }
      }
    }
  });
}


/* ============================================================
   SECTION: USAGE PAGE — THREE CHARTS
   Uses window.usageTrendLabels / usageTrendData
         window.usageBarLabels / usageBarRainwater / usageBarTap
         window.usageBreakLabels / usageBreakData
   ============================================================ */

function initUsageCharts() {
  Chart.defaults.font.family = "'Inter', sans-serif";
  Chart.defaults.color = '#9ca3af';
  Chart.defaults.font.size = 11;

  /* ── 30-day trend line ── */
  const tEl = document.getElementById('trendChart');
  if (tEl && typeof window.usageTrendLabels !== 'undefined') {
    const tCtx = tEl.getContext('2d');
    const grad = tCtx.createLinearGradient(0, 0, 0, 220);
    grad.addColorStop(0, 'rgba(59,130,246,0.22)');
    grad.addColorStop(1, 'rgba(59,130,246,0)');
    new Chart(tCtx, {
      type: 'line',
      data: {
        labels: window.usageTrendLabels,
        datasets: [{
          data: window.usageTrendData,
          borderColor: '#3b82f6', borderWidth: 2,
          pointRadius: 0, pointHoverRadius: 5, pointHoverBackgroundColor: '#3b82f6',
          tension: 0.45, fill: true, backgroundColor: grad
        }]
      },
      options: {
        responsive: true,
        plugins: {
          legend: { display: false },
          tooltip: { mode: 'index', intersect: false, backgroundColor: '#fff', borderColor: '#e5e7eb', borderWidth: 1, titleColor: '#111827', bodyColor: '#6b7280', callbacks: { label: ctx => ` ${ctx.raw}L` } }
        },
        scales: {
          x: { grid: { color: '#f3f4f6' }, ticks: { maxTicksLimit: 10 } },
          y: { grid: { color: '#f3f4f6' }, ticks: { callback: v => v + 'L' }, suggestedMin: 0 }
        }
      }
    });
  }

  /* ── Monthly bar chart ── */
  const bEl = document.getElementById('barChart');
  if (bEl && typeof window.usageBarLabels !== 'undefined') {
    new Chart(bEl.getContext('2d'), {
      type: 'bar',
      data: {
        labels: window.usageBarLabels,
        datasets: [
          { label: 'Rainwater', data: window.usageBarRainwater, backgroundColor: 'rgba(59,130,246,0.82)', borderRadius: 6, borderSkipped: false },
          { label: 'Tap Water', data: window.usageBarTap,       backgroundColor: 'rgba(209,213,219,0.85)', borderRadius: 6, borderSkipped: false }
        ]
      },
      options: {
        responsive: true,
        plugins: {
          legend: { display: false },
          tooltip: { backgroundColor: '#fff', borderColor: '#e5e7eb', borderWidth: 1, titleColor: '#111827', bodyColor: '#6b7280', callbacks: { label: ctx => ` ${ctx.dataset.label}: ${ctx.raw}L` } }
        },
        scales: {
          x: { grid: { display: false } },
          y: { grid: { color: '#f3f4f6' }, ticks: { callback: v => v + 'L' } }
        }
      }
    });
  }

  /* ── Usage breakdown doughnut ── */
  const dEl = document.getElementById('doughnutChart');
  if (dEl && typeof window.usageBreakLabels !== 'undefined') {
    const dColors = ['#3b82f6','#10b981','#8b5cf6','#ef4444','#f59e0b','#6b7280'];
    new Chart(dEl.getContext('2d'), {
      type: 'doughnut',
      data: {
        labels: window.usageBreakLabels,
        datasets: [{
          data: window.usageBreakData,
          backgroundColor: dColors.slice(0, window.usageBreakData.length),
          borderWidth: 0, hoverOffset: 6
        }]
      },
      options: {
        cutout: '68%',
        plugins: {
          legend: { position: 'bottom', labels: { padding: 16, usePointStyle: true, pointStyleWidth: 8 } },
          tooltip: { backgroundColor: '#fff', borderColor: '#e5e7eb', borderWidth: 1, titleColor: '#111827', bodyColor: '#6b7280', callbacks: { label: ctx => ` ${ctx.label}: ${ctx.raw}L` } }
        }
      }
    });
  }
}


/* ============================================================
   SECTION: WEATHER PAGE — CHARTS
   Uses window.wxRfLabels / wxRfData
         window.wxNormalPct / wxRainPct / wxAlertPct
   ============================================================ */

function initWeatherCharts() {
  Chart.defaults.font.family = "'Inter', sans-serif";
  Chart.defaults.color = '#9ca3af';
  Chart.defaults.font.size = 11;

  /* ── Rainfall line chart ── */
  const rfEl = document.getElementById('rainfallChart');
  if (rfEl && typeof window.wxRfLabels !== 'undefined') {
    const rfCtx = rfEl.getContext('2d');
    const grad  = rfCtx.createLinearGradient(0, 0, 0, 140);
    grad.addColorStop(0, 'rgba(37,99,235,0.28)');
    grad.addColorStop(1, 'rgba(37,99,235,0)');
    new Chart(rfCtx, {
      type: 'line',
      data: {
        labels: window.wxRfLabels,
        datasets: [{
          data: window.wxRfData,
          borderColor: '#2563eb', borderWidth: 2.5,
          backgroundColor: grad, fill: true, tension: 0.42,
          pointRadius: 3, pointBackgroundColor: '#2563eb', pointHoverRadius: 5
        }]
      },
      options: {
        responsive: true, maintainAspectRatio: false,
        plugins: { legend: { display: false }, tooltip: { backgroundColor: '#0f172a', callbacks: { label: ctx => ` ${ctx.raw} mm` } } },
        scales: {
          x: { grid: { display: false }, ticks: { maxTicksLimit: 7, color: '#94a3b8' } },
          y: { grid: { color: '#f3f4f6' }, beginAtZero: true, ticks: { color: '#94a3b8', callback: v => v + 'mm' } }
        }
      }
    });
  }

  /* ── Sensor inference doughnut ── */
  const doEl = document.getElementById('donutChart');
  if (doEl && typeof window.wxNormalPct !== 'undefined') {
    new Chart(doEl.getContext('2d'), {
      type: 'doughnut',
      data: {
        datasets: [{
          data: [window.wxNormalPct, window.wxRainPct, window.wxAlertPct],
          backgroundColor: ['#2563eb', '#93c5fd', '#f59e0b'],
          borderWidth: 0, hoverOffset: 4
        }]
      },
      options: {
        cutout: '72%', responsive: true,
        plugins: { legend: { display: false }, tooltip: { backgroundColor: '#0f172a', callbacks: { label: ctx => ` ${ctx.parsed}%` } } }
      }
    });
  }
}


/* ============================================================
   SECTION: MAP PAGE — LEAFLET INTEGRATION
   Uses window.mapTanks (array injected by PHP)
   ============================================================ */

function initMap() {
  if (typeof L === 'undefined' || !window.mapTanks) return;

  const DEFAULT_LAT = 8.360015;
  const DEFAULT_LNG = 124.868419;

  const map = L.map('map').setView([DEFAULT_LAT, DEFAULT_LNG], 13);
  L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
    attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
    maxZoom: 19
  }).addTo(map);

  function makeIcon(color) {
    return L.divIcon({
      className: '',
      html: `<svg xmlns="http://www.w3.org/2000/svg" width="32" height="40" viewBox="0 0 32 40">
        <path fill="${color}" stroke="#fff" stroke-width="2" d="M16 2C9.37 2 4 7.37 4 14c0 9 12 24 12 24s12-15 12-24C28 7.37 22.63 2 16 2z"/>
        <circle cx="16" cy="14" r="6" fill="rgba(255,255,255,0.85)"/>
      </svg>`,
      iconSize: [32, 40], iconAnchor: [16, 40], popupAnchor: [0, -42]
    });
  }

  const markerMap = {};

  async function geocode(locationStr) {
    try {
      const url = `https://nominatim.openstreetmap.org/search?q=${encodeURIComponent(locationStr)}&format=json&limit=1`;
      const res  = await fetch(url, { headers: { 'Accept-Language': 'en' } });
      const data = await res.json();
      if (data && data.length > 0) return { lat: parseFloat(data[0].lat), lng: parseFloat(data[0].lon) };
    } catch(e) { console.warn('Geocode failed for:', locationStr, e); }
    return null;
  }

  function placeMarker(tank, lat, lng) {
    const color = tank.status === 'active'
      ? (tank.pct >= 75 ? '#3b82f6' : tank.pct >= 40 ? '#f59e0b' : '#ef4444')
      : (tank.status === 'maintenance' ? '#f59e0b' : '#6b7280');
    const marker = L.marker([lat, lng], { icon: makeIcon(color) }).addTo(map).bindPopup(`
      <div style="font-family:'DM Sans',sans-serif;min-width:180px;">
        <strong style="font-size:14px">${tank.name}</strong><br>
        <span style="font-size:12px;color:#64748b">📍 ${tank.location}</span>
        <div style="margin-top:8px;font-size:13px"><b>${tank.pct}%</b> <span style="color:#64748b">— ${Number(tank.liters).toLocaleString()}L / ${Number(tank.capacity).toLocaleString()}L</span></div>
        <div style="margin-top:6px;height:5px;background:#e2e8f0;border-radius:99px">
          <div style="width:${tank.pct}%;height:100%;background:${color};border-radius:99px"></div>
        </div>
        <div style="margin-top:6px;font-size:11px;color:#94a3b8;text-transform:capitalize">Status: ${tank.status}</div>
      </div>`);
    markerMap[tank.id] = { marker, lat, lng };
    return marker;
  }

  async function loadAllTanks() {
    const bounds = [];
    for (const tank of window.mapTanks) {
      let coords = null;
      if (tank.location && tank.location.trim() !== '') coords = await geocode(tank.location);
      if (!coords) coords = { lat: DEFAULT_LAT + (Math.random()*.01-.005), lng: DEFAULT_LNG + (Math.random()*.01-.005) };
      placeMarker(tank, coords.lat, coords.lng);
      bounds.push([coords.lat, coords.lng]);
    }
    if (bounds.length > 0) map.fitBounds(bounds, { padding: [40, 40] });
    if (window.mapTanks.length > 0) setTimeout(() => focusTank(window.mapTanks[0].id), 600);
  }

  loadAllTanks();

  /* Global so onclick= attributes work */
  window.focusTank = function(tankId) {
    document.querySelectorAll('.tank-card-map').forEach(c => c.classList.remove('selected'));
    const card = document.getElementById('card-' + tankId);
    if (card) { card.classList.add('selected'); card.scrollIntoView({ behavior: 'smooth', block: 'nearest' }); }
    const m = markerMap[tankId];
    if (m) { map.flyTo([m.lat, m.lng], 17, { duration: 1 }); setTimeout(() => m.marker.openPopup(), 900); }
    if (window.innerWidth <= 768) setTimeout(() => closePanel(), 300);
  };

  /* Search filters */
  function filterTanks(q) {
    q = q.toLowerCase();
    document.querySelectorAll('.tank-card-map').forEach(card => {
      const name = card.querySelector('h4').textContent.toLowerCase();
      const loc  = card.querySelector('.card-location').textContent.toLowerCase();
      card.style.display = (name.includes(q) || loc.includes(q)) ? '' : 'none';
    });
  }
  const siDesktop = document.getElementById('searchInput');
  const siMobile  = document.getElementById('searchInputMobile');
  if (siDesktop) siDesktop.addEventListener('input', e => filterTanks(e.target.value));
  if (siMobile)  siMobile.addEventListener('input', e => filterTanks(e.target.value));

  /* Responsive panel toggle */
  function handleResize() {
    const isMobile = window.innerWidth <= 768;
    const panelSearch = document.getElementById('panelSearch');
    if (panelSearch) panelSearch.style.display = isMobile ? 'block' : 'none';
    if (!isMobile) {
      const rp = document.getElementById('rightPanel');
      if (rp) rp.classList.remove('open');
      closeSidebar();
    }
  }
  window.addEventListener('resize', handleResize);
}


/* ============================================================
   SECTION: MAP PAGE — PANEL OPEN / CLOSE (mobile bottom sheet)
   ============================================================ */

function openPanel() {
  const rp = document.getElementById('rightPanel');
  const ps = document.getElementById('panelSearch');
  if (rp) rp.classList.add('open');
  if (ps) ps.style.display = 'block';
}

function closePanel() {
  const rp = document.getElementById('rightPanel');
  if (rp) rp.classList.remove('open');
}


/* ============================================================
   SECTION: WEATHER PAGE — LIVE WEATHER FETCH
   Uses window.WX_LAT, window.WX_LON, window.WX_KEY injected by PHP
   ============================================================ */

function weatherEmoji(id) {
  if (id >= 200 && id < 300) return '⛈️';
  if (id >= 300 && id < 400) return '🌦️';
  if (id >= 500 && id < 600) return '🌧️';
  if (id >= 600 && id < 700) return '❄️';
  if (id >= 700 && id < 800) return '🌫️';
  if (id === 800)             return '☀️';
  if (id === 801 || id === 802) return '⛅';
  return '☁️';
}

/* (Weather page forecast strip is rendered server-side via PHP;
   this function is kept for any client-side re-renders if needed) */


/* ============================================================
   SECTION: PAGE ROUTER — initialise the correct module
   Called at bottom of manager_master.php via inline <script>
   passing the current page slug.
   ============================================================ */

function initPage(page) {
  /* Always wire sidebar on every page */
  /* (toggleSidebar/closeSidebar are already global) */

  switch (page) {

    case 'dashboard':
      initDashboardChart();
      loadForecast();
      break;

    case 'oversight':
      initOversightChart();
      break;

    case 'map':
      initMap();
      break;

    case 'settings':
      initSettings();
      /* hasSuccess is passed as second arg — see manager_master.php */
      break;

    case 'usage':
      initUsageCharts();
      break;

    case 'weather':
      initWeatherCharts();
      break;

    case 'user':
      /* User page uses Bootstrap/jQuery loaded inline; no extra init needed */
      break;

    default:
      /* Default = dashboard */
      initDashboardChart();
      loadForecast();
      break;
  }
}