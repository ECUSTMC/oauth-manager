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
        $generalForm = Option::form('general', trans('OAuthRecord::oauth-record.config.general.title'), function (OptionForm $form) {
            $form->checkbox('oauth_record_enable_auth_record', trans('OAuthRecord::oauth-record.config.general.enable-auth-record.title'))
                ->label(trans('OAuthRecord::oauth-record.config.general.enable-auth-record.label'))
                ->description(trans('OAuthRecord::oauth-record.config.general.enable-auth-record.description'));
            $form->checkbox('oauth_record_enable_app_hall', trans('OAuthRecord::oauth-record.config.general.enable-app-hall.title'))
                ->label(trans('OAuthRecord::oauth-record.config.general.enable-app-hall.label'))
                ->description(trans('OAuthRecord::oauth-record.config.general.enable-app-hall.description'));
        })->handle();

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

        // Yggdrasil Connect cleanup config
        $yggcForm = Option::form('yggc', trans('OAuthRecord::oauth-record.config.yggc.title'), function (OptionForm $form) {
            $form->checkbox('oauth_record_yggc_cleanup', trans('OAuthRecord::oauth-record.config.yggc.enable.title'))
                ->label(trans('OAuthRecord::oauth-record.config.yggc.enable.label'))
                ->description(trans('OAuthRecord::oauth-record.config.yggc.enable.description'));
            $form->text('oauth_record_yggc_auth_code_ttl', trans('OAuthRecord::oauth-record.config.yggc.auth-code-ttl.title'))
                ->description(trans('OAuthRecord::oauth-record.config.yggc.auth-code-ttl.description'));
            $form->text('oauth_record_yggc_refresh_token_ttl', trans('OAuthRecord::oauth-record.config.yggc.refresh-token-ttl.title'))
                ->description(trans('OAuthRecord::oauth-record.config.yggc.refresh-token-ttl.description'));
            $form->text('oauth_record_yggc_device_code_ttl', trans('OAuthRecord::oauth-record.config.yggc.device-code-ttl.title'))
                ->description(trans('OAuthRecord::oauth-record.config.yggc.device-code-ttl.description'));
            $form->text('oauth_record_yggc_grant_ttl', trans('OAuthRecord::oauth-record.config.yggc.grant-ttl.title'))
                ->description(trans('OAuthRecord::oauth-record.config.yggc.grant-ttl.description'));
            $form->text('oauth_record_yggc_interaction_ttl', trans('OAuthRecord::oauth-record.config.yggc.interaction-ttl.title'))
                ->description(trans('OAuthRecord::oauth-record.config.yggc.interaction-ttl.description'));
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

        // Yggdrasil Connect stats
        $yggcStats = ['total' => 0];
        if (option('oauth_record_yggc_cleanup')) {
            $yggcStats = $this->getYggcRevokedCounts($db);

            if ($yggcStats['total'] > 0) {
                $yggcForm->addMessage(trans('OAuthRecord::oauth-record.config.yggc-stats', [
                    'auth_codes' => $yggcStats['auth_codes'],
                    'refresh_tokens' => $yggcStats['refresh_tokens'],
                    'device_codes' => $yggcStats['device_codes'],
                    'grants' => $yggcStats['grants'],
                    'interactions' => $yggcStats['interactions'],
                ]), 'info');
            }
        }

        // Count redundant tokens (active tokens per client beyond the first one)
        $redundantCount = $this->countRedundantTokens();

        $hasRevoked = ($revokedTokenCount + $revokedRefreshTokenCount + $revokedAuthCodeCount + $yggcStats['total']) > 0;

        return view('OAuthRecord::config', [
            'forms' => ['general' => $generalForm, 'cleanup' => $cleanupForm, 'yggc' => $yggcForm],
            'has_revoked' => $hasRevoked,
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

        $result = [
            'tokens' => $tokenDeleted,
            'refresh' => $refreshDeleted,
            'codes' => $codeDeleted,
        ];

        // Clean up Yggdrasil Connect tables
        if (option('oauth_record_yggc_cleanup')) {
            $yggcDeleted = $this->cleanupYggc($db);
            $result = array_merge($result, $yggcDeleted);
        }

        return json(trans('OAuthRecord::oauth-record.config.cleanup-result', $result), 0);
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

    /**
     * Get the TTL value for a yggc config option, falling back to yggdrasil-connect's option, then to default.
     */
    protected function getYggcTtl(string $optionName, int $default): int
    {
        $value = option($optionName);

        if ($value !== null && $value !== '') {
            return max(1, (int) $value);
        }

        // Fall back to yggdrasil-connect's own option if available
        $yggcOptionMap = [
            'oauth_record_yggc_auth_code_ttl' => null,
            'oauth_record_yggc_refresh_token_ttl' => 'ygg_token_expire_2',
            'oauth_record_yggc_device_code_ttl' => 'ygg_device_code_expires_in',
            'oauth_record_yggc_grant_ttl' => 'ygg_grant_expires_in',
            'oauth_record_yggc_interaction_ttl' => null,
        ];

        $fallback = $yggcOptionMap[$optionName] ?? null;
        if ($fallback) {
            $fallbackValue = option($fallback);
            if ($fallbackValue !== null && $fallbackValue !== '') {
                return max(1, (int) $fallbackValue);
            }
        }

        return $default;
    }

    /**
     * Get revoked/expired counts from Yggdrasil Connect tables.
     */
    protected function getYggcRevokedCounts($db): array
    {
        $stats = [
            'auth_codes' => 0,
            'refresh_tokens' => 0,
            'device_codes' => 0,
            'grants' => 0,
            'interactions' => 0,
            'total' => 0,
        ];

        try {
            $authCodeTtl = $this->getYggcTtl('oauth_record_yggc_auth_code_ttl', 600);
            $stats['auth_codes'] = $db->table('yggc_authorization_codes')
                ->where('consumed', true)
                ->orWhere('created_at', '<', now()->subSeconds($authCodeTtl))
                ->count();

            $refreshTokenTtl = $this->getYggcTtl('oauth_record_yggc_refresh_token_ttl', 604800);
            $stats['refresh_tokens'] = $db->table('yggc_refresh_tokens')
                ->where('consumed', true)
                ->orWhere('created_at', '<', now()->subSeconds($refreshTokenTtl))
                ->count();

            $deviceCodeTtl = $this->getYggcTtl('oauth_record_yggc_device_code_ttl', 600);
            $stats['device_codes'] = $db->table('yggc_device_codes')
                ->where('consumed', true)
                ->orWhere('created_at', '<', now()->subSeconds($deviceCodeTtl))
                ->count();

            $grantTtl = $this->getYggcTtl('oauth_record_yggc_grant_ttl', 86400);
            $stats['grants'] = $db->table('yggc_grants')
                ->where('created_at', '<', now()->subSeconds($grantTtl))
                ->count();

            $interactionTtl = $this->getYggcTtl('oauth_record_yggc_interaction_ttl', 86400);
            $stats['interactions'] = $db->table('yggc_interactions')
                ->where('created_at', '<', now()->subSeconds($interactionTtl))
                ->count();

            $stats['total'] = $stats['auth_codes'] + $stats['refresh_tokens']
                + $stats['device_codes'] + $stats['grants'] + $stats['interactions'];
        } catch (\Exception $e) {
            // Silently ignore if yggc tables are not accessible
        }

        return $stats;
    }

    /**
     * Clean up expired/consumed records from Yggdrasil Connect tables.
     */
    protected function cleanupYggc($db): array
    {
        $result = [
            'yggc_auth_codes' => 0,
            'yggc_refresh_tokens' => 0,
            'yggc_device_codes' => 0,
            'yggc_grants' => 0,
            'yggc_interactions' => 0,
        ];

        try {
            $authCodeTtl = $this->getYggcTtl('oauth_record_yggc_auth_code_ttl', 600);
            $result['yggc_auth_codes'] = $db->table('yggc_authorization_codes')
                ->where('consumed', true)
                ->orWhere('created_at', '<', now()->subSeconds($authCodeTtl))
                ->delete();

            $refreshTokenTtl = $this->getYggcTtl('oauth_record_yggc_refresh_token_ttl', 604800);
            $result['yggc_refresh_tokens'] = $db->table('yggc_refresh_tokens')
                ->where('consumed', true)
                ->orWhere('created_at', '<', now()->subSeconds($refreshTokenTtl))
                ->delete();

            $deviceCodeTtl = $this->getYggcTtl('oauth_record_yggc_device_code_ttl', 600);
            $result['yggc_device_codes'] = $db->table('yggc_device_codes')
                ->where('consumed', true)
                ->orWhere('created_at', '<', now()->subSeconds($deviceCodeTtl))
                ->delete();

            $grantTtl = $this->getYggcTtl('oauth_record_yggc_grant_ttl', 86400);
            $result['yggc_grants'] = $db->table('yggc_grants')
                ->where('created_at', '<', now()->subSeconds($grantTtl))
                ->delete();

            $interactionTtl = $this->getYggcTtl('oauth_record_yggc_interaction_ttl', 86400);
            $result['yggc_interactions'] = $db->table('yggc_interactions')
                ->where('created_at', '<', now()->subSeconds($interactionTtl))
                ->delete();
        } catch (\Exception $e) {
            // Silently ignore if yggc tables are not accessible
        }

        return $result;
    }

    protected function getPassportConnection()
    {
        $connectionName = config('passport.storage.database.connection');

        return $connectionName ? DB::connection($connectionName) : DB::connection();
    }
}
