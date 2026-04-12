<?php

use App\Support\Env;

/**
 * WebRTC ICE Server Configuration.
 *
 * ── Development (default) ──────────────────────────────────
 * Only public STUN servers. Sufficient for peers on the same
 * network or when both sides have open NAT.
 *
 * ── Production ─────────────────────────────────────────────
 * Must include at least one TURN server for reliable connectivity
 * behind symmetric NAT, corporate firewalls, or mobile carriers.
 *
 * Set these env vars:
 *   TURN_SERVER_URL=turn:turn.example.com:3478
 *   TURN_SECRET=<shared-secret-with-turn-server>
 *   TURN_CREDENTIAL_TTL=86400          (optional, default 24 h)
 *   TURN_TRANSPORT=udp                 (optional, udp|tcp|tls)
 *
 * For coturn with --use-auth-secret (TURN REST API / RFC draft):
 *   username = <expiry-timestamp>:<user-id>
 *   credential = HMAC-SHA1(username, secret)
 *   → Time-limited, per-user, no long-lived passwords.
 */
return [

    /*
    |--------------------------------------------------------------------------
    | STUN Servers
    |--------------------------------------------------------------------------
    | Public STUN servers used in all environments.
    | These only help with NAT discovery — no media relay.
    */
    'stun' => array_filter([
        Env::get('STUN_SERVER_1', 'stun:stun.l.google.com:19302'),
        Env::get('STUN_SERVER_2', 'stun:stun1.l.google.com:19302'),
    ]),

    /*
    |--------------------------------------------------------------------------
    | TURN Server
    |--------------------------------------------------------------------------
    | Required for production. Leave TURN_SERVER_URL empty to disable.
    |
    | Security: Credentials are generated per-user with HMAC-SHA1
    | and expire after TURN_CREDENTIAL_TTL seconds. The shared secret
    | never leaves the backend.
    */
    'turn' => [
        'url' => Env::get('TURN_SERVER_URL', ''),
        'secret' => Env::get('TURN_SECRET', ''),
        'credential_ttl' => Env::int('TURN_CREDENTIAL_TTL', 3600), // 1 hour (was 24h — reduced attack window)
        'transport' => Env::get('TURN_TRANSPORT', 'udp'),
    ],

    /*
    |--------------------------------------------------------------------------
    | ICE Transport Policy
    |--------------------------------------------------------------------------
    | 'all'   – Use both STUN (srflx) and TURN (relay) candidates.
    | 'relay' – Force all traffic through TURN (max. privacy, higher latency).
    */
    'ice_transport_policy' => Env::get('ICE_TRANSPORT_POLICY', 'all'),
];
