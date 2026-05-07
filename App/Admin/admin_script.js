/* ============================================================
   ECORAIN — ADMIN MASTER JAVASCRIPT
   admin_script.js
   Contains all JS extracted from:
     - admin_dashboard.php
     - admin_map.php
     - admin_oversight.php
     - admin_settings.php
     - admin_usage.php
     - admin_userlogs.php
     - admin_weather.php

   NOTE: PHP-generated data (chart labels, tank JSON, etc.)
   is injected by admin_master.php via inline <script> tags
   before this file is loaded, using window.ECORAIN_DATA = {...}
   ============================================================ */


/* ============================================================
   SECTION: SIDEBAR TOGGLE — shared by all pages
   ============================================================ */
function toggleSidebar() {
  document.getElementById('sidebar').classList.toggle('open');
  document.getElementById('overlay').classList.toggle('show');
}

function closeSidebar() {
  document.getElementById('sidebar').classList.remove('open');
  document.getElementById('overlay').classList.remove('show');
}

/* REPLACE the current scroll reset lines at the top of admin_script.js */
requestAnimationFrame(function() {
  window.scrollTo(0, 0);
  document.querySelector('.main')?.scrollTo(0, 0);
  document.documentElement.scrollTop = 0;
  document.body.scrollTop = 0;
});

/* ============================================================
   SECTION: PAGE ROUTER
   Calls the correct init function based on active page
   ============================================================ */
document.addEventListener('DOMContentLoaded', function () {
  var page = (typeof ECORAIN_PAGE !== 'undefined') ? ECORAIN_PAGE : 'dashboard';

  switch (page) {
    case 'dashboard':  initDashboard();  break;
    case 'map':        initMap();        break;
    case 'oversight':  initOversight();  break;
    case 'settings':   initSettings();   break;
    case 'usage':      initUsage();      break;
    case 'userlogs':   initUserLogs();   break;
    case 'weather':    initWeather();    break;
    default:           initDashboard();  break;
  }
});


/* ============================================================
   SECTION: DASHBOARD — admin_dashboard.php JS
   Depends on: window.ECORAIN_DATA.chartLabels, chartData
   ============================================================ */
