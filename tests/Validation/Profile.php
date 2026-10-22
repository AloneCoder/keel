<?php

declare(strict_types=1);

namespace Keel\Tests\Validation;

use Keel\Validation\Alnum;
use Keel\Validation\Count;
use Keel\Validation\Date;
use Keel\Validation\Each;
use Keel\Validation\Hostname;
use Keel\Validation\Ip;
use Keel\Validation\Json;
use Keel\Validation\Length;
use Keel\Validation\NotBlank;
use Keel\Validation\Timezone;
use Keel\Validation\Unique;
use Keel\Validation\Uuid;

final readonly class Profile
{
    /**
     * @param list<string> $tags
     * @param list<string> $servers
     */
    public function __construct(
        #[Uuid]
        public string $id,
        #[Alnum('-_')]
        public string $handle,
        #[Date]
        public string $born,
        #[Date(DATE_ATOM)]
        public ?string $seenAt = null,
        #[Ip]
        public ?string $ip = null,
        #[Ip(version: 4)]
        public ?string $ip4 = null,
        #[Hostname]
        public ?string $host = null,
        #[Json]
        public ?string $settings = null,
        #[Timezone]
        public string $tz = 'UTC',
        #[Count(max: 5), Unique, Each(new NotBlank(), new Length(max: 10))]
        public array $tags = [],
        #[Each(new Hostname(requireTld: false))]
        public array $servers = [],
    ) {}
}
