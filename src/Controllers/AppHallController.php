<?php

namespace OAuthRecord\Controllers;

use Auth;
use Laravel\Passport\Client;
use Laravel\Passport\Token;

class AppHallController
{
    public function index()
    {
        $user = Auth::user();

        // Get all active OAuth clients (non-personal, non-revoked)
        $clients = Client::where('personal_access_client', false)
            ->where('password_client', false)
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

        $apps = $clients->map(function ($client) use ($authorizedClientIds) {
            return [
                'id' => $client->id,
                'name' => $client->name,
                'domain' => $this->extractDomain($client->redirect),
                'redirect' => $client->redirect,
                'authorized' => in_array($client->id, $authorizedClientIds),
                'user_count' => Token::where('client_id', $client->id)
                    ->where('revoked', false)
                    ->distinct('user_id')
                    ->count('user_id'),
            ];
        })->values()->toArray();

        return view('OAuthRecord::hall', ['apps' => $apps]);
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
}