function initDashboard() {
  /* -- Bar chart: Water Usage last 7 days -- */
  var barCtx = document.getElementById('bar-chart');
  if (!barCtx) return;

  var data = window.ECORAIN_DATA || {};

  new Chart(barCtx.getContext('2d'), {
    type: 'bar',
    data: {
      labels: data.chartLabels || [],
      datasets: [{
        label: 'Rainwater Collection (L)',
        data: data.chartData || [],
        backgroundColor: '#3b82f6',
        hoverBackgroundColor: '#2563eb',
        borderWidth: 0,
        borderRadius: 5,
        borderSkipped: false,
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: {
          display: true, position: 'top', align: 'end',
          labels: {
            font: { size: 10, family: 'DM Sans' },
            color: '#94a3b8', boxWidth: 18, boxHeight: 7,
            borderRadius: 3, useBorderRadius: true
          }
        },
        tooltip: {
          backgroundColor: '#0f172a',
          titleFont: { family: 'Sora', size: 11 },
          bodyFont: { family: 'DM Sans', size: 11 },
          padding: 10, cornerRadius: 8
        }
      },
      scales: {
        x: {
          grid: { display: false },
          ticks: { color: '#94a3b8', font: { family: 'DM Sans', size: 11 } }
        },
        y: {
          beginAtZero: true,
          grid: { color: '#f1f5f9' },
          ticks: { color: '#94a3b8', font: { family: 'DM Sans', size: 11 } }
        }
      }
    }
  });

  /* -- Rainfall forecast via OpenWeatherMap -- */
  loadForecast();
}

/* Dashboard: weather constants (set from PHP via ECORAIN_DATA) */
function wxIcon(desc, rain) {
  if (rain > 5)  return '🌧️';
  if (rain > 0)  return '🌦️';
  if (desc.includes('cloud')) return '☁️';
  if (desc.includes('clear') || desc.includes('sun')) return '☀️';
  return '🌤️';
}

function rainChance(item) {
  var hr = item.rain && item.rain['3h'] > 0;
  var h  = item.main.humidity, c = item.clouds.all;
  if (hr)          return Math.min(Math.round(h * 0.7 + c * 0.3), 95);
  if (h > 80 && c > 70) return Math.round((h + c) / 2 * 0.5);
  if (h > 70)      return Math.round(h * 0.3);
  return Math.round(c * 0.2);
}

async function loadForecast() {
  var WX = window.ECORAIN_DATA && window.ECORAIN_DATA.wx ? window.ECORAIN_DATA.wx : {};
  if (!WX.key) return;

  try {
    var res  = await fetch('https://api.openweathermap.org/data/2.5/forecast?lat=' + WX.lat + '&lon=' + WX.lon + '&appid=' + WX.key + '&units=metric');
    if (!res.ok) throw new Error('HTTP ' + res.status);
    var data = await res.json();

    document.getElementById('wx-location').textContent = 'Rainfall Forecast — ' + data.city.name + ', ' + data.city.country;
    document.getElementById('wx-loading').style.display = 'none';

    var daily = {};
    data.list.forEach(function (item) {
      var key = new Date(item.dt * 1000).toLocaleDateString('en-US', { weekday: 'long', month: 'short', day: 'numeric' });
      if (!daily[key]) daily[key] = { name: new Date(item.dt * 1000).toLocaleDateString('en-US', { weekday: 'long' }), rain: [], chance: [], desc: item.weather[0].description };
      daily[key].rain.push(item.rain ? (item.rain['3h'] || 0) : 0);
      daily[key].chance.push(rainChance(item));
    });

    var html = Object.keys(daily).slice(0, 3).map(function (k, i) {
      var total = daily[k].rain.reduce(function (a, b) { return a + b; }, 0);
      var avg   = Math.round(daily[k].chance.reduce(function (a, b) { return a + b; }, 0) / daily[k].chance.length);
      var label = i === 0 ? 'Today' : i === 1 ? 'Tomorrow' : daily[k].name.slice(0, 3);
      return '<div class="forecast-row">'
        + '<div class="forecast-icon">' + wxIcon(daily[k].desc, total) + '</div>'
        + '<div><div class="forecast-day">' + label + '</div><div class="forecast-pct">' + avg + '% chance of rain</div></div>'
        + '<div class="forecast-right"><div class="forecast-predicted">+' + Math.round(total * 10) + 'L</div><div class="forecast-lbl">predicted</div></div>'
        + '</div>';
    }).join('');

    document.getElementById('rainfallForecast').innerHTML = html;
    document.getElementById('forecastSection').style.display = 'block';

  } catch (e) {
    document.getElementById('wx-loading').style.display = 'none';
    var errEl = document.getElementById('wx-error');
    errEl.style.display = 'block';
    errEl.textContent = 'Weather unavailable: ' + e.message;
  }
}


/* ============================================================
   SECTION: MAP — admin_map.php JS
   Depends on: window.ECORAIN_DATA.tanks (array of tank objects)
   ============================================================ */
function initMap() {
  /* Safety check — Leaflet must be loaded */
  if (typeof L === 'undefined') return;

  var tanks       = (window.ECORAIN_DATA && window.ECORAIN_DATA.tanks) ? window.ECORAIN_DATA.tanks : [];
  var DEFAULT_LAT = 8.360015;
  var DEFAULT_LNG = 124.868419;

  var map = L.map('map').setView([DEFAULT_LAT, DEFAULT_LNG], 13);
  L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
    attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
    maxZoom: 19
  }).addTo(map);

  /* -- Custom pin icon -- */
  function makeIcon(color) {
    return L.divIcon({
      className: '',
      html: '<svg xmlns="http://www.w3.org/2000/svg" width="32" height="40" viewBox="0 0 32 40">'
          + '<path fill="' + color + '" stroke="#fff" stroke-width="2" d="M16 2C9.37 2 4 7.37 4 14c0 9 12 24 12 24s12-15 12-24C28 7.37 22.63 2 16 2z"/>'
          + '<circle cx="16" cy="14" r="6" fill="rgba(255,255,255,0.85)"/>'
          + '</svg>',
      iconSize: [32, 40], iconAnchor: [16, 40], popupAnchor: [0, -42]
    });
  }

  var markerMap = {};

  /* -- Geocode a location string via Nominatim -- */
  async function geocode(locationStr) {
    try {
      var url  = 'https://nominatim.openstreetmap.org/search?q=' + encodeURIComponent(locationStr) + '&format=json&limit=1';
      var res  = await fetch(url, { headers: { 'Accept-Language': 'en' } });
      var data = await res.json();
      if (data && data.length > 0) return { lat: parseFloat(data[0].lat), lng: parseFloat(data[0].lon) };
    } catch (e) { console.warn('Geocode failed for:', locationStr, e); }
    return null;
  }

  /* -- Place a marker on the map -- */
  function placeMarker(tank, lat, lng) {
    var color = tank.status === 'active'
      ? (tank.pct >= 75 ? '#3b82f6' : tank.pct >= 40 ? '#f59e0b' : '#ef4444')
      : (tank.status === 'maintenance' ? '#f59e0b' : '#6b7280');

    var marker = L.marker([lat, lng], { icon: makeIcon(color) }).addTo(map).bindPopup(
      '<div style="font-family:\'DM Sans\',sans-serif;min-width:180px;">'
      + '<strong style="font-size:14px">' + tank.name + '</strong><br>'
      + '<span style="font-size:12px;color:#64748b">📍 ' + tank.location + '</span>'
      + '<div style="margin-top:8px;font-size:13px"><b>' + tank.pct + '%</b> <span style="color:#64748b">— ' + Number(tank.liters).toLocaleString() + 'L / ' + Number(tank.capacity).toLocaleString() + 'L</span></div>'
      + '<div style="margin-top:6px;height:5px;background:#e2e8f0;border-radius:99px">'
      + '<div style="width:' + tank.pct + '%;height:100%;background:' + color + ';border-radius:99px"></div></div>'
      + '<div style="margin-top:6px;font-size:11px;color:#94a3b8;text-transform:capitalize">Status: ' + tank.status + '</div>'
      + '</div>'
    );
    markerMap[tank.id] = { marker: marker, lat: lat, lng: lng };
    return marker;
  }

  /* -- Load all tanks, geocode, then fit bounds -- */
  async function loadAllTanks() {
    var bounds = [];
    for (var i = 0; i < tanks.length; i++) {
      var tank   = tanks[i];
      var coords = null;
      if (tank.location && tank.location.trim() !== '') coords = await geocode(tank.location);
      if (!coords) coords = { lat: DEFAULT_LAT + (Math.random() * .01 - .005), lng: DEFAULT_LNG + (Math.random() * .01 - .005) };
      placeMarker(tank, coords.lat, coords.lng);
      bounds.push([coords.lat, coords.lng]);
    }
    if (bounds.length > 0) map.fitBounds(bounds, { padding: [40, 40] });
    if (tanks.length > 0) setTimeout(function () { focusTank(tanks[0].id); }, 600);
  }

  loadAllTanks();

  /* -- Focus map on a tank card click -- */
  window.focusTank = function (tankId) {
    document.querySelectorAll('.map-tank-card').forEach(function (c) { c.classList.remove('selected'); });
    var card = document.getElementById('card-' + tankId);
    if (card) { card.classList.add('selected'); card.scrollIntoView({ behavior: 'smooth', block: 'nearest' }); }
    var m = markerMap[tankId];
    if (m) { map.flyTo([m.lat, m.lng], 17, { duration: 1 }); setTimeout(function () { m.marker.openPopup(); }, 900); }
    if (window.innerWidth <= 768) setTimeout(closePanel, 300);
  };

  /* -- Search filter -- */
  function filterTanks(q) {
    q = q.toLowerCase();
    document.querySelectorAll('.map-tank-card').forEach(function (card) {
      var name = card.querySelector('h4').textContent.toLowerCase();
      var loc  = card.querySelector('.card-location').textContent.toLowerCase();
      card.style.display = (name.includes(q) || loc.includes(q)) ? '' : 'none';
    });
  }

  var desktopSearch = document.getElementById('searchInput');
  var mobileSearch  = document.getElementById('searchInputMobile');
  if (desktopSearch) desktopSearch.addEventListener('input', function () { filterTanks(this.value); });
  if (mobileSearch)  mobileSearch.addEventListener('input',  function () { filterTanks(this.value); });

  /* -- Mobile bottom panel -- */
  window.openPanel = function () {
    document.getElementById('rightPanel').classList.add('open');
    var ps = document.getElementById('panelSearch');
    if (ps) ps.style.display = 'block';
  };
  window.closePanel = function () {
    document.getElementById('rightPanel').classList.remove('open');
  };

  function handleResize() {
    var isMobile = window.innerWidth <= 768;
    var ps = document.getElementById('panelSearch');
    if (ps) ps.style.display = isMobile ? 'block' : 'none';
    if (!isMobile) {
      document.getElementById('rightPanel').classList.remove('open');
      closeSidebar();
    }
  }
  window.addEventListener('resize', handleResize);
}


