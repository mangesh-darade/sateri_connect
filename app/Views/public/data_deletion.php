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
        .code-box {
            background: #f1f5f9;
            border: 1px dashed #cbd5e1;
            padding: 1rem 1.25rem;
            border-radius: 8px;
            margin: 1.5rem 0;
            font-family: monospace;
            font-size: 0.95rem;
        }
        h2 {
            font-size: 1.25rem;
            color: var(--text-main);
            margin: 2rem 0 0.8rem;
            font-weight: 600;
        }
        p, ol {
            margin-bottom: 1.1rem;
            color: #334155;
            font-size: 0.98rem;
        }
        ol { padding-left: 1.5rem; }
        li { margin-bottom: 0.5rem; }
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
        <h1>User Data Deletion Request</h1>
        <p style="color:var(--text-muted);font-size:0.9rem;margin-top:0.4rem;">Meta Platform & GDPR Compliance · <?= esc($appName) ?></p>
    </div>

    <?php if (! empty($code)): ?>
        <div class="code-box">
            <strong>Deletion Request Confirmation ID:</strong><br>
            <span style="color:var(--brand-primary);font-size:1.1rem;font-weight:700"><?= esc($code) ?></span>
            <?php if (! empty($request)): ?>
                <p style="margin:0.5rem 0 0;font-size:0.88rem;color:#475569">
                    Status: <strong><?= esc(ucfirst((string) $request['status'])) ?></strong>
                    · Received <?= esc((string) $request['requested_at']) ?>
                    <?php if (! empty($request['completed_at'])): ?> · Completed <?= esc((string) $request['completed_at']) ?><?php endif; ?>
                </p>
                <?php if (! empty($request['notes'])): ?>
                    <p style="margin:0.4rem 0 0;font-size:0.85rem;color:#475569"><?= esc((string) $request['notes']) ?></p>
                <?php endif; ?>
            <?php else: ?>
                <p style="margin:0.5rem 0 0;font-size:0.88rem;color:#b45309">No deletion request was found for this confirmation ID.</p>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <p>In compliance with Meta Platform Policies and Data Protection regulations (GDPR / DPDP), users and businesses interacting with <strong><?= esc($appName) ?></strong> have the right to request the deletion of their personal data and account records.</p>

    <h2>How to Request Data Deletion</h2>
    <ol>
        <li><strong>From your Meta / Facebook Account:</strong>
            <p>Go to your Facebook Account Settings &amp; Privacy &rarr; Settings &rarr; Apps and Websites. Locate <strong><?= esc($appName) ?></strong> and click <strong>Remove</strong>. You can then click "View Details" to submit an automatic data deletion request.</p>
        </li>
        <li><strong>From the Workspace Dashboard:</strong>
            <p>Account administrators can delete contacts, chat conversations, and marketing campaigns directly from their respective tabs (Contacts &rarr; Delete, Chat &rarr; Close/Delete).</p>
        </li>
        <li><strong>Manual Request via Email:</strong>
            <p>Send an email with the subject <em>"Data Deletion Request"</em> along with your registered business email and phone number to your platform support administrator. Requests are acknowledged and permanently purged within 30 days.</p>
        </li>
    </ol>

    <h2>What Data is Deleted?</h2>
    <p>Upon verification of your request, we permanently remove your user profile, stored contact numbers, message content, and conversation history from our active databases and encrypted backups.</p>

    <a href="<?= site_url() ?>" class="back-link">&larr; Return to <?= esc($appName) ?></a>
</div>
</body>
</html>
