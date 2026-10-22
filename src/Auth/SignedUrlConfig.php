<?php

declare(strict_types=1);

namespace Keel\Auth;

/**
 * Register one in config/signed_url.php for UrlSigner and #[Signed].
 */
final readonly class SignedUrlConfig
{
    /**
     * @param string      $secret  signs the links: long, random and out of the repository (APP_SECRET);
     *                             changing it invalidates every link handed out
     * @param int|null    $ttl     seconds a link stays valid unless sign()/route() set an expiry; null = forever
     * @param string|null $baseUrl put in front of the signed path ("https://app.example.com") for links that
     *                             leave the application, as in emails. Not signed: the signature covers the path
     *                             and query the application receives, so a proxy that mounts the application
     *                             under a prefix keeps links valid (put the prefix here)
     * @param string      $purpose keeps these signatures apart from cookies signed with the same secret
     */
    public function __construct(
        public string $secret,
        public ?int $ttl = null,
        public ?string $baseUrl = null,
        public string $purpose = 'signed-url',
    ) {
        if (strlen($secret) < 32) {
            throw new \InvalidArgumentException('Signed URL secret must be at least 32 characters');
        }
        if ($ttl !== null && $ttl < 1) {
            throw new \InvalidArgumentException(sprintf('Signed URL ttl must be at least 1 second, got %d', $ttl));
        }
        if ($baseUrl !== null && preg_match('~^https?://[^/?#]+(/[^?#]*)?$~iD', $baseUrl) !== 1) {
            throw new \InvalidArgumentException(sprintf('Signed URL baseUrl must be an http(s) URL without query or fragment, got "%s"', $baseUrl));
        }
    }
}
