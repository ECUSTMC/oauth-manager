<?php

namespace OAuthRecord\Controllers;

use App\Models\User;
use Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Passport\Client;
use Laravel\Passport\Token;

class AppHallController
{
    public function index()
    {
        $user = Auth::user();

        // Get all active OAuth clients (non-personal, non-revoked)
        $clients = Client::where('personal_access_client', false)
            ->where('revoked', false)
            ->get();

        // Get client IDs the current user has already authorized
        $authorizedClientIds = Token::where('user_id', $user->uid)
            ->where('revoked', false)
            ->where(function ($query) {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->pluck('client_id')
            ->unique()
            ->toArray();

        // Preload creators
        $creatorIds = $clients->pluck('user_id')->filter()->unique()->toArray();
        $creators = User::whereIn('uid', $creatorIds)->get()->keyBy('uid');

        $apps = $clients->map(function ($client) use ($authorizedClientIds, $creators) {
            $creator = $client->user_id ? $creators->get($client->user_id) : null;

            return [
                'id' => $client->id,
                'name' => $client->name,
                'domain' => $this->extractDomain($client->redirect),
                'url' => $this->extractUrl($client->redirect),
                'authorized' => in_array($client->id, $authorizedClientIds),
                'creator' => $creator ? $creator->nickname : null,
                'user_count' => Token::where('client_id', $client->id)
                    ->where('revoked', false)
                    ->distinct('user_id')
                    ->count('user_id'),
            ];
        })->values()->toArray();

        return view('OAuthRecord::hall', ['apps' => $apps]);
    }

    /**
     * API endpoint: fetch favicon URL for a given site URL.
     * Returns JSON: { "favicon": "https://..." } or { "favicon": null }
     */
    public function favicon()
    {
        $url = request()->query('url');

        if (!$url || !filter_var($url, FILTER_VALIDATE_URL)) {
            return response()->json(['favicon' => null]);
        }

        return response()->json(['favicon' => $this->fetchFavicon($url)]);
    }

    /**
     * Fetch the favicon URL from a given page by parsing <link rel="icon"> tags.
     * Results are cached for 24 hours.
     */
    protected function fetchFavicon(string $url): ?string
    {
        $cacheKey = 'oauth_favicon:'.md5($url);

        return Cache::remember($cacheKey, now()->addDay(), function () use ($url) {
            try {
                $response = Http::timeout(5)->get($url);
                if (!$response->successful()) {
                    return $this->fallbackFavicon($url);
                }

                $html = $response->body();

                // Match <link rel="icon" href="..."> or <link rel="shortcut icon" href="...">
                // Also handle href before rel
                $patterns = [
                    '/<link[^>]+rel=["\'](?:shortcut\s+icon|icon|apple-touch-icon)["\'][^>]+href=["\']([^"\']+)["\']/i',
                    '/<link[^>]+href=["\']([^"\']+)["\'][^>]+rel=["\'](?:shortcut\s+icon|icon|apple-touch-icon)["\']/i',
                ];

                foreach ($patterns as $pattern) {
                    if (preg_match($pattern, $html, $match)) {
                        return $this->resolveUrl($url, html_entity_decode($match[1]));
                    }
                }

                return $this->fallbackFavicon($url);
            } catch (\Exception $e) {
                return $this->fallbackFavicon($url);
            }
        });
    }

    /**
     * Fallback to /favicon.ico when no <link> icon is found.
     */
    protected function fallbackFavicon(string $url): ?string
    {
        $parsed = parse_url($url);
        $scheme = $parsed['scheme'] ?? 'https';
        $host = $parsed['host'] ?? null;

        if ($host) {
            return $scheme.'://'.$host.'/favicon.ico';
        }

        return null;
    }

    /**
     * Resolve a potentially relative URL against a base URL.
     */
    protected function resolveUrl(string $baseUrl, string $href): string
    {
        $href = trim($href);

        // Already absolute
        if (preg_match('/^https?:\/\//i', $href)) {
            return $href;
        }

        $parsed = parse_url($baseUrl);
        $scheme = $parsed['scheme'] ?? 'https';
        $host = $parsed['host'] ?? '';

        // Protocol-relative: //example.com/favicon.png
        if (str_starts_with($href, '//')) {
            return $scheme.':'.$href;
        }

        // Absolute path: /favicon.png
        if (str_starts_with($href, '/')) {
            return $scheme.'://'.$host.$href;
        }

        // Relative path
        $path = $parsed['path'] ?? '/';
        $dir = rtrim(dirname($path), '/');

        return $scheme.'://'.$host.$dir.'/'.$href;
    }

    protected function extractDomain(?string $redirect): ?string
    {
        if (!$redirect) {
            return null;
        }

        $url = trim(explode(',', $redirect)[0]);
        $parsed = parse_url($url);
        $host = $parsed['host'] ?? null;

        return $host;
    }

    protected function extractUrl(?string $redirect): ?string
    {
        if (!$redirect) {
            return null;
        }

        $url = trim(explode(',', $redirect)[0]);
        $parsed = parse_url($url);

        if (isset($parsed['host'])) {
            $scheme = $parsed['scheme'] ?? 'https';

            return $scheme.'://'.$parsed['host'];
        }

        return null;
    }
}
