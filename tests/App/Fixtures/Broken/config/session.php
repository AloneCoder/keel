<?php

declare(strict_types=1);

use Keel\Session\SessionConfig;

return new SessionConfig(str_repeat('s', 32), storage: 'file');
