Chart.defaults.font.family = "'DM Sans', Arial, sans-serif";
  Chart.defaults.color = '#9ca3af';
  Chart.defaults.font.size = 11;

  /* 30-day trend */
  const tCtx = document.getElementById('trendChart').getContext('2d');
  const grad = tCtx.createLinearGradient(0, 0, 0, 240);
  grad.addColorStop(0, 'rgba(79,142,247,0.25)');
  grad.addColorStop(1, 'rgba(79,142,247,0)');

  new Chart(tCtx, {
    type: 'line',
    data: {
      labels: Array.from({length:30}, (_,i) => i+1),
      datasets: [{
        data: [178,162,185,190,155,170,192,168,182,178,165,180,195,188,175,
               160,185,200,178,165,180,195,175,192,168,182,188,175,210,195],
        borderColor: '#4f8ef7', borderWidth: 2,
        pointRadius: 0, pointHoverRadius: 5,
        pointHoverBackgroundColor: '#4f8ef7',
        tension: 0.45, fill: true, backgroundColor: grad,
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
          callbacks: { label: ctx => ` ${ctx.raw}L` }
        }
      },
      scales: {
        x: { grid: { color: '#f3f4f6' }, ticks: { maxTicksLimit: 10 } },
        y: {
          grid: { color: '#f3f4f6', drawBorder: false },
          ticks: { callback: v => v + 'L' },
          suggestedMin: 0, suggestedMax: 260
        }
      }
    }
  });

  /* Monthly bar */
  new Chart(document.getElementById('barChart').getContext('2d'), {
    type: 'bar',
    data: {
      labels: ['Oct','Nov','Dec','Jan','Feb','Mar'],
      datasets: [
        { label:'Rainwater', data:[520,480,610,590,550,620],
          backgroundColor:'rgba(79,142,247,0.85)', borderRadius:6, borderSkipped:false },
        { label:'Tap Water', data:[310,290,260,280,250,220],
          backgroundColor:'rgba(209,213,219,0.85)', borderRadius:6, borderSkipped:false }
      ]
    },
    options: {
      responsive: true,
      plugins: {
        legend: { display: false },
        tooltip: {
          backgroundColor: '#fff', borderColor: '#e5e7eb', borderWidth: 1,
          titleColor: '#111827', bodyColor: '#6b7280',
          callbacks: { label: ctx => ` ${ctx.dataset.label}: ${ctx.raw}L` }
        }
      },
      scales: {
        x: { grid: { display: false } },
        y: { grid: { color: '#f3f4f6' }, ticks: { callback: v => v+'L' } }
      }
    }
  });