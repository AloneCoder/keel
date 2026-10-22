<?php

declare(strict_types=1);

namespace Keel\HttpClient;

enum CircuitState: string
{
    /** Calls go through; failures are counted. */
    case Closed = 'closed';

    /** Calls are refused until the open period ends. */
    case Open = 'open';

    /** The open period is over: the next call probes the host and decides. */
    case HalfOpen = 'half-open';
}
