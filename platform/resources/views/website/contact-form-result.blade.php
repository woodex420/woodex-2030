<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="no-referrer">
    <meta name="robots" content="noindex, nofollow, nosnippet">
    <title>{{ $heading }} — Woodex Interior</title>
    <style>
        *{box-sizing:border-box}body{margin:0;padding:32px 20px;background:#f8f7f4;color:#242824;font:18px/1.6 system-ui,sans-serif}main{max-width:640px;margin:10vh auto;padding:32px;background:#fff;border:1px solid #deded7;border-radius:12px}h1{font-size:clamp(1.6rem,6vw,2rem);line-height:1.25;margin:0 0 24px}p{margin:16px 0}a{display:inline-block;color:#175438;font-weight:700;text-underline-offset:4px;padding:8px 0}a:focus-visible{outline:3px solid #175438;outline-offset:4px}li{overflow-wrap:anywhere}@media(max-width:480px){main{padding:24px;margin:24px auto}}
    </style>
</head>
<body>
<main aria-labelledby="result-heading">
    <h1 id="result-heading">{{ $heading }}</h1>
    <p>{{ $message }}</p>
    @if ($fields !== [])
        <p>Please check:</p>
        <ul>@foreach ($fields as $field)<li>{{ $field }}</li>@endforeach</ul>
    @endif
    <a href="/contact/#visit-form">Return to Contact</a>
</main>
</body>
</html>