/* ============================================================
   SECTION: OVERSIGHT — admin_oversight.php JS
   Depends on: window.ECORAIN_DATA.roleChart, actionChart
   ============================================================ */
function initOversight() {
  Chart.defaults.font.family = "'DM Sans', sans-serif";
  Chart.defaults.color       = '#94a3b8';
  Chart.defaults.font.size   = 11;

  var data = window.ECORAIN_DATA || {};

  /* -- Role activity line chart -- */
  var roleCtx = document.getElementById('roleChart');
  if (roleCtx) {
    new Chart(roleCtx.getContext('2d'), {
      type: 'line',
      data: {
        labels: data.roleChartLabels || [],
        datasets: [
          { label: 'Admin',   data: data.roleChartAdmin   || [], borderColor: '#3b82f6', backgroundColor: 'rgba(59,130,246,.08)',   tension: .4, fill: true, borderWidth: 2, pointRadius: 3 },
          { label: 'Manager', data: data.roleChartManager || [], borderColor: '#8b5cf6', backgroundColor: 'rgba(139,92,246,.06)',  tension: .4, fill: true, borderWidth: 2, pointRadius: 3 },
          { label: 'User',    data: data.roleChartUser    || [], borderColor: '#10b981', backgroundColor: 'rgba(16,185,129,.06)', tension: .4, fill: true, borderWidth: 2, pointRadius: 3 },
        ]
      },
      options: {
        responsive: true, maintainAspectRatio: false,
        plugins: {
          legend: { labels: { font: { family: 'DM Sans', size: 11 }, color: '#64748b', boxWidth: 12, boxHeight: 4, borderRadius: 2, useBorderRadius: true } }
        },
        scales: {
          x: { grid: { display: false }, ticks: { color: '#94a3b8', font: { family: 'DM Sans', size: 11 } } },
          y: { beginAtZero: true, grid: { color: '#f1f5f9' }, ticks: { color: '#94a3b8', font: { family: 'DM Sans', size: 11 } } }
        }
      }
    });
  }

  /* -- Action frequency horizontal bar chart -- */
  var actionCtx = document.getElementById('actionChart');
  if (actionCtx) {
    new Chart(actionCtx.getContext('2d'), {
      type: 'bar',
      data: {
        labels: data.chartActionLabels || [],
        datasets: [{
          label: 'Count',
          data: data.chartActionData || [],
          backgroundColor: ['#3b82f6','#8b5cf6','#10b981','#f59e0b','#ef4444','#06b6d4','#ec4899','#6366f1'],
          borderWidth: 0, borderRadius: 6, borderSkipped: false,
        }]
      },
      options: {
        indexAxis: 'y', responsive: true, maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: {
          x: { grid: { color: '#f1f5f9' }, ticks: { color: '#94a3b8', font: { family: 'DM Sans', size: 10 } } },
          y: { grid: { display: false },   ticks: { color: '#374151', font: { family: 'DM Sans', size: 11 } } }
        }
      }
    });
  }

  /* -- Tabs -- */
  window.switchTab = function (id, el) {
    document.querySelectorAll('[id^="tab-"]').forEach(function (t) { t.style.display = 'none'; });
    document.getElementById('tab-' + id).style.display = '';
    document.querySelectorAll('.tab').forEach(function (t) { t.classList.remove('active'); });
    el.classList.add('active');
  };
}


