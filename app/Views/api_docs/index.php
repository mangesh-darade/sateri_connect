<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= esc($title ?? 'API Documentation | Sateri Connect') ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        :root {
            --docs-primary: #10b981;
            --docs-primary-dark: #059669;
            --docs-dark: #0f172a;
            --docs-sidebar-bg: #0b1329;
            --docs-card-bg: #ffffff;
            --docs-code-bg: #0f172a;
        }
        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background-color: #f8fafc;
            color: #334155;
            line-height: 1.6;
        }
        .code-font {
            font-family: 'JetBrains Mono', monospace;
        }
        .docs-navbar {
            background: #ffffff;
            border-bottom: 1px solid #e2e8f0;
            padding: 0.85rem 1.5rem;
            position: sticky;
            top: 0;
            z-index: 100;
        }
        .docs-sidebar {
            width: 280px;
            background: var(--docs-sidebar-bg);
            color: #cbd5e1;
            min-height: calc(100vh - 65px);
            position: sticky;
            top: 65px;
            padding: 1.5rem 1rem;
        }
        .docs-nav-link {
            display: flex;
            align-items: center;
            gap: 0.6rem;
            padding: 0.6rem 0.85rem;
            color: #94a3b8;
            text-decoration: none;
            border-radius: 8px;
            font-size: 0.875rem;
            font-weight: 500;
            transition: all 0.2s ease;
        }
        .docs-nav-link:hover, .docs-nav-link.active {
            color: #ffffff;
            background: rgba(16, 185, 129, 0.15);
        }
        .docs-nav-link.active {
            color: #34d399;
            font-weight: 600;
        }
        .method-badge {
            font-size: 0.72rem;
            font-weight: 700;
            padding: 0.2rem 0.45rem;
            border-radius: 4px;
            text-transform: uppercase;
        }
        .method-post { background: #dcfce7; color: #15803d; }
        .method-get  { background: #e0f2fe; color: #0369a1; }
        .method-put  { background: #fef3c7; color: #b45309; }
        .method-delete { background: #fee2e2; color: #b91c1c; }

        .api-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
            margin-bottom: 2rem;
            overflow: hidden;
        }
        .api-card-header {
            padding: 1.25rem 1.5rem;
            background: #ffffff;
            border-bottom: 1px solid #f1f5f9;
        }
        .api-card-body {
            padding: 1.5rem;
        }
        .code-block {
            background: var(--docs-code-bg);
            color: #e2e8f0;
            border-radius: 8px;
            padding: 1rem;
            position: relative;
            font-size: 0.82rem;
            overflow-x: auto;
        }
        .btn-copy {
            position: absolute;
            top: 0.5rem;
            right: 0.5rem;
            background: rgba(255,255,255,0.1);
            color: #cbd5e1;
            border: none;
            border-radius: 4px;
            padding: 0.25rem 0.6rem;
            font-size: 0.75rem;
            cursor: pointer;
            transition: all 0.2s;
        }
        .btn-copy:hover {
            background: var(--docs-primary);
            color: #ffffff;
        }
        .auth-banner {
            background: linear-gradient(135deg, #064e3b 0%, #022c22 100%);
            color: #ffffff;
            border-radius: 12px;
            padding: 2rem;
            margin-bottom: 2.5rem;
        }
        .tester-card {
            background: #ffffff;
            border: 2px solid #10b981;
            border-radius: 12px;
            box-shadow: 0 10px 25px -5px rgba(16, 185, 129, 0.1);
            margin-bottom: 2.5rem;
            overflow: hidden;
        }
    </style>
</head>
<body>

    <!-- Header Navigation -->
    <nav class="docs-navbar d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div class="d-flex align-items-center gap-3">
            <a href="<?= site_url('dashboard') ?>" class="text-decoration-none d-flex align-items-center gap-2">
                <span class="badge bg-success rounded-pill px-2 py-1"><i class="fab fa-whatsapp"></i></span>
                <span class="fw-bold fs-5 text-dark">Sateri Connect <span class="badge bg-light text-success border ms-1" style="font-size:0.75rem;">API v1</span></span>
            </a>
        </div>
        <div class="d-flex align-items-center gap-2 flex-wrap">
            <a href="<?= site_url('api/v1/postman') ?>" class="btn btn-sm btn-warning text-dark fw-semibold shadow-sm">
                <i class="fas fa-file-arrow-down me-1"></i> Download Postman Collection
            </a>
            <a href="<?= site_url('settings') ?>" class="btn btn-sm btn-outline-secondary"><i class="fas fa-key me-1"></i> Manage API Keys</a>
            <a href="<?= site_url('dashboard') ?>" class="btn btn-sm btn-success"><i class="fas fa-gauge me-1"></i> Dashboard</a>
        </div>
    </nav>

    <div class="d-flex">
        <!-- Sidebar Navigation -->
        <aside class="docs-sidebar d-none d-lg-block">
            <div class="small text-uppercase fw-bold text-muted mb-2 px-2" style="font-size: 0.72rem; letter-spacing: 0.05em;">Getting Started</div>
            <a href="#authentication" class="docs-nav-link active"><i class="fas fa-lock text-success"></i> Authentication</a>
            <a href="#live-tester" class="docs-nav-link text-warning"><i class="fas fa-play text-warning"></i> Live Test Console</a>

            <div class="small text-uppercase fw-bold text-muted mt-4 mb-2 px-2" style="font-size: 0.72rem; letter-spacing: 0.05em;">Endpoints (v1)</div>
            <?php foreach ($spec['endpoints'] as $idx => $ep): ?>
                <a href="#endpoint-<?= $idx ?>" class="docs-nav-link">
                    <span class="method-badge method-<?= strtolower($ep['method']) ?>"><?= esc($ep['method']) ?></span>
                    <span class="text-truncate"><?= esc($ep['title']) ?></span>
                </a>
            <?php endforeach; ?>

            <div class="small text-uppercase fw-bold text-muted mt-4 mb-2 px-2" style="font-size: 0.72rem; letter-spacing: 0.05em;">Webhook Callbacks</div>
            <a href="#webhooks" class="docs-nav-link"><i class="fas fa-satellite-dish text-primary"></i> Inbound Webhooks</a>
        </aside>

        <!-- Main Content -->
        <main class="flex-grow-1 p-4 p-md-5" style="max-width: 1050px;">
            <div class="d-flex justify-content-between align-items-start flex-wrap gap-3 mb-4">
                <div>
                    <span class="badge bg-success-subtle text-success border border-success-subtle px-3 py-1 mb-2 fw-semibold">REST API v1 Developer Hub</span>
                    <h1 class="fw-bold mb-2">Sateri Connect Public REST API</h1>
                    <p class="text-muted fs-6 mb-0">Integrate your CRM, E-commerce store (WooCommerce, Shopify), Lead forms, or custom applications directly with WhatsApp Cloud API automation.</p>
                </div>
                <div>
                    <a href="<?= site_url('api/v1/postman') ?>" class="btn btn-warning text-dark fw-bold px-3 py-2 shadow-sm">
                        <i class="fas fa-download me-1"></i> Postman Collection (.json)
                    </a>
                </div>
            </div>

            <!-- Authentication Banner -->
            <div class="auth-banner" id="authentication">
                <div class="d-flex align-items-center gap-2 mb-2">
                    <i class="fas fa-shield-halved fs-4 text-warning"></i>
                    <h4 class="fw-bold mb-0">API Authentication</h4>
                </div>
                <p class="mb-3 opacity-90">Every API request must include your Secret API Key in the HTTP headers. You can generate and revoke permanent API keys anytime under <strong>Settings &rarr; Developer API Keys</strong>.</p>
                
                <div class="code-block code-font mb-0">
                    <button class="btn-copy js-copy-btn" data-clipboard="X-API-Key: sc_live_your_secret_key_here"><i class="fas fa-copy me-1"></i> Copy Header</button>
                    <span class="text-secondary">// Provide via custom header (Recommended)</span><br>
                    <span class="text-warning">X-API-Key</span>: <span class="text-success">sc_live_98a72b0c41d6...</span><br><br>
                    <span class="text-secondary">// Or standard Bearer Token</span><br>
                    <span class="text-warning">Authorization</span>: <span class="text-info">Bearer sc_live_98a72b0c41d6...</span>
                </div>
            </div>

            <!-- Live Interactive Tester Console -->
            <div class="tester-card" id="live-tester">
                <div class="p-3 bg-light border-bottom d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <div>
                        <span class="badge bg-success text-white me-2">LIVE TESTER</span>
                        <strong class="text-dark">Interactive API Console</strong>
                        <span class="text-muted small ms-1">— Test endpoints right in your browser with real-time response</span>
                    </div>
                    <span class="badge bg-white text-secondary border">CORS Enabled</span>
                </div>

                <div class="p-4">
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">Select Endpoint</label>
                            <select class="form-select form-select-sm" id="testerEndpointSelect">
                                <option value="contacts_upsert">POST /api/v1/contacts/upsert — Create or Update Contact</option>
                                <option value="messages_text">POST /api/v1/messages/send-text — Send WhatsApp Direct Text</option>
                                <option value="messages_media">POST /api/v1/messages/send-media — Send Media (PDF / Image / Video)</option>
                                <option value="messages_template">POST /api/v1/messages/send-template — Send Approved Template</option>
                                <option value="messages_status">GET /api/v1/messages/{id}/status — Check Message Status</option>
                                <option value="templates_list">GET /api/v1/templates — List WhatsApp Templates</option>
                                <option value="automations_trigger">POST /api/v1/automations/trigger — Trigger Workflow Event</option>
                                <option value="contacts_search">GET /api/v1/contacts/search — Search Contacts</option>
                                <option value="account_info">GET /api/v1/account — Account & WABA Status</option>
                                <option value="health_check">GET /api/v1/health — API Health Check</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold">Your API Key <span class="text-danger">*</span></label>
                            <input type="text" class="form-control form-control-sm code-font" id="testerApiKey" placeholder="sc_live_your_key_here" value="<?= !empty($userTokens[0]['id']) ? 'sc_live_••••' : '' ?>">
                            <div class="form-text" style="font-size: 0.72rem;">Enter your API key from Settings &rarr; Developer API Keys.</div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-bold">Request URL</label>
                        <div class="input-group input-group-sm">
                            <span class="input-group-text fw-bold text-success bg-white" id="testerMethodBadge">POST</span>
                            <input type="text" class="form-control code-font" id="testerUrl" readonly value="<?= esc($spec['base_url']) ?>/contacts/upsert">
                        </div>
                    </div>

                    <div class="mb-3" id="testerBodyGroup">
                        <label class="form-label small fw-bold">Request JSON Body</label>
                        <textarea class="form-control code-font" id="testerJsonBody" rows="7" style="font-size: 0.8rem;"></textarea>
                    </div>

                    <div class="d-flex justify-content-between align-items-center">
                        <button type="button" class="btn btn-sm btn-success px-4 fw-bold" id="btnRunTest">
                            <i class="fas fa-paper-plane me-1"></i> Send Live Request
                        </button>
                        <span class="text-muted small" id="testerStatusText"></span>
                    </div>

                    <!-- Test Output Box -->
                    <div class="mt-3 d-none" id="testerOutputGroup">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <span class="small fw-bold text-muted text-uppercase">Live API Response</span>
                            <div>
                                <span class="badge" id="testerStatusCodeBadge">200 OK</span>
                                <span class="badge bg-light text-muted border ms-1" id="testerDurationBadge">120 ms</span>
                            </div>
                        </div>
                        <div class="code-block code-font">
                            <pre class="m-0" id="testerResponseContent" style="white-space: pre-wrap; font-size: 0.82rem;"></pre>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Base URL info -->
            <div class="card p-3 mb-4 bg-light border-0">
                <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                    <div>
                        <span class="small text-muted fw-bold text-uppercase">Production Base URL</span>
                        <div class="code-font fw-bold text-dark fs-6"><?= esc($spec['base_url']) ?></div>
                    </div>
                    <div class="d-flex gap-2">
                        <a href="<?= site_url('api/v1/spec') ?>" target="_blank" class="badge bg-dark text-decoration-none px-3 py-2">OpenAPI JSON</a>
                        <a href="<?= site_url('api/v1/postman') ?>" class="badge bg-warning text-dark text-decoration-none px-3 py-2">Postman (.json)</a>
                    </div>
                </div>
            </div>

            <!-- Endpoints Loop -->
            <h3 class="fw-bold mb-3 mt-5">Core Endpoints Reference</h3>

            <?php foreach ($spec['endpoints'] as $idx => $ep): ?>
                <div class="api-card" id="endpoint-<?= $idx ?>">
                    <div class="api-card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <div class="d-flex align-items-center gap-2">
                            <span class="method-badge method-<?= strtolower($ep['method']) ?> fs-6"><?= esc($ep['method']) ?></span>
                            <span class="code-font fw-bold text-dark fs-6"><?= esc($ep['path']) ?></span>
                        </div>
                        <span class="fw-semibold text-secondary small"><?= esc($ep['title']) ?></span>
                    </div>

                    <div class="api-card-body">
                        <p class="text-secondary mb-3"><?= esc($ep['description']) ?></p>

                        <!-- cURL example -->
                        <?php
                            $fullUrl = $baseUrl . $ep['path'];
                            $curlLines = [];
                            $curlLines[] = "curl -X " . $ep['method'] . " \\";
                            $curlLines[] = "  \"" . $fullUrl . "\" \\";
                            $curlLines[] = "  -H \"X-API-Key: sc_live_YOUR_API_KEY\" \\";
                            if (!empty($ep['body'])) {
                                $curlLines[] = "  -H \"Content-Type: application/json\" \\";
                                $jsonBody = json_encode($ep['body'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                                $curlLines[] = "  -d '" . str_replace("'", "\\'", $jsonBody) . "'";
                            }
                            $curlCmd = implode("\n", $curlLines);
                        ?>

                        <div class="mb-3">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <span class="small fw-bold text-muted text-uppercase">Example Request (cURL)</span>
                            </div>
                            <div class="code-block code-font">
                                <button class="btn-copy js-copy-btn" data-clipboard="<?= esc($curlCmd) ?>"><i class="fas fa-copy me-1"></i> Copy cURL</button>
                                <pre class="m-0" style="white-space: pre-wrap;"><?= esc($curlCmd) ?></pre>
                            </div>
                        </div>

                        <?php if (!empty($ep['response'])): ?>
                            <div>
                                <span class="small fw-bold text-muted text-uppercase">Success Response (200 / 201)</span>
                                <div class="code-block code-font">
                                    <pre class="m-0 text-info" style="white-space: pre-wrap;"><?= esc(json_encode($ep['response'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></pre>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>

            <!-- Inbound Webhooks Section -->
            <div class="api-card" id="webhooks">
                <div class="api-card-header bg-dark text-white">
                    <h5 class="fw-bold mb-0"><i class="fas fa-satellite-dish me-2 text-success"></i> Receiving Inbound Webhooks</h5>
                </div>
                <div class="api-card-body">
                    <p>Sateri Connect automatically routes WhatsApp customer replies and delivery statuses. If you want our system to push inbound events to your server, configure your Webhook URL in <strong>Settings &rarr; Webhooks</strong>.</p>
                    
                    <h6 class="fw-bold mt-3 mb-2">Inbound WhatsApp Message Payload:</h6>
                    <div class="code-block code-font">
<pre class="m-0 text-success">{
  "event": "message_received",
  "contact": {
    "id": 1042,
    "phone": "+919876543210",
    "name": "Mangesh Darade"
  },
  "message": {
    "wamid": "wamid.HBgLMOTE5ODc...",
    "type": "text",
    "text": "Hello, I want to book a demo.",
    "timestamp": 1727938500
  }
}</pre>
                    </div>
                </div>
            </div>

        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        var BASE_URL = '<?= esc($spec['base_url']) ?>';

        var samplePayloads = {
            contacts_upsert: {
                method: 'POST',
                url: BASE_URL + '/contacts/upsert',
                body: {
                    phone: '+919876543210',
                    name: 'Mangesh Darade',
                    email: 'mangesh@example.com',
                    tags: ['VIP Customer', 'Website Lead'],
                    custom_attributes: {
                        city: 'Pune',
                        plan: 'Enterprise'
                    }
                }
            },
            messages_text: {
                method: 'POST',
                url: BASE_URL + '/messages/send-text',
                body: {
                    to: '+919876543210',
                    text: 'Hello Mangesh! Your order #ORD-9988 has been confirmed.'
                }
            },
            messages_media: {
                method: 'POST',
                url: BASE_URL + '/messages/send-media',
                body: {
                    to: '+919876543210',
                    type: 'document',
                    url: 'https://www.w3.org/WAI/ER/tests/xhtml/testfiles/resources/pdf/dummy.pdf',
                    caption: 'Here is your monthly invoice statement.',
                    filename: 'invoice_2026.pdf'
                }
            },
            messages_template: {
                method: 'POST',
                url: BASE_URL + '/messages/send-template',
                body: {
                    to: '+919876543210',
                    template_name: 'order_confirmation',
                    language: 'en_US',
                    variables: ['Mangesh', 'ORD-9988', 'Rs. 1,499']
                }
            },
            messages_status: {
                method: 'GET',
                url: BASE_URL + '/messages/1/status',
                body: null
            },
            templates_list: {
                method: 'GET',
                url: BASE_URL + '/templates?status=APPROVED&page=1&per_page=25',
                body: null
            },
            automations_trigger: {
                method: 'POST',
                url: BASE_URL + '/automations/trigger',
                body: {
                    event: 'order_placed',
                    phone: '+919876543210',
                    name: 'Mangesh',
                    data: {
                        order_id: 'ORD-9988',
                        total: 1499,
                        product: 'Premium Plan'
                    }
                }
            },
            contacts_search: {
                method: 'GET',
                url: BASE_URL + '/contacts/search?q=Mangesh&page=1&per_page=25',
                body: null
            },
            account_info: {
                method: 'GET',
                url: BASE_URL + '/account',
                body: null
            },
            health_check: {
                method: 'GET',
                url: BASE_URL + '/health',
                body: null
            }
        };

        function updateTesterEndpoint() {
            var key = document.getElementById('testerEndpointSelect').value;
            var conf = samplePayloads[key];
            if (!conf) return;

            document.getElementById('testerMethodBadge').textContent = conf.method;
            document.getElementById('testerMethodBadge').className = 'input-group-text fw-bold ' + (conf.method === 'POST' ? 'text-success' : 'text-primary') + ' bg-white';
            document.getElementById('testerUrl').value = conf.url;

            var bodyGroup = document.getElementById('testerBodyGroup');
            if (conf.body) {
                bodyGroup.style.display = 'block';
                document.getElementById('testerJsonBody').value = JSON.stringify(conf.body, null, 2);
            } else {
                bodyGroup.style.display = 'none';
            }
        }

        document.getElementById('testerEndpointSelect').addEventListener('change', updateTesterEndpoint);
        updateTesterEndpoint();

        // Run live test
        document.getElementById('btnRunTest').addEventListener('click', function() {
            var btn = this;
            var apiKey = document.getElementById('testerApiKey').value.trim();
            var url = document.getElementById('testerUrl').value;
            var method = document.getElementById('testerMethodBadge').textContent;
            var jsonBody = document.getElementById('testerJsonBody').value;

            if (!apiKey) {
                alert('Please enter your Secret API Key first (or generate one in Settings > Developer API Keys).');
                document.getElementById('testerApiKey').focus();
                return;
            }

            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Sending…';
            document.getElementById('testerStatusText').textContent = 'Connecting to API endpoint…';

            var startTime = performance.now();

            var fetchOpts = {
                method: method,
                headers: {
                    'X-API-Key': apiKey,
                    'Accept': 'application/json'
                }
            };

            if (method === 'POST' && jsonBody) {
                fetchOpts.headers['Content-Type'] = 'application/json';
                try {
                    JSON.parse(jsonBody); // validate
                    fetchOpts.body = jsonBody;
                } catch(e) {
                    alert('Invalid JSON in request body: ' + e.message);
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fas fa-paper-plane me-1"></i> Send Live Request';
                    return;
                }
            }

            fetch(url, fetchOpts)
                .then(function(res) {
                    var elapsed = Math.round(performance.now() - startTime);
                    return res.text().then(function(text) {
                        return { status: res.status, text: text, elapsed: elapsed };
                    });
                })
                .then(function(result) {
                    document.getElementById('testerOutputGroup').classList.remove('d-none');
                    var badge = document.getElementById('testerStatusCodeBadge');
                    badge.textContent = result.status + ' HTTP';
                    badge.className = 'badge ' + (result.status < 300 ? 'bg-success' : (result.status < 500 ? 'bg-danger' : 'bg-warning text-dark'));

                    document.getElementById('testerDurationBadge').textContent = result.elapsed + ' ms';

                    try {
                        var parsed = JSON.parse(result.text);
                        document.getElementById('testerResponseContent').textContent = JSON.stringify(parsed, null, 2);
                        document.getElementById('testerResponseContent').className = 'm-0 ' + (result.status < 300 ? 'text-success' : 'text-danger');
                    } catch(e) {
                        document.getElementById('testerResponseContent').textContent = result.text;
                    }
                    document.getElementById('testerStatusText').textContent = 'Done (' + result.elapsed + 'ms)';
                })
                .catch(function(err) {
                    document.getElementById('testerOutputGroup').classList.remove('d-none');
                    document.getElementById('testerStatusCodeBadge').textContent = 'Network Error';
                    document.getElementById('testerStatusCodeBadge').className = 'badge bg-danger';
                    document.getElementById('testerResponseContent').textContent = 'Fetch failed: ' + err.message;
                    document.getElementById('testerStatusText').textContent = 'Failed';
                })
                .finally(function() {
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fas fa-paper-plane me-1"></i> Send Live Request';
                });
        });

        // Copy buttons
        document.querySelectorAll('.js-copy-btn').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var text = this.getAttribute('data-clipboard');
                if (navigator.clipboard) {
                    navigator.clipboard.writeText(text);
                }
                var original = this.innerHTML;
                this.innerHTML = '<i class="fas fa-check me-1"></i> Copied!';
                var self = this;
                setTimeout(function() {
                    self.innerHTML = original;
                }, 2000);
            });
        });
    </script>
</body>
</html>
