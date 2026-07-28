<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>404 — Page Not Found</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;600&family=Sora:wght@600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.5.2/css/all.min.css">
    <style>
        body {
            min-height: 100vh;
            margin: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'DM Sans', system-ui, sans-serif;
            color: #fff;
            background:
                radial-gradient(800px 500px at 20% 10%, rgba(37, 211, 102, 0.35), transparent 60%),
                radial-gradient(600px 400px at 90% 80%, rgba(18, 140, 126, 0.45), transparent 55%),
                linear-gradient(160deg, #042f2a 0%, #075E54 55%, #0a6b5c 100%);
        }
        .box {
            text-align: center;
            background: rgba(255,255,255,.1);
            backdrop-filter: blur(12px);
            border: 1px solid rgba(255,255,255,.15);
            border-radius: 24px;
            padding: 3rem 2rem;
            max-width: 440px;
            margin: 1rem;
        }
        .mark {
            width: 64px;
            height: 64px;
            margin: 0 auto 1.25rem;
            border-radius: 20px;
            background: linear-gradient(145deg, #2be072, #128C7E);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.85rem;
            box-shadow: 0 10px 28px rgba(0,0,0,.25);
        }
        h1 {
            font-family: 'Sora', system-ui, sans-serif;
            font-size: 4.5rem;
            font-weight: 700;
            margin: 0;
            line-height: 1;
            letter-spacing: -0.04em;
        }
        .lead {
            font-family: 'Sora', system-ui, sans-serif;
            font-weight: 600;
            font-size: 1.15rem;
            margin: 0.75rem 0 0.35rem;
        }
        .msg { opacity: 0.7; font-size: 0.95rem; margin: 0; }
        .btn-home {
            background: linear-gradient(180deg, #2be072, #25D366);
            border: 0;
            color: #042f2a;
            padding: .7rem 1.5rem;
            border-radius: 999px;
            text-decoration: none;
            display: inline-block;
            margin-top: 1.5rem;
            font-weight: 600;
            box-shadow: 0 6px 18px rgba(37, 211, 102, 0.35);
        }
        .btn-home:hover { filter: brightness(1.05); color: #042f2a; }
    </style>
</head>
<body>
    <div class="box">
        <div class="mark"><i class="fab fa-whatsapp"></i></div>
        <h1>404</h1>
        <p class="lead">Page not found</p>
        <p class="msg">
            <?php if (ENVIRONMENT !== 'production') : ?>
                <?= nl2br(esc($message ?? 'The page you requested was not found.')) ?>
            <?php else : ?>
                The page you requested could not be found.
            <?php endif; ?>
        </p>
        <a class="btn-home" href="<?= site_url('/') ?>">Go home</a>
    </div>
</body>
</html>
