<?php

/*
| Proxies whose X-Forwarded-* headers are trusted (read by Illuminate\Http\Middleware\TrustProxies).
| TRUSTED_PROXIES: comma separated IPs/CIDRs, or * to trust every proxy (only if the API is unreachable except via that proxy).
| Default 127.0.0.1: a same-host reverse proxy. On shared hosting where PHP sees the real client address, leave the default.
*/
$proxies = trim((string) env('TRUSTED_PROXIES', '127.0.0.1'));

return [
    'proxies' => $proxies === '*' ? '*' : array_values(array_filter(array_map('trim', explode(',', $proxies)))),
];