/* ============================================================
   SECTION: SETTINGS — admin_settings.php JS
   Depends on: window.ECORAIN_DATA.maxCap (for slider)
   ============================================================ */
function initSettings() {
  /* -- Range slider update -- */
  window.updateSlider = function (el, valId) {
    var max = parseInt(el.max) || 5000;
    var val = parseInt(el.value);
    el.style.setProperty('--val', Math.round(val / max * 100) + '%');
    document.getElementById(valId).textContent = val.toLocaleString() + 'L';
  };

  var tankCapInput = document.getElementById('tankCapacity');
  if (tankCapInput) {
    tankCapInput.addEventListener('input', function () {
      var s      = document.getElementById('threshold');
      var newMax = parseInt(this.value) || 5000;
      if (parseInt(s.value) > newMax) s.value = newMax;
      s.max = newMax;
      updateSlider(s, 'thresholdVal');
    });
  }

  /* -- Add tank form toggle -- */
  window.showAddTankForm = function () {
    var wrap = document.getElementById('addTankForm');
    if (!wrap) return;
    wrap.style.display = 'block';
    void wrap.offsetWidth;
    wrap.style.animation = 'none';
    requestAnimationFrame(function () { wrap.style.animation = ''; });
  };

  window.hideAddTankForm = function () {
    var wrap = document.getElementById('addTankForm');
    if (!wrap) return;
    wrap.classList.add('fgen-collapsing');
    wrap.addEventListener('animationend', function () {
      wrap.style.display = 'none';
      wrap.classList.remove('fgen-collapsing');
    }, { once: true });
  };

  var addTankForm = document.querySelector('#addTankForm .fgen-form');
  if (addTankForm) {
    addTankForm.addEventListener('submit', function (e) {
      e.preventDefault();
      var wrap = document.getElementById('addTankForm');
      wrap.classList.add('fgen-collapsing');
      var self = this;
      wrap.addEventListener('animationend', function () { self.submit(); }, { once: true });
    });
  }

  /* -- Delete confirm modal -- */
  window.confirmDelete = function (tankId, tankName) {
    document.getElementById('deleteTankId').value        = tankId;
    document.getElementById('modalTankName').textContent = '"' + tankName + '"';
    document.getElementById('deleteModal').classList.add('show');
  };
  window.closeDeleteModal = function () {
    document.getElementById('deleteModal').classList.remove('show');
  };
  window.submitDelete = function () {
    document.getElementById('deleteTankForm').submit();
  };

  var delModal = document.getElementById('deleteModal');
  if (delModal) {
    delModal.addEventListener('click', function (e) {
      if (e.target === this) closeDeleteModal();
    });
  }

  /* -- Toast on success -- */
  if (window.ECORAIN_DATA && window.ECORAIN_DATA.settingsSaved) {
    var t = document.getElementById('toast');
    if (t) {
      t.classList.add('show');
      setTimeout(function () { t.classList.remove('show'); }, 3200);
    }
  }

  /* -- Auto-dismiss flash messages -- */
  setTimeout(function () {
    document.querySelectorAll('.flash').forEach(function (a) {
      a.style.transition = 'opacity .5s';
      a.style.opacity    = '0';
      setTimeout(function () { a.remove(); }, 500);
    });
  }, 4000);
}


