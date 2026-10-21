<?php

declare(strict_types=1);

use Keel\Auth\AuthConfig;
use Keel\Auth\BearerTokenAuthenticator;

return new AuthConfig([BearerTokenAuthenticator::class]);
