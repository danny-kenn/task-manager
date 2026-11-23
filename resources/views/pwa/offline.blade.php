<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#0d1117">
<title>Offline · {{ config('app.name') }}</title>
<style>
  *{box-sizing:border-box}body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px;font-family:system-ui,-apple-system,'Segoe UI',Roboto,sans-serif;background:#0d1117;color:#e6edf3;text-align:center}
  .box{max-width:340px}.ic{width:84px;height:84px;margin:0 auto 18px;border-radius:24px;background:#2c7a7b;display:flex;align-items:center;justify-content:center;font-size:38px}
  h1{font-size:1.3rem;margin:0 0 8px}p{color:#9aa5b1;line-height:1.5;margin:0 0 20px}
  button{background:#2c7a7b;color:#fff;border:0;border-radius:12px;padding:12px 22px;font-size:1rem;font-weight:600;cursor:pointer}
</style>
</head>
<body>
<div class="box"><div class="ic">📡</div><h1>You are offline</h1><p>{{ config('app.name') }} needs a connection to load your tasks. Check your network and try again.</p><button onclick="location.reload()">Try again</button></div>
<script>addEventListener('online',function(){location.reload()})</script>
</body>
</html>
