<?php

namespace Uptimistic\Laravel\Http;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Lets through only requests Uptimistic signed with the report secret:
 *
 *   X-Uptimistic-Timestamp: unix time
 *   X-Uptimistic-Signature: sha256=HMAC-SHA256(secret, "{timestamp}.{path and query}")
 *
 * A request older or newer than the tolerance, or one already seen, is
 * refused as well. Every refusal is a plain 404, so the route does not
 * give itself away.
 */
class VerifySignature
{
    public const MIN_SECRET_LENGTH = 32;

    /**
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        $secret = (string) config('uptimistic.secret');
        $timestamp = (string) $request->header('X-Uptimistic-Timestamp', '');
        $signature = (string) $request->header('X-Uptimistic-Signature', '');
        $tolerance = (int) config('uptimistic.tolerance', 300);

        if (strlen($secret) < self::MIN_SECRET_LENGTH
            || preg_match('/^\d{1,12}$/', $timestamp) !== 1
            || abs(time() - (int) $timestamp) > $tolerance) {
            abort(404);
        }

        $expected = 'sha256='.hash_hmac('sha256', $timestamp.'.'.$request->getRequestUri(), $secret);

        if (! hash_equals($expected, $signature) || ! $this->firstUse($signature, $tolerance)) {
            abort(404);
        }

        return $next($request);
    }

    /**
     * Whether this signature is new. When the cache cannot say, the
     * signature and the time limit still hold.
     */
    private function firstUse(string $signature, int $tolerance): bool
    {
        try {
            return Cache::add('uptimistic-report:'.sha1($signature), true, $tolerance * 2 + 60);
        } catch (Throwable $e) {
            return true;
        }
    }
}