/* ============================================================
   SECTION: USAGE — admin_usage.php JS
   Depends on: window.ECORAIN_DATA.usage (chart data)
   ============================================================ */
function initUsage() {
  Chart.defaults.font.family = "'Inter', sans-serif";
  Chart.defaults.color       = '#9ca3af';
  Chart.defaults.font.size   = 11;

  var d = (window.ECORAIN_DATA && window.ECORAIN_DATA.usage) ? window.ECORAIN_DATA.usage : {};

  /* -- 30-day trend line chart -- */
  var tCtx = document.getElementById('trendChart');
  if (tCtx) {
    var ctx  = tCtx.getContext('2d');
    var grad = ctx.createLinearGradient(0, 0, 0, 220);
    grad.addColorStop(0, 'rgba(59,130,246,0.22)');
    grad.addColorStop(1, 'rgba(59,130,246,0)');
    new Chart(ctx, {
      type: 'line',
      data: {
        labels: d.trendLabels || [],
        datasets: [{
          data: d.trendData || [],
          borderColor: '#3b82f6', borderWidth: 2,
          pointRadius: 0, pointHoverRadius: 5,
          pointHoverBackgroundColor: '#3b82f6',
          tension: 0.45, fill: true, backgroundColor: grad
        }]
      },
      options: {
        responsive: true,
        plugins: {
          legend: { display: false },
          tooltip: {
            mode: 'index', intersect: false,
            backgroundColor: '#fff', borderColor: '#e5e7eb', borderWidth: 1,
            titleColor: '#111827', bodyColor: '#6b7280',
            callbacks: { label: function (ctx) { return ' ' + ctx.raw + 'L'; } }
          }
        },
        scales: {
          x: { grid: { color: '#f3f4f6' }, ticks: { maxTicksLimit: 10 } },
          y: { grid: { color: '#f3f4f6' }, ticks: { callback: function (v) { return v + 'L'; } }, suggestedMin: 0 }
        }
      }
    });
  }

  /* -- Monthly comparison grouped bar chart -- */
  var barCtx = document.getElementById('barChart');
  if (barCtx) {
    new Chart(barCtx.getContext('2d'), {
      type: 'bar',
      data: {
        labels: d.barLabels || [],
        datasets: [
          { label: 'Rainwater', data: d.barRainwater || [], backgroundColor: 'rgba(59,130,246,0.82)', borderRadius: 6, borderSkipped: false },
          { label: 'Tap Water', data: d.barTap       || [], backgroundColor: 'rgba(209,213,219,0.85)', borderRadius: 6, borderSkipped: false }
        ]
      },
      options: {
        responsive: true,
        plugins: {
          legend: { display: false },
          tooltip: {
            backgroundColor: '#fff', borderColor: '#e5e7eb', borderWidth: 1,
            titleColor: '#111827', bodyColor: '#6b7280',
            callbacks: { label: function (ctx) { return ' ' + ctx.dataset.label + ': ' + ctx.raw + 'L'; } }
          }
        },
        scales: {
          x: { grid: { display: false } },
          y: { grid: { color: '#f3f4f6' }, ticks: { callback: function (v) { return v + 'L'; } } }
        }
      }
    });
  }

  /* -- Usage breakdown doughnut -- */
  var doCtx = document.getElementById('doughnutChart');
  if (doCtx && d.breakData && d.breakData.length > 0 && d.breakData.reduce(function (a, b) { return a + b; }, 0) > 0) {
    var dColors = ['#3b82f6','#10b981','#8b5cf6','#ef4444','#f59e0b','#6b7280'];
    new Chart(doCtx.getContext('2d'), {
      type: 'doughnut',
      data: {
        labels: d.breakLabels || [],
        datasets: [{
          data: d.breakData,
          backgroundColor: dColors.slice(0, d.breakData.length),
          borderWidth: 0, hoverOffset: 6
        }]
      },
      options: {
        cutout: '68%',
        plugins: {
          legend: { position: 'bottom', labels: { padding: 16, usePointStyle: true, pointStyleWidth: 8 } },
          tooltip: {
            backgroundColor: '#fff', borderColor: '#e5e7eb', borderWidth: 1,
            titleColor: '#111827', bodyColor: '#6b7280',
            callbacks: { label: function (ctx) { return ' ' + ctx.label + ': ' + ctx.raw + 'L'; } }
          }
        }
      }
    });
  }
}


