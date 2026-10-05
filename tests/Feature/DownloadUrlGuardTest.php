<?php

namespace Tests\Feature;

use App\Contracts\ProviderException;
use App\Enums\ProviderFailureReason;
use App\Services\Provider\DownloadUrlGuard;
use Tests\TestCase;

/**
 * The SSRF guard on provider-supplied download URLs.
 *
 * Generated assets do not live on the API we authenticated to — a Kling result
 * points at v3b.fal.media — so the download target is a string out of a response
 * body, and fetching an arbitrary string from inside the application is
 * server-side request forgery regardless of how much the provider is trusted.
 *
 * Each refusal below names something a server can actually lose: instance
 * metadata hands out credentials on a VPS, and localhost is where the queue and
 * the database are listening.
 *
 * Resolvers are injected here rather than mocked at the network layer, so these
 * assertions are about the decision and never about DNS.
 */
class DownloadUrlGuardTest extends TestCase
{
    protected function guard(string ...$addresses): DownloadUrlGuard
    {
        return new DownloadUrlGuard(fn (string $host) => $addresses);
    }

    protected function assertRefused(string $url, DownloadUrlGuard $guard, string $expectIn): void
    {
        try {
            $guard->assertSafe($url);
            $this->fail("Expected {$url} to be refused.");
        } catch (ProviderException $e) {
            $this->assertStringContainsString($expectIn, $e->getMessage());

            // Permanent: the same URL is just as unsafe next time, and retrying
            // an SSRF attempt is simply the attack again.
            $this->assertFalse($e->retryable);
            $this->assertSame(ProviderFailureReason::InvalidRequest, $e->reason);
        }
    }

    public function test_a_public_https_url_is_allowed(): void
    {
        $this->guard('93.184.216.34')->assertSafe('https://v3b.fal.media/files/b/0aacfce3/clip.mp4');

        $this->addToAssertionCount(1);
    }

    public function test_plaintext_http_is_refused(): void
    {
        // The asset is the product. Over http it is readable and replaceable in
        // transit, and FalResponseMapper accepts anything starting "http", so
        // this is the only thing standing between a typo'd scheme and a
        // plaintext fetch.
        $this->assertRefused(
            'http://v3b.fal.media/files/clip.mp4',
            $this->guard('93.184.216.34'),
            'must be fetched over https',
        );
    }

    public function test_loopback_is_refused(): void
    {
        // Where the queue, the database and the dev server are listening.
        $this->assertRefused(
            'https://assets.example.com/clip.mp4',
            $this->guard('127.0.0.1'),
            'private or reserved',
        );
    }

    public function test_cloud_instance_metadata_is_refused(): void
    {
        // 169.254.169.254 is the one that turns SSRF into credential theft on a
        // VPS. Caught by NO_RES_RANGE, the same rule as loopback.
        $this->assertRefused(
            'https://assets.example.com/clip.mp4',
            $this->guard('169.254.169.254'),
            'private or reserved',
        );
    }

    public function test_a_private_network_neighbour_is_refused(): void
    {
        $this->assertRefused(
            'https://assets.example.com/clip.mp4',
            $this->guard('10.0.0.7'),
            'private or reserved',
        );
    }

    public function test_ipv6_loopback_is_refused(): void
    {
        $this->assertRefused(
            'https://assets.example.com/clip.mp4',
            $this->guard('::1'),
            'private or reserved',
        );
    }

    public function test_a_bare_ipv6_literal_is_checked_without_a_lookup(): void
    {
        // The attack can be spelled without a hostname, so a literal must be
        // checked too — and bracketed, which is how a URL carries IPv6.
        $this->assertRefused(
            'https://[::1]/clip.mp4',
            new DownloadUrlGuard(fn (string $host) => ['93.184.216.34']),
            'private or reserved',
        );
    }

    public function test_a_bare_ipv4_literal_is_checked_without_a_lookup(): void
    {
        $this->assertRefused(
            'https://127.0.0.1/clip.mp4',
            new DownloadUrlGuard(fn (string $host) => ['93.184.216.34']),
            'private or reserved',
        );
    }

    public function test_one_private_address_among_several_is_enough_to_refuse(): void
    {
        // A host that answers with both a public and a private address is the
        // interesting case: curl may pick either, so allowing it would make the
        // outcome a coin toss.
        $this->assertRefused(
            'https://assets.example.com/clip.mp4',
            $this->guard('93.184.216.34', '192.168.1.10'),
            'private or reserved',
        );
    }

    public function test_something_that_is_not_a_url_is_refused(): void
    {
        $this->assertRefused('not a url', $this->guard('93.184.216.34'), 'not an absolute URL');
    }

    public function test_a_host_that_will_not_resolve_fails_open(): void
    {
        // Deliberate. If this cannot resolve the name, curl will not reach it
        // either, so refusing adds no safety — while failing closed would break
        // every download on a transient DNS blip. The case that matters is a
        // name that resolves TO a private address, and that resolves fine.
        $this->guard()->assertSafe('https://nothing-resolves-here.invalid/clip.mp4');

        $this->addToAssertionCount(1);
    }

    public function test_the_ip_check_can_be_switched_off_for_proxied_setups(): void
    {
        // Documented escape hatch: where assets legitimately come from a proxy
        // on your own network, a private address is the correct answer.
        config(['studio.fal.verify_host_ip' => false]);

        $this->guard('127.0.0.1')->assertSafe('https://assets.example.com/clip.mp4');

        $this->addToAssertionCount(1);
    }

    public function test_switching_off_the_ip_check_does_not_allow_plaintext(): void
    {
        // The two controls are independent. Turning off host verification for a
        // proxy must not quietly also permit http.
        config(['studio.fal.verify_host_ip' => false]);

        $this->assertRefused(
            'http://assets.example.com/clip.mp4',
            $this->guard('127.0.0.1'),
            'must be fetched over https',
        );
    }
}
