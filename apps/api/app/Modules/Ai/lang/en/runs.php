<?php

return [
    'connected' => 'The :provider key works. :count models available.',
    'failures' => [
        'invalid_key' => ':provider rejected the key. Check it was copied in full and has not been revoked.',
        'permission_denied' => 'The :provider key cannot list models. Check the key or project permissions.',
        'rate_limited' => ':provider is temporarily limiting requests. Try again in a minute.',
        'provider_error' => ':provider returned an error. The service may be unavailable right now.',
        'unreachable' => 'Cannot reach :provider. Check the network connection.',
    ],
];
