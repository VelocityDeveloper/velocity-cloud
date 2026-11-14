<?php

use App\Mcp\Servers\VelocityCloud;
use Laravel\Mcp\Facades\Mcp;

// stdio lokal saja (php84 artisan mcp:start velocity-cloud) untuk bot di PC ini; tidak dibuka lewat web.
Mcp::local('velocity-cloud', VelocityCloud::class);
