<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= esc($pageTitle) ?> | <?= esc($appName) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --brand-primary: #0b6e4f;
            --text-main: #1e293b;
            --text-muted: #64748b;
            --bg-page: #f8fafc;
            --bg-card: #ffffff;
            --border: #e2e8f0;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Plus Jakarta Sans', system-ui, sans-serif;
            background: var(--bg-page);
            color: var(--text-main);
            line-height: 1.7;
            padding: 2.5rem 1rem;
        }
        .policy-container {
            max-width: 820px;
            margin: 0 auto;
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 3rem 2.5rem;
            box-shadow: 0 4px 20px -2px rgba(0, 0, 0, 0.05);
        }
        .policy-header {
            border-bottom: 1px solid var(--border);
            padding-bottom: 1.5rem;
            margin-bottom: 2rem;
        }
        .policy-header h1 {
            font-size: 2rem;
            color: var(--text-main);
            font-weight: 700;
            letter-spacing: -0.02em;
        }
        .policy-meta {
            color: var(--text-muted);
            font-size: 0.9rem;
            margin-top: 0.5rem;
        }
        h2 {
            font-size: 1.25rem;
            color: var(--text-main);
            margin: 2rem 0 0.8rem;
            font-weight: 600;
        }
        p, ul {
            margin-bottom: 1.1rem;
            color: #334155;
            font-size: 0.98rem;
        }
        ul { padding-left: 1.5rem; }
        li { margin-bottom: 0.4rem; }
        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            color: var(--brand-primary);
            text-decoration: none;
            font-weight: 600;
            font-size: 0.9rem;
            margin-top: 2.5rem;
            padding-top: 1.5rem;
            border-top: 1px solid var(--border);
            width: 100%;
        }
        .back-link:hover { text-decoration: underline; }
    </style>
</head>
<body>
<div class="policy-container">
    <div class="policy-header">
        <h1>Privacy Policy</h1>
        <div class="policy-meta">Last Updated: <?= esc($updatedAt) ?> · <?= esc($appName) ?></div>
    </div>

    <p>This Privacy Policy explains how <strong><?= esc($appName) ?></strong> ("we", "our", or "us") collects, uses, discloses, and protects information when businesses and end-users utilize our WhatsApp Automation, Messaging, and Platform services, including integrations via Meta's WhatsApp Business Cloud API.</p>

    <h2>1. Information We Collect</h2>
    <p>We process data required to facilitate automated communication, messaging workflows, and team collaboration:</p>
    <ul>
        <li><strong>Account Information:</strong> Business name, email address, administrator credentials, and contact details.</li>
        <li><strong>WhatsApp & Meta Business Information:</strong> WhatsApp Business Account (WABA) ID, Phone Number ID, display names, and authentication tokens provided during authorization.</li>
        <li><strong>Customer & Message Data:</strong> Contact phone numbers, customer names, conversation history, delivery status (sent, delivered, read), and incoming message interactions.</li>
        <li><strong>Technical Information:</strong> IP addresses, browser types, and system logs necessary for security, rate-limiting, and error diagnosis.</li>
    </ul>

    <h2>2. How We Use Information</h2>
    <p>We use the collected information strictly for:</p>
    <ul>
        <li>Delivering authorized WhatsApp broadcasts, sequence campaigns, and customer support messages.</li>
        <li>Processing incoming inquiries via automated chatbot rules, keywords, and agent assignments.</li>
        <li>Aggregating analytics, delivery reports, and system performance metrics.</li>
        <li>Maintaining platform security, authenticating users, and preventing fraud or abuse.</li>
    </ul>

    <h2>3. Meta Platform & WhatsApp Data Protection</h2>
    <p>Our platform strictly adheres to Meta's Developer Policies, Commercial Terms, and WhatsApp Business Messaging Policies:</p>
    <ul>
        <li>We never sell, rent, or monetize end-user message data or phone numbers.</li>
        <li>Data accessed through Meta APIs is used solely to provide messaging functionality directly requested by the account holder.</li>
        <li>All authentication tokens and sensitive credentials are encrypted using industry-standard AES-256 encryption at rest.</li>
    </ul>

    <h2>4. Data Retention and Deletion</h2>
    <p>We retain data only as long as necessary to fulfill business purposes or legal requirements. Account administrators may delete contacts, templates, or message history directly from their workspace. Users may also request data deletion via our <a href="<?= site_url('data-deletion') ?>" style="color:var(--brand-primary)">Data Deletion Request Page</a>.</p>

    <h2>5. Security of Your Information</h2>
    <p>We implement technical and organizational measures to safeguard data against unauthorized access, loss, or alteration, including SSL/TLS encryption in transit, hashed passwords, secure API tokens, and access control matrices.</p>

    <h2>6. Contact Us</h2>
    <p>For questions or data privacy inquiries regarding this Privacy Policy, please contact our Data Protection team through your platform administrator or support channel.</p>

    <a href="<?= site_url() ?>" class="back-link">&larr; Return to <?= esc($appName) ?></a>
</div>
</body>
</html>
