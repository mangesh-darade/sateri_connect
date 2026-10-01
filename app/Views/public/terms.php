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
        <h1>Terms of Service</h1>
        <div class="policy-meta">Last Updated: <?= esc($updatedAt) ?> · <?= esc($appName) ?></div>
    </div>

    <p>Welcome to <strong><?= esc($appName) ?></strong>. By accessing or using our platform and messaging automation services, you agree to comply with and be bound by these Terms of Service.</p>

    <h2>1. Platform Service & Account</h2>
    <p>Our platform provides tools to connect official WhatsApp Business Accounts, automate messaging workflows, manage team inboxes, and execute broadcasts. You are responsible for maintaining the confidentiality of your account credentials and for all activities that occur under your account.</p>

    <h2>2. WhatsApp & Meta Policy Compliance</h2>
    <p>Users must comply with all applicable laws and Meta's policies, including:</p>
    <ul>
        <li><strong>Opt-In Requirement:</strong> You must obtain explicit, verifiable consent (opt-in) from recipients before sending WhatsApp messages.</li>
        <li><strong>Acceptable Use:</strong> You may not use the service to transmit spam, unsolicited promotional material, abusive, fraudulent, or prohibited content as defined by WhatsApp Business Policy.</li>
        <li><strong>Account Integrity:</strong> Violations of Meta policies may result in account quality rating downgrades or suspension by Meta directly.</li>
    </ul>

    <h2>3. Service Availability & Modifications</h2>
    <p>While we strive for 99.9% uptime, access may occasionally be interrupted for scheduled maintenance or upstream API modifications by Meta. We reserve the right to modify or discontinue features with prior notice.</p>

    <h2>4. Limitation of Liability</h2>
    <p>To the maximum extent permitted by law, <?= esc($appName) ?> shall not be liable for any indirect, incidental, or consequential damages resulting from upstream carrier network delays, Meta API service interruptions, or account suspensions resulting from policy violations.</p>

    <h2>5. Termination</h2>
    <p>We reserve the right to suspend or terminate accounts that violate these Terms or WhatsApp Business policies. You may terminate your account at any time by contacting platform support.</p>

    <a href="<?= site_url() ?>" class="back-link">&larr; Return to <?= esc($appName) ?></a>
</div>
</body>
</html>
