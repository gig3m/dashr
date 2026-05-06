<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= htmlspecialchars($title ?? 'southside.cc') ?></title>
  <style>
    :root { color-scheme: light dark; }
    body { font-family: system-ui, -apple-system, sans-serif; max-width: 32rem;
           margin: 4rem auto; padding: 0 1rem; line-height: 1.5; }
    h1 { font-size: 1.5rem; }
    input, button { font: inherit; padding: 0.5rem 0.75rem; border-radius: 0.375rem;
                    border: 1px solid #888; }
    input[type=text], input[type=url], input[type=password] { width: 100%; box-sizing: border-box; }
    button { cursor: pointer; }
    .row { display: flex; gap: 0.5rem; margin: 0.5rem 0; }
    .err { color: #c0392b; }
    table { border-collapse: collapse; width: 100%; }
    th, td { text-align: left; padding: 0.4rem 0.5rem; border-bottom: 1px solid #ccc; }
    code { font-family: ui-monospace, monospace; }
    .muted { color: #888; font-size: 0.9rem; }
  </style>
</head>
<body>
  <?= $content ?>
</body>
</html>