/* ============================================================
   SECTION: USER LOGS — admin_userlogs.php JS
   Uses jQuery (Bootstrap 4 page) — initialised inside DOM ready
   ============================================================ */
function initUserLogs() {
  /* jQuery is loaded by Bootstrap 4 on this page */
  if (typeof $ === 'undefined') return;

  var $checkboxes = $('input.row-checkbox');

  /* Select-all toggle */
  $('#selectAll').on('click', function () {
    $checkboxes.prop('checked', this.checked);
  });

  /* Deselect header checkbox if any row is unchecked */
  $checkboxes.on('click', function () {
    if (!this.checked) $('#selectAll').prop('checked', false);
  });

  /* Populate edit modal */
  $(document).on('click', 'a.action-link.edit', function () {
    $('#editUserId').val($(this).data('id'));
    $('#editEmail').val($(this).data('email'));
    $('#editRole').val($(this).data('role'));
  });

  /* Populate single-delete modal */
  $(document).on('click', 'a.action-link.delete', function () {
    $('#deleteUserId').val($(this).data('id'));
    $('#deleteUserEmail').text($(this).data('email'));
  });

  /* Bulk delete — prevent modal if nothing selected */
  $('#bulkDeleteBtn').on('click', function (e) {
    var ids = $checkboxes.filter(':checked').map(function () { return this.value; }).get();
    if (!ids.length) {
      e.preventDefault();
      e.stopImmediatePropagation();
      alert('Please select at least one user.');
      return false;
    }
    $('#bulkDeleteIds').val(ids.join(','));
    $('#bulkDeleteCount').text(ids.length);
  });

  /* Auto-dismiss Bootstrap alerts */
  setTimeout(function () { $('.alert').alert('close'); }, 4000);
}


