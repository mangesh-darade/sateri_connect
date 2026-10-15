/**
 * Analytics charts. Status mix uses mutually exclusive buckets.
 */
(function () {
  'use strict';

  if (typeof Chart === 'undefined') return;

  function readJson(canvas, attr) {
    try {
      return JSON.parse(canvas.getAttribute(attr) || '[]');
    } catch (e) {
      return [];
    }
  }

  function lineChart(canvas, datasets) {
    if (!canvas) return;
    var labels = readJson(canvas, 'data-labels');
    var cfg = datasets.map(function (d) {
      return {
        label: d.label,
        data: readJson(canvas, d.attr),
        borderColor: d.color,
        backgroundColor: d.fill,
        tension: 0.35,
        pointRadius: 2,
        pointHoverRadius: 4,
        borderWidth: 2,
        fill: true
      };
    });
    new Chart(canvas, {
      type: 'line',
      data: { labels: labels, datasets: cfg },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { position: 'bottom', labels: { boxWidth: 8, boxHeight: 8, padding: 10, font: { size: 11 } } } },
        scales: {
          x: { grid: { display: false }, ticks: { font: { size: 11 }, maxRotation: 0 } },
          y: { beginAtZero: true, ticks: { precision: 0, font: { size: 11 } }, grid: { color: 'rgba(15, 23, 42, 0.06)' } }
        }
      }
    });
  }

  function doughnut(canvas, colors) {
    if (!canvas) return;
    new Chart(canvas, {
      type: 'doughnut',
      data: {
        labels: readJson(canvas, 'data-labels'),
        datasets: [{ data: readJson(canvas, 'data-values'), backgroundColor: colors, borderWidth: 0 }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        cutout: '62%',
        plugins: { legend: { position: 'bottom', labels: { boxWidth: 8, boxHeight: 8, padding: 8, font: { size: 11 } } } }
      }
    });
  }

  lineChart(document.getElementById('waTrendChart'), [
    { label: 'Sent', attr: 'data-sent', color: '#0d9488', fill: 'rgba(13, 148, 136, 0.12)' },
    { label: 'Delivered', attr: 'data-delivered', color: '#16a34a', fill: 'rgba(22, 163, 74, 0.1)' },
    { label: 'Failed', attr: 'data-failed', color: '#dc2626', fill: 'rgba(220, 38, 38, 0.08)' }
  ]);
  doughnut(document.getElementById('waMixChart'), ['#f59e0b', '#0d9488', '#16a34a', '#0284c7', '#dc2626']);

  lineChart(document.getElementById('emailTrendChart'), [
    { label: 'Sent', attr: 'data-sent', color: '#16a34a', fill: 'rgba(22, 163, 74, 0.12)' },
    { label: 'Queued', attr: 'data-queued', color: '#f59e0b', fill: 'rgba(245, 158, 11, 0.12)' },
    { label: 'Failed', attr: 'data-failed', color: '#dc2626', fill: 'rgba(220, 38, 38, 0.08)' }
  ]);
  doughnut(document.getElementById('emailMixChart'), ['#16a34a', '#f59e0b', '#dc2626']);
})();
