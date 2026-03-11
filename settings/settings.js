 function syncThumb(input) {
    const thumb = input.parentElement.querySelector('.toggle-thumb');
    // CSS handles visual state via :checked, thumb movement is CSS too
  }

  function updateSlider(input, valId) {
    const min = +input.min, max = +input.max, val = +input.value;
    const pct = ((val - min) / (max - min) * 100).toFixed(1);
    input.style.setProperty('--val', pct + '%');
    document.getElementById(valId).textContent = val.toLocaleString() + 'L';
  }

  // Init slider on load
  const slider = document.getElementById('threshold');
  updateSlider(slider, 'thresholdVal');

  function saveSettings() {
    const toast = document.getElementById('toast');
    toast.classList.add('show');
    setTimeout(() => toast.classList.remove('show'), 3000);
  }