/* ============================================================
   SECTION: WEATHER — admin_weather.php JS
   Depends on: window.ECORAIN_DATA.weather (chart data + donut)
   ============================================================ */
function initWeather() {
  Chart.defaults.font.family = "'Inter', sans-serif";
  Chart.defaults.color       = '#9ca3af';
  Chart.defaults.font.size   = 11;

  var d = (window.ECORAIN_DATA && window.ECORAIN_DATA.weather) ? window.ECORAIN_DATA.weather : {};

  /* -- Rainfall collection 14-day line chart -- */
  var rfCtx = document.getElementById('rainfallChart');
  if (rfCtx) {
    var ctx  = rfCtx.getContext('2d');
    var grad = ctx.createLinearGradient(0, 0, 0, 140);
    grad.addColorStop(0, 'rgba(37,99,235,0.28)');
    grad.addColorStop(1, 'rgba(37,99,235,0)');
    new Chart(ctx, {
      type: 'line',
      data: {
        labels: d.rfLabels || [],
        datasets: [{
          data: d.rfData || [],
          borderColor: '#2563eb', borderWidth: 2.5,
          backgroundColor: grad, fill: true, tension: 0.42,
          pointRadius: 3, pointBackgroundColor: '#2563eb', pointHoverRadius: 5
        }]
      },
      options: {
        responsive: true, maintainAspectRatio: false,
        plugins: {
          legend: { display: false },
          tooltip: { backgroundColor: '#0f172a', callbacks: { label: function (ctx) { return ' ' + ctx.raw + ' mm'; } } }
        },
        scales: {
          x: { grid: { display: false }, ticks: { maxTicksLimit: 7, color: '#94a3b8' } },
          y: { grid: { color: '#f3f4f6' }, beginAtZero: true, ticks: { color: '#94a3b8', callback: function (v) { return v + 'mm'; } } }
        }
      }
    });
  }

  /* -- Sensor inference doughnut chart -- */
  var doCtx = document.getElementById('donutChart');
  if (doCtx) {
    new Chart(doCtx.getContext('2d'), {
      type: 'doughnut',
      data: {
        datasets: [{
          data: [d.normalPct || 84, d.rainPct || 11, d.alertPct || 5],
          backgroundColor: ['#2563eb','#93c5fd','#f59e0b'],
          borderWidth: 0, hoverOffset: 4
        }]
      },
      options: {
        cutout: '72%', responsive: true,
        plugins: {
          legend: { display: false },
          tooltip: { backgroundColor: '#0f172a', callbacks: { label: function (ctx) { return ' ' + ctx.parsed + '%'; } } }
        }
      }
    });
  }
}