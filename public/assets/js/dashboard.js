/**
 * Dashboard Chart.js initialization
 */
(function (window, $) {
    'use strict';

    function parseJsonAttr($el, name, fallback) {
        var raw = $el.attr(name);
        if (!raw) {
            return fallback;
        }
        try {
            return JSON.parse(raw);
        } catch (e) {
            return fallback;
        }
    }

    function initTrendChart() {
        var canvas = document.getElementById('chartTrends');
        if (!canvas || typeof Chart === 'undefined') {
            return;
        }
        var $c = $(canvas);
        var labels = parseJsonAttr($c, 'data-labels', []);
        var sent = parseJsonAttr($c, 'data-sent', []);
        var delivered = parseJsonAttr($c, 'data-delivered', []);
        var read = parseJsonAttr($c, 'data-read', []);
        var failed = parseJsonAttr($c, 'data-failed', []);
        var replies = parseJsonAttr($c, 'data-replies', []);

        if (window.dashboardCharts && window.dashboardCharts.trends) {
            labels = window.dashboardCharts.trends.labels || labels;
            sent = window.dashboardCharts.trends.sent || sent;
            delivered = window.dashboardCharts.trends.delivered || delivered;
            read = window.dashboardCharts.trends.read || read;
            failed = window.dashboardCharts.trends.failed || failed;
            replies = window.dashboardCharts.trends.replies || replies;
        }

        new Chart(canvas.getContext('2d'), {
            type: 'line',
            data: {
                labels: labels,
                datasets: [
                    { label: 'Sent', data: sent, borderColor: '#4b3786', backgroundColor: 'rgba(142,83,247,.15)', tension: 0.3, fill: true },
                    { label: 'Delivered', data: delivered, borderColor: '#8e53f7', backgroundColor: 'rgba(142,83,247,.1)', tension: 0.3, fill: false },
                    { label: 'Read', data: read, borderColor: '#34B7F1', backgroundColor: 'transparent', tension: 0.3, fill: false },
                    { label: 'Failed', data: failed, borderColor: '#dc3545', backgroundColor: 'transparent', tension: 0.3, fill: false },
                    { label: 'Replies', data: replies, borderColor: '#fd7e14', backgroundColor: 'transparent', tension: 0.3, fill: false }
                ]
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
        var $c = $(canvas);
        var labels = parseJsonAttr($c, 'data-labels', []);
        var values = parseJsonAttr($c, 'data-values', []);

        if (window.dashboardCharts && window.dashboardCharts.campaigns) {
            labels = window.dashboardCharts.campaigns.labels || labels;
            values = window.dashboardCharts.campaigns.values || values;
        }

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
