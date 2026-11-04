(function () {
    'use strict';

    // Platform panel scheduler tick: about once a minute while a platform page is visible, ask the server to
    // run the due cart reminders for the admin's clients. The server throttles repeated calls.
    var token = document.querySelector('meta[name="csrf-token"]');
    var header = document.querySelector('meta[name="csrf-header"]');
    var url = document.documentElement.getAttribute('data-tick-url');
    if (!token || !header || !url) { return; }

    var busy = false;

    function run() {
        if (busy || document.hidden) { return; }
        busy = true;
        var headers = { 'X-Requested-With': 'XMLHttpRequest' };
        headers[header.content] = token.content;
        fetch(url, { method: 'POST', headers: headers, credentials: 'same-origin' })
            .catch(function () { /* the next tick tries again */ })
            .then(function () { busy = false; });
    }

    window.setTimeout(run, 5000);
    window.setInterval(run, 60000);
    document.addEventListener('visibilitychange', function () { if (!document.hidden) { run(); } });
})();
