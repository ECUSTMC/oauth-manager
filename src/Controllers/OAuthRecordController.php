<?php

namespace OAuthRecord\Controllers;

use Auth;
use Carbon\Carbon;
use DB;
use Illuminate\Http\Request;
use Laravel\Passport\Token;

class OAuthRecordController
{
    public function index()
    {
        $user = Auth::user();

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
                $scopes = $tokens->flatMap(fn ($t) => $t->scopes ?: [])->unique()->values()->toArray();
                $earliestCreatedAt = $tokens->map(fn ($t) => $t->created_at)->filter()->sort()->first();
                return [
                    'client_id' => $client->id,
                    'client_name' => $client->name,
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

        // Also revoke associated refresh tokens
        DB::table('oauth_refresh_tokens')
            ->where('access_token_id', $token->id)
            ->update(['revoked' => true]);

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

            DB::table('oauth_refresh_tokens')
                ->where('access_token_id', $token->id)
                ->update(['revoked' => true]);
        }

        return json(trans('OAuthRecord::oauth-record.revoke-success'), 0);
    }
}
