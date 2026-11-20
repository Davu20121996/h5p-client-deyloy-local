<?php

return [
    'proxies' => array_filter(array_map('trim', explode(',', env('TRUSTED_PROXIES', '127.0.0.1,::1,172.16.0.0/12')))),
];
