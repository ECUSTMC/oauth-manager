<?php

namespace OAuthRecord\Controllers;

use App\Services\Facades\Option;
use App\Services\OptionForm;
use DB;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Laravel\Passport\Token;

class ConfigController extends Controller
{
    public function render(): View
    {
        $cleanupForm = Option::form('cleanup', trans('OAuthRecord::oauth-record.config.title'), function (OptionForm $form) {
            $form->checkbox('oauth_record_auto_cleanup', trans('OAuthRecord::oauth-record.config.auto-cleanup.title'))
                ->label(trans('OAuthRecord::oauth-record.config.auto-cleanup.label'))
                ->description(trans('OAuthRecord::oauth-record.config.auto-cleanup.description'));
            $form->checkbox('oauth_record_clean_revoked', trans('OAuthRecord::oauth-record.config.clean-revoked.title'))
                ->label(trans('OAuthRecord::oauth-record.config.clean-revoked.label'))
                ->description(trans('OAuthRecord::oauth-record.config.clean-revoked.description'));
        })->after(function () {
            // After saving config, redirect back to refresh stats
        })->handle();

        // Show statistics about revoked tokens
        $db = $this->getPassportConnection();

        $revokedTokenCount = $db->table('oauth_access_tokens')->where('revoked', true)->count();
        $revokedRefreshTokenCount = $db->table('oauth_refresh_tokens')->where('revoked', true)->count();
        $revokedAuthCodeCount = $db->table('oauth_auth_codes')->where('revoked', true)->count();

        $cleanupForm->addMessage(trans('OAuthRecord::oauth-record.config.stats', [
            'tokens' => $revokedTokenCount,
            'refresh' => $revokedRefreshTokenCount,
            'codes' => $revokedAuthCodeCount,
        ]), 'info');

        // Count redundant tokens (active tokens per client beyond the first one)
        $redundantCount = $this->countRedundantTokens();

        return view('OAuthRecord::config', [
            'forms' => ['cleanup' => $cleanupForm],
            'has_revoked' => ($revokedTokenCount + $revokedRefreshTokenCount + $revokedAuthCodeCount) > 0,
            'has_redundant' => $redundantCount > 0,
            'redundant_count' => $redundantCount,
        ]);
    }

    /**
     * Clean up ALL revoked tokens globally (admin action).
     */
    public function cleanup(Request $request): JsonResponse
    {
        $db = $this->getPassportConnection();

        // Delete revoked refresh tokens that belong to revoked access tokens
        $revokedAccessTokenIds = $db->table('oauth_access_tokens')
            ->where('revoked', true)
            ->pluck('id')
            ->toArray();

        $refreshDeleted = 0;
        $tokenDeleted = 0;
        $codeDeleted = 0;

        if (!empty($revokedAccessTokenIds)) {
            $refreshDeleted = $db->table('oauth_refresh_tokens')
                ->whereIn('access_token_id', $revokedAccessTokenIds)
                ->delete();
        }

        // Also delete orphaned revoked refresh tokens (whose access token no longer exists)
        $allAccessTokenIds = $db->table('oauth_access_tokens')->pluck('id')->toArray();
        $refreshDeleted += $db->table('oauth_refresh_tokens')
            ->whereNotIn('access_token_id', $allAccessTokenIds)
            ->delete();

        // Delete revoked access tokens
        $tokenDeleted = $db->table('oauth_access_tokens')
            ->where('revoked', true)
            ->delete();

        // Delete revoked auth codes
        $codeDeleted = $db->table('oauth_auth_codes')
            ->where('revoked', true)
            ->delete();

        return json(trans('OAuthRecord::oauth-record.config.cleanup-result', [
            'tokens' => $tokenDeleted,
            'refresh' => $refreshDeleted,
            'codes' => $codeDeleted,
        ]), 0);
    }

    /**
     * Clean up redundant tokens globally, keeping only the latest one per user+client.
     */
    public function cleanupRedundant(Request $request): JsonResponse
    {
        // Find all user_id + client_id combinations with more than one active token
        $db = $this->getPassportConnection();

        $personalClientIds = $db->table('oauth_clients')
            ->where('personal_access_client', true)
            ->pluck('id')
            ->toArray();

        $groups = Token::where('revoked', false)
            ->when(!empty($personalClientIds), function ($query) use ($personalClientIds) {
                $query->whereNotIn('client_id', $personalClientIds);
            })
            ->selectRaw('user_id, client_id, COUNT(*) as cnt')
            ->groupBy('user_id', 'client_id')
            ->havingRaw('cnt > 1')
            ->get();

        $revokedCount = 0;

        foreach ($groups as $group) {
            // Get all active tokens for this user+client, sorted by created_at desc
            $tokens = Token::where('user_id', $group->user_id)
                ->where('client_id', $group->client_id)
                ->where('revoked', false)
                ->orderBy('created_at', 'desc')
                ->get();

            // Keep the first (latest), revoke the rest
            $tokens->skip(1)->each(function ($token) use (&$revokedCount, $db) {
                $token->revoke();
                $db->table('oauth_refresh_tokens')
                    ->where('access_token_id', $token->id)
                    ->update(['revoked' => true]);
                $revokedCount++;
            });
        }

        return json(trans('OAuthRecord::oauth-record.config.cleanup-redundant-result', [
            'count' => $revokedCount,
        ]), 0);
    }

    /**
     * Count redundant active tokens globally.
     */
    protected function countRedundantTokens(): int
    {
        $db = $this->getPassportConnection();

        $personalClientIds = $db->table('oauth_clients')
            ->where('personal_access_client', true)
            ->pluck('id')
            ->toArray();

        $groups = Token::where('revoked', false)
            ->when(!empty($personalClientIds), function ($query) use ($personalClientIds) {
                $query->whereNotIn('client_id', $personalClientIds);
            })
            ->selectRaw('user_id, client_id, COUNT(*) as cnt')
            ->groupBy('user_id', 'client_id')
            ->havingRaw('cnt > 1')
            ->get();

        return $groups->sum(function ($group) {
            return $group->cnt - 1;
        });
    }

    protected function getPassportConnection()
    {
        $connectionName = config('passport.storage.database.connection');

        return $connectionName ? DB::connection($connectionName) : DB::connection();
    }
}
