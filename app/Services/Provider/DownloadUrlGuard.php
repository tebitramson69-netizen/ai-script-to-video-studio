<?php

namespace App\Services\Provider;

use App\Contracts\ProviderException;
use App\Enums\ProviderFailureReason;
use Closure;

/**
 * Decides whether a URL a provider handed us is safe to fetch server-side.
 *
 * Generated assets do not live on the API we authenticated to. A Kling result
 * points at v3b.fal.media; other fal responses have used cdn.fal.media. So the
 * download target is a string from a response body, and fetching an arbitrary
 * string from inside the application is server-side request forgery whether or
 * not the provider is trustworthy today.
 *
 * What this blocks, concretely: a URL — or a redirect — aimed at
 * http://169.254.169.254/ (cloud instance metadata, which on a VPS hands out
 * credentials), at 127.0.0.1 (anything bound to localhost, including the queue
 * and the database), or at a 10.x neighbour on the same private network.
 *
 * Two deliberate non-decisions:
 *
 *   1. No host allowlist. fal serves assets from several domains and adding more
 *      costs them nothing; a list that is wrong fails a render that has already
 *      been billed. Rejecting private and reserved destinations blocks the
 *      attack without claiming to know their CDN estate.
 *
 *   2. A host that will not resolve fails OPEN. If this cannot resolve it, curl
 *      will not reach it either, so refusing adds no safety while making the
 *      pipeline fail on a transient DNS blip. The case that matters — a name
 *      that resolves TO a private address — resolves fine and is refused.
 *
 * Known limitation: this resolves, then curl resolves again. DNS rebinding can
 * move the answer in between. Closing that needs the connection pinned to the
 * address checked here (CURLOPT_RESOLVE), which this does not attempt. The
 * https requirement and the redirect protocol restriction in FalClient are not
 * bypassable that way.
 */
class DownloadUrlGuard
{
    /**
     * @param  (Closure(string): list<string>)|null  $resolver  Host to IPs. Injected so
     *                                                          the behaviour can be tested without DNS, and so a
     *                                                          suite that downloads from cdn.fal.media does not
     *                                                          depend on the network.
     */
    public function __construct(
        protected ?Closure $resolver = null,
    ) {}

    /**
     * @throws ProviderException when the URL must not be fetched
     */
    public function assertSafe(string $url, string $provider = 'fal'): void
    {
        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'], $parts['host']) || $parts['host'] === '') {
            $this->refuse("'{$url}' is not an absolute URL.", $provider);
        }

        if (strtolower($parts['scheme']) !== 'https') {
            // Not pedantry: the asset is the product, and a plaintext fetch is
            // both readable and replaceable in transit.
            $this->refuse(
                "Refusing to download over {$parts['scheme']}: provider assets must be fetched over https.",
                $provider,
            );
        }

        if (! (bool) config('studio.fal.verify_host_ip', true)) {
            return;
        }

        foreach ($this->addressesFor($parts['host']) as $address) {
            if (! $this->isPublic($address)) {
                $this->refuse(
                    sprintf(
                        'Refusing to download from %s: it resolves to %s, which is a private or reserved address.',
                        $parts['host'],
                        $address,
                    ),
                    $provider,
                );
            }
        }
    }

    /**
     * @return list<string>
     */
    protected function addressesFor(string $host): array
    {
        // A literal address needs no lookup, and must still be checked — the
        // whole attack can be spelled without a hostname.
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        // An IPv6 literal arrives bracketed: [::1].
        $unbracketed = trim($host, '[]');

        if (filter_var($unbracketed, FILTER_VALIDATE_IP) !== false) {
            return [$unbracketed];
        }

        return $this->resolver !== null
            ? ($this->resolver)($host)
            : $this->resolve($host);
    }

    /**
     * @return list<string>
     */
    protected function resolve(string $host): array
    {
        $addresses = [];

        $v4 = gethostbynamel($host);

        if (is_array($v4)) {
            $addresses = $v4;
        }

        // dns_get_record is quiet about failures by design here: an empty answer
        // means "nothing to check", which fails open as documented above.
        $v6 = @dns_get_record($host, DNS_AAAA);

        if (is_array($v6)) {
            foreach ($v6 as $record) {
                if (isset($record['ipv6']) && is_string($record['ipv6'])) {
                    $addresses[] = $record['ipv6'];
                }
            }
        }

        return array_values(array_unique($addresses));
    }

    /**
     * Covers IPv4 and IPv6 in one call, and needs no CIDR list to be kept in
     * step with IANA. NO_RES_RANGE is what rejects 169.254.0.0/16, so instance
     * metadata is out by the same rule as loopback.
     */
    protected function isPublic(string $address): bool
    {
        return filter_var(
            $address,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) !== false;
    }

    /**
     * @throws ProviderException
     */
    protected function refuse(string $message, string $provider): never
    {
        // Permanent: the same URL will be just as unsafe on a retry, and a
        // retried SSRF attempt is simply the attack again.
        throw ProviderException::because(
            ProviderFailureReason::InvalidRequest,
            $message,
            $provider,
        );
    }
}
