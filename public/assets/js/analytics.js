/**
 * Global Analytics charts (Chart.js already on layout)
 */
(function () {
  'use strict';

  if (typeof Chart === 'undefined') return;

  function lineChart(canvas, datasets) {
    if (!canvas) return;
    var labels = [];
    try { labels = JSON.parse(canvas.getAttribute('data-labels') || '[]'); } catch (e) { labels = []; }
    var cfgDatasets = datasets.map(function (d) {
      var values = [];
      try { values = JSON.parse(canvas.getAttribute(d.attr) || '[]'); } catch (e) { values = []; }
      return {
        label: d.label,
        data: values,
        borderColor: d.color,
        backgroundColor: d.bg || 'transparent',
        tension: 0.25,
        fill: !!d.fill,
        pointRadius: 2
      };
    });
    new Chart(canvas, {
      type: 'line',
      data: { labels: labels, datasets: cfgDatasets },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { position: 'bottom' } },
        scales: { y: { beginAtZero: true, ticks: { precision: 0 } } }
      }
    });
  }

  function doughnut(canvas, labels, values, colors) {
    if (!canvas) return;
    new Chart(canvas, {
      type: 'doughnut',
      data: {
        labels: labels,
        datasets: [{ data: values, backgroundColor: colors, borderWidth: 0 }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { position: 'bottom' } }
      }
    });
  }

  lineChart(document.getElementById('waTrendChart'), [
    { label: 'Sent', attr: 'data-sent', color: '#0d9488' },
    { label: 'Delivered', attr: 'data-delivered', color: '#16a34a' },
    { label: 'Failed', attr: 'data-failed', color: '#dc2626' }
  ]);

  var waMix = document.getElementById('waMixChart');
  if (waMix) {
    doughnut(
      waMix,
      ['Delivered', 'Read', 'Failed', 'Replies'],
      [
        parseInt(waMix.getAttribute('data-delivered') || '0', 10),
        parseInt(waMix.getAttribute('data-read') || '0', 10),
        parseInt(waMix.getAttribute('data-failed') || '0', 10),
        parseInt(waMix.getAttribute('data-replies') || '0', 10)
      ],
      ['#16a34a', '#0ea5e9', '#dc2626', '#f59e0b']
    );
  }

  lineChart(document.getElementById('emailTrendChart'), [
    { label: 'Sent', attr: 'data-sent', color: '#16a34a' },
    { label: 'Failed', attr: 'data-failed', color: '#dc2626' }
  ]);

  var emailMix = document.getElementById('emailMixChart');
  if (emailMix) {
    doughnut(
      emailMix,
      ['Sent', 'Failed'],
      [
        parseInt(emailMix.getAttribute('data-sent') || '0', 10),
        parseInt(emailMix.getAttribute('data-failed') || '0', 10)
      ],
      ['#16a34a', '#dc2626']
    );
  }
})();
