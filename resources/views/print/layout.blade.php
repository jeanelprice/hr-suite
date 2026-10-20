<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>@yield('title') — HR Suite</title>
<style>
  :root { --navy: #1F3864; --ink: #1b1b1b; --muted: #5b6270; --line: #c9ced8; }
  * { box-sizing: border-box; }
  body { margin: 0; font-family: "Aptos Narrow", "Arial Narrow", Arial, sans-serif; color: var(--ink); background: #eef0f4; }
  .toolbar { position: sticky; top: 0; display: flex; gap: 12px; align-items: center; padding: 10px 16px; background: var(--navy); color: #fff; font-size: 14px; }
  .toolbar button { background: #fff; color: var(--navy); border: 0; border-radius: 6px; padding: 7px 14px; font-weight: 600; cursor: pointer; }
  .toolbar a { color: #fff; }
  .sheet { background: #fff; margin: 20px auto; box-shadow: 0 1px 4px rgba(0,0,0,.15); }
  @media print {
    body { background: #fff; }
    .toolbar { display: none; }
    .sheet { margin: 0; box-shadow: none; }
  }
  @yield('style')
</style>
</head>
<body>
<div class="toolbar">
  <button type="button" onclick="window.print()">Imprimer / Enregistrer en PDF</button>
  <a href="javascript:history.back()">Retour</a>
  <span>@yield('hint')</span>
</div>
@yield('content')
</body>
</html>
