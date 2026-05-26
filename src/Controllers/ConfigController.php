<?php

namespace OAuthRecord\Controllers;

use App\Services\Facades\Option;
use App\Services\OptionForm;
use DB;
use Illuminate\Contracts\View\View;
use Illuminate\Routing\Controller;

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
        })->handle();

        // Show statistics about revoked tokens
        $connectionName = config('passport.storage.database.connection');
        $db = $connectionName ? DB::connection($connectionName) : DB::connection();

        $revokedTokenCount = $db->table('oauth_access_tokens')->where('revoked', true)->count();
        $revokedRefreshTokenCount = $db->table('oauth_refresh_tokens')->where('revoked', true)->count();
        $revokedAuthCodeCount = $db->table('oauth_auth_codes')->where('revoked', true)->count();

        $cleanupForm->addMessage(trans('OAuthRecord::oauth-record.config.stats', [
            'tokens' => $revokedTokenCount,
            'refresh' => $revokedRefreshTokenCount,
            'codes' => $revokedAuthCodeCount,
        ]), 'info');

        return view('OAuthRecord::config', [
            'forms' => ['cleanup' => $cleanupForm],
        ]);
    }
}
