<?php

use App\Models\Domain;

if (!function_exists('currentDomain')) {
    function currentDomain(): ?Domain
    {
        $host = request()->getHost();

        return cache()->remember(
            "domain:$host",
            300,
            fn () => Domain::where('host', $host)->first()
        );
    }
}
