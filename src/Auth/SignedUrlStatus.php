<?php

declare(strict_types=1);

namespace Keel\Auth;

enum SignedUrlStatus
{
    case Valid;

    /** No signature parameter. */
    case Missing;

    /** The signature does not match the path and query: forged or altered. */
    case Invalid;

    /** Genuine, but past its expiry. */
    case Expired;
}
