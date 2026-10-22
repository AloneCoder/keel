<?php

declare(strict_types=1);

namespace Keel\Tests\Mail\Fixtures;

use Keel\Mail\Mailer;
use Keel\Mail\MemoryTransport;
use Keel\Routing\Attribute\Post;
use Symfony\Component\Mime\Email;

final class SignUpController
{
    public function __construct(private readonly Mailer $mailer, private readonly MemoryTransport $transport) {}

    /**
     * @return array{sent_before_response: int}
     */
    #[Post('/register')]
    public function register(): array
    {
        $this->mailer->defer(new Email()->to('ann@example.com')->subject('Welcome')->text('Hello Ann'));

        return ['sent_before_response' => count($this->transport->sent())];
    }
}
