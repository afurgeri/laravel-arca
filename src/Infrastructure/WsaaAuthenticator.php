<?php

namespace Modules\Arca\Infrastructure;

use Illuminate\Cache\CacheManager;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Modules\Arca\Contracts\ArcaAuthorizer;
use Modules\Arca\Data\ArcaCredentials;
use Modules\Arca\Exceptions\ArcaException;
use Throwable;

class WsaaAuthenticator implements ArcaAuthorizer
{
    public function __construct(
        private readonly SoapTransport $transport,
        private readonly CacheManager $cache,
        private readonly string $endpoint,
        private readonly ?string $cacheStore = null,
        private readonly int $safetySeconds = 300,
    ) {}

    /** @return array{Token: string, Sign: string} */
    public function authorization(ArcaCredentials $credentials): array
    {
        $certificate = $credentials->certificate;
        $privateKey = $credentials->privateKey;

        if ($certificate === null || $privateKey === null) {
            throw new ArcaException('A certificate and private key are required for direct ARCA SOAP access.');
        }

        $cache = $this->cache->store($this->cacheStore);
        $lockProvider = $cache->getStore();

        if (! $lockProvider instanceof LockProvider) {
            throw new ArcaException('The configured ARCA ticket cache does not support atomic locks.');
        }

        $cacheKey = 'arca:wsaa:'.hash('sha256', implode('|', [
            $credentials->environment(),
            $credentials->cuit,
            hash('sha256', $certificate),
            'wsfe',
        ]));
        $cached = $this->decryptTicket($cache->get($cacheKey));

        if ($this->isUsableTicket($cached)) {
            return ['Token' => $cached['token'], 'Sign' => $cached['sign']];
        }

        try {
            return $lockProvider->lock($cacheKey.':lock', 30)->block(10, function () use ($cache, $cacheKey, $certificate, $privateKey): array {
                $cached = $this->decryptTicket($cache->get($cacheKey));

                if ($this->isUsableTicket($cached)) {
                    return ['Token' => $cached['token'], 'Sign' => $cached['sign']];
                }

                $ticket = $this->requestTicket($certificate, $privateKey);
                $expiresAt = Carbon::parse($ticket['expirationTime']);
                $ttl = max(1, $expiresAt->getTimestamp() - now()->getTimestamp() - $this->safetySeconds);
                $cache->put($cacheKey, Crypt::encryptString(json_encode([
                    'token' => $ticket['token'],
                    'sign' => $ticket['sign'],
                    'expiration' => $expiresAt->toIso8601String(),
                ], JSON_THROW_ON_ERROR)), $ttl);

                return ['Token' => $ticket['token'], 'Sign' => $ticket['sign']];
            });
        } catch (ArcaException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new ArcaException('ARCA WSAA authentication failed ('.class_basename($exception).'). No credential details were retained.');
        }
    }

    /** @param array<string, mixed>|null $ticket */
    private function isUsableTicket(?array $ticket): bool
    {
        return isset($ticket['token'], $ticket['sign'], $ticket['expiration'])
            && strtotime((string) $ticket['expiration']) > now()->getTimestamp() + $this->safetySeconds;
    }

    /** @return array<string, mixed>|null */
    private function decryptTicket(mixed $value): ?array
    {
        if (! is_string($value)) {
            return null;
        }

        try {
            $ticket = json_decode(Crypt::decryptString($value), true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        return is_array($ticket) ? $ticket : null;
    }

    /** @return array{token: string, sign: string, expirationTime: string} */
    private function requestTicket(string $certificate, string $privateKey): array
    {
        $now = now();
        $tra = '<?xml version="1.0" encoding="UTF-8"?>'
            .'<loginTicketRequest version="1.0"><header><uniqueId>'.$now->timestamp.'</uniqueId>'
            .'<generationTime>'.$now->copy()->subMinutes(10)->toIso8601String().'</generationTime>'
            .'<expirationTime>'.$now->copy()->addMinutes(10)->toIso8601String().'</expirationTime>'
            .'</header><service>wsfe</service></loginTicketRequest>';

        $cms = $this->sign($tra, $certificate, $privateKey);
        $response = $this->transport->call(
            $this->endpoint,
            'http://wsaa.view.sua.dvadac.desein.afip.gov',
            'loginCms',
            ['in0' => $cms],
        );
        $rawTicket = $response['loginCmsReturn'] ?? null;

        if (! is_string($rawTicket) || $rawTicket === '') {
            throw new ArcaException('ARCA WSAA returned an empty access ticket.');
        }

        $document = new \DOMDocument;

        if (! $document->loadXML($rawTicket, LIBXML_NONET | LIBXML_NOBLANKS)) {
            throw new ArcaException('ARCA WSAA returned an invalid access ticket.');
        }

        $token = $document->getElementsByTagName('token')->item(0)?->textContent;
        $sign = $document->getElementsByTagName('sign')->item(0)?->textContent;
        $expiration = $document->getElementsByTagName('expirationTime')->item(0)?->textContent;

        if (! is_string($token) || $token === '' || ! is_string($sign) || $sign === '' || ! is_string($expiration)) {
            throw new ArcaException('ARCA WSAA returned an incomplete access ticket.');
        }

        return ['token' => $token, 'sign' => $sign, 'expirationTime' => $expiration];
    }

    private function sign(string $tra, string $certificate, string $privateKey): string
    {
        $input = tempnam(sys_get_temp_dir(), 'arca-tra-');
        $output = tempnam(sys_get_temp_dir(), 'arca-cms-');

        if ($input === false || $output === false) {
            throw new ArcaException('Could not prepare the ARCA authentication request.');
        }

        chmod($input, 0600);
        chmod($output, 0600);

        try {
            $certificateResource = openssl_x509_read($certificate);

            if (file_put_contents($input, $tra) === false
                || $certificateResource === false
                || ! openssl_pkcs7_sign($input, $output, $certificateResource, $privateKey, [], 0)) {
                throw new ArcaException('Could not sign the ARCA authentication request.');
            }

            $signed = file_get_contents($output);

            if ($signed === false || ! preg_match('/\r?\n\r?\n(.*)\z/s', $signed, $matches)) {
                throw new ArcaException('Could not encode the ARCA authentication signature.');
            }

            return trim(preg_replace('/\s+/', '', $matches[1]) ?? '');
        } finally {
            @unlink($input);
            @unlink($output);
        }
    }
}
