(() => {
  const precision = document.querySelector('#deadline_precision');
  const fields = document.querySelectorAll('.deadline-fields input');
  const toggleFields = () => {
    if (!precision) return;
    const p = precision.value;
    fields.forEach((f, i) => {
      f.disabled = (p === '') || (p === 'year' && i > 0) || (p === 'month' && i > 1);
      if (f.disabled) f.value = '';
    });
  };
  if (precision) {
    precision.addEventListener('change', toggleFields);
    toggleFields();
  }

  if (window.dashboardData && document.querySelector('#stateChart')) {
    new Chart(document.querySelector('#stateChart'), {
      type: 'doughnut',
      data: { labels: window.dashboardData.estadoLabels, datasets: [{ data: window.dashboardData.estados, backgroundColor: ['#c7d2fe','#e9d5ff','#86efac','#fde68a','#a7f3d0','#fecaca'] }] },
      options: { plugins: { legend: { position: 'bottom' } } }
    });

    const pMap = window.dashboardData.prioridades;
    new Chart(document.querySelector('#prioChart'), {
      type: 'bar',
      data: { labels: ['1','2','3','4','5'], datasets: [{ data: [pMap[1]||0,pMap[2]||0,pMap[3]||0,pMap[4]||0,pMap[5]||0], backgroundColor: '#4f46e5' }] },
      options: { scales: { y: { beginAtZero: true, ticks: { precision: 0 } } }, plugins: { legend: { display: false } } }
    });
  }
})();
