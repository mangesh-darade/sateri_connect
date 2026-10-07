/**
 * Dashboard Chart.js initialization
 */
(function (window, $) {
    'use strict';

    var charts = window.dashboardCharts || {};

    function initTrendChart() {
        var canvas = document.getElementById('chartTrends');
        if (!canvas || typeof Chart === 'undefined') {
            return;
        }
        var t = charts.trends || {};
        var datasets = [
            { label: 'WhatsApp', data: t.whatsapp || [], borderColor: '#25a35a', backgroundColor: 'rgba(37,211,102,.12)', tension: 0.3, fill: true }
        ];
        if (canvas.getAttribute('data-show-email') === '1') {
            datasets.push({ label: 'Email', data: t.email || [], borderColor: '#5b8def', backgroundColor: 'rgba(91,141,239,.10)', tension: 0.3, fill: true });
        }
        datasets.push(
            { label: 'Replies', data: t.replies || [], borderColor: '#f0a202', backgroundColor: 'transparent', borderDash: [4, 3], tension: 0.3, fill: false },
            { label: 'Failed', data: t.failed || [], borderColor: '#e25555', backgroundColor: 'transparent', tension: 0.3, fill: false }
        );

        new Chart(canvas.getContext('2d'), {
            type: 'line',
            data: {
                labels: t.labels || [],
                datasets: datasets
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                layout: { padding: { bottom: 4 } },
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: { boxWidth: 10, boxHeight: 10, padding: 12, font: { size: 11 } }
                    }
                },
                scales: {
                    x: { ticks: { maxRotation: 0, autoSkipPadding: 8, font: { size: 10 } }, grid: { display: false } },
                    y: { beginAtZero: true, ticks: { precision: 0, font: { size: 10 } }, grid: { color: 'rgba(75,55,134,.06)' } }
                }
            }
        });
    }

    function sumValues(values) {
        var total = 0;
        for (var i = 0; i < values.length; i++) {
            total += Number(values[i]) || 0;
        }
        return total;
    }

    function showCampaignEmptyState(canvas) {
        if (canvas) {
            canvas.classList.add('d-none');
        }
        var empty = document.getElementById('chartCampaignsEmpty');
        if (empty) {
            empty.classList.remove('d-none');
            empty.setAttribute('aria-hidden', 'false');
        }
    }

    function initCampaignChart() {
        var canvas = document.getElementById('chartCampaigns');
        if (!canvas || typeof Chart === 'undefined') {
            return;
        }
        var labels = (charts.campaigns && charts.campaigns.labels) || [];
        var values = (charts.campaigns && charts.campaigns.values) || [];

        if (!labels.length || !values.length || sumValues(values) <= 0) {
            showCampaignEmptyState(canvas);
            return;
        }

        new Chart(canvas.getContext('2d'), {
            type: 'doughnut',
            data: {
                labels: labels,
                datasets: [{
                    data: values,
                    backgroundColor: [
                        '#8e53f7', '#4b3786', '#34B7F1', '#f0a202', '#e25555', '#5b8def', '#6c757d'
                    ],
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '62%',
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: { boxWidth: 10, boxHeight: 10, padding: 12, font: { size: 11 } }
                    }
                }
            }
        });
    }

    $(function () {
        initTrendChart();
        initCampaignChart();
    });
})(window, jQuery);
