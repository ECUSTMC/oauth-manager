<?php

namespace OAuthRecord\Controllers;

use Auth;
use Carbon\Carbon;
use DB;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Laravel\Passport\Token;

class OAuthRecordController
{
    public function index()
    {
        $user = Auth::user();

        // Auto cleanup: if enabled, revoke redundant tokens keeping only the latest one per client
        if (option('oauth_record_auto_cleanup')) {
            $this->cleanupRedundantTokens($user->uid);
        }

        // Auto cleanup: if enabled, physically delete revoked tokens
        if (option('oauth_record_clean_revoked')) {
            $this->deleteRevokedTokens($user->uid);
        }

        // Get all non-revoked, non-expired access tokens for this user
        // grouped by client, excluding personal access tokens
        $authorizations = Token::where('user_id', $user->uid)
            ->where('revoked', false)
            ->where(function ($query) {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->whereNotIn('client_id', function ($query) {
                $query->select('id')
                    ->from('oauth_clients')
                    ->where('personal_access_client', true);
            })
            ->with('client')
            ->get()
            ->filter(fn ($token) => $token->client && !$token->client->revoked)
            ->groupBy('client_id')
            ->map(function ($tokens) {
                $client = $tokens->first()->client;
                // scopes is already cast to array by Token model
                $scopes = $tokens->flatMap(fn ($t) => $t->scopes ?: [])->unique()->values()->map(function ($scope) {
                    $key = 'OAuthRecord::oauth-record.scopes.'.$scope;
                    $translated = trans($key);
                    // If no translation found, fall back to original scope name
                    return [
                        'id' => $scope,
                        'name' => $translated !== $key ? $translated : $scope,
                    ];
                })->values()->toArray();
                $earliestCreatedAt = $tokens->map(fn ($t) => $t->created_at)->filter()->sort()->first();
                return [
                    'client_id' => $client->id,
                    'client_name' => $client->name,
                    'client_domain' => $this->extractDomain($client->redirect),
                    'scopes' => $scopes,
                    'authorized_at' => $earliestCreatedAt
                        ? Carbon::parse($earliestCreatedAt)->toDateTimeString()
                        : '-',
                    'token_count' => $tokens->count(),
                    'token_ids' => $tokens->pluck('id')->toArray(),
                ];
            })
            ->values()
            ->toArray();

        return view('OAuthRecord::index', ['authorizations' => $authorizations]);
    }

    public function revoke(Request $request, $tokenId)
    {
        $user = Auth::user();

        $token = Token::where('id', $tokenId)
            ->where('user_id', $user->uid)
            ->where('revoked', false)
            ->first();

        if (!$token) {
            return json(trans('OAuthRecord::oauth-record.token-not-found'), 1);
        }

        $token->revoke();
        $this->revokeRefreshTokens($token->id);

        return json(trans('OAuthRecord::oauth-record.revoke-success'), 0);
    }

    public function revokeClient(Request $request, $clientId)
    {
        $user = Auth::user();

        $tokens = Token::where('user_id', $user->uid)
            ->where('client_id', $clientId)
            ->where('revoked', false)
            ->get();

        if ($tokens->isEmpty()) {
            return json(trans('OAuthRecord::oauth-record.token-not-found'), 1);
        }

        foreach ($tokens as $token) {
            $token->revoke();
            $this->revokeRefreshTokens($token->id);
        }

        return json(trans('OAuthRecord::oauth-record.revoke-success'), 0);
    }

    /**
     * Revoke refresh tokens associated with the given access token ID.
     */
    protected function revokeRefreshTokens(string $accessTokenId): void
    {
        $connection = $this->getPassportConnection();
        $connection->table('oauth_refresh_tokens')
            ->where('access_token_id', $accessTokenId)
            ->update(['revoked' => true]);
    }

    /**
     * Revoke redundant tokens for a user, keeping only the latest one per client.
     */
    protected function cleanupRedundantTokens(int $userId): void
    {
        // Find clients with more than one active token
        $clientTokenCounts = Token::where('user_id', $userId)
            ->where('revoked', false)
            ->whereNotIn('client_id', function ($query) {
                $query->select('id')
                    ->from('oauth_clients')
                    ->where('personal_access_client', true);
            })
            ->selectRaw('client_id, COUNT(*) as cnt')
            ->groupBy('client_id')
            ->havingRaw('cnt > 1')
            ->get();

        foreach ($clientTokenCounts as $row) {
            // Get all active tokens for this client, sorted by created_at desc
            $tokens = Token::where('user_id', $userId)
                ->where('client_id', $row->client_id)
                ->where('revoked', false)
                ->orderBy('created_at', 'desc')
                ->get();

            // Keep the first (latest) one, revoke the rest
            $tokens->skip(1)->each(function ($token) {
                $token->revoke();
                $this->revokeRefreshTokens($token->id);
            });
        }
    }

    /**
     * Physically delete revoked tokens for a user from the database.
     */
    protected function deleteRevokedTokens(int $userId): void
    {
        $connection = $this->getPassportConnection();

        // Get IDs of revoked access tokens for this user
        $revokedTokenIds = Token::where('user_id', $userId)
            ->where('revoked', true)
            ->pluck('id')
            ->toArray();

        if (!empty($revokedTokenIds)) {
            // Delete associated refresh tokens first
            $connection->table('oauth_refresh_tokens')
                ->whereIn('access_token_id', $revokedTokenIds)
                ->delete();

            // Delete the access tokens
            $connection->table('oauth_access_tokens')
                ->whereIn('id', $revokedTokenIds)
                ->delete();
        }

        // Also clean up revoked auth codes for this user
        if (Schema::connection($connection->getName())->hasTable('oauth_auth_codes')) {
            $connection->table('oauth_auth_codes')
                ->where('user_id', $userId)
                ->where('revoked', true)
                ->delete();
        }
    }

    /**
     * Get the database connection used by Passport.
     */
    protected function getPassportConnection()
    {
        $connectionName = config('passport.storage.database.connection');

        return $connectionName ? DB::connection($connectionName) : DB::connection();
    }

    /**
     * Extract domain from a redirect URL string (may contain multiple URLs separated by comma).
     */
    protected function extractDomain(?string $redirect): ?string
    {
        if (!$redirect) {
            return null;
        }

        // Take the first URL if there are multiple
        $url = trim(explode(',', $redirect)[0]);
        $parsed = parse_url($url);
        $host = $parsed['host'] ?? null;

        if ($host) {
            return $host;
        }

        return null;
    }
}
