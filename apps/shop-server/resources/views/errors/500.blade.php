<!doctype html>
<html lang="en-GB">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
{{-- A page load the server could not answer (finding LG5). It names the request's reference, which HAQ support
     finds in the log, and opens the page again by itself after 15 seconds, as the counter's own crash screen
     does (finding LG3). No assets and no script: it must show even when the app cannot build its page. --}}
<meta http-equiv="refresh" content="15">
<title>Something went wrong</title>
<style>
:root { color-scheme: light; }
body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; background: #ffffff; color: #000000; font-family: Inter, "Segoe UI", system-ui, sans-serif; }
main { box-sizing: border-box; max-width: 36rem; padding: 32px 16px; display: flex; flex-direction: column; gap: 16px; }
p { margin: 0; font-size: 20px; line-height: 1.45; }
h1 { margin: 0; font-size: 32px; font-weight: 700; }
.brand { font-size: 18px; font-weight: 700; }
.note { font-size: 18px; color: #6c6c70; }
.reload { align-self: flex-start; min-height: 56px; padding: 0 28px; display: inline-flex; align-items: center; border-radius: 12px; background: #007aff; color: #ffffff; font-size: 20px; font-weight: 700; text-decoration: none; }
</style>
</head>
<body>
<main>
  <span class="brand">HAQ Retail OS</span>
  <h1 role="alert">Something went wrong</h1>
  <p>The server had a problem with this page. It opens again by itself in a few seconds. A sale that was open on a counter is kept.</p>
  <a class="reload" href="">Reload</a>
  @php($reference = \App\Platform\Http\RequestContext::reference())
  @if ($reference !== null)
    <p class="note">If it happens again, tell HAQ support the reference <strong>{{ $reference }}</strong>.</p>
  @endif
</main>
</body>
</html>
