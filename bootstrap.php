<?php

use App\Services\Hook;
use Illuminate\Support\Facades\Route;

return function () {
    Hook::addRoute(function () {
        // OAuth authorization records
        Route::namespace('OAuthRecord\Controllers')
            ->prefix('user/oauth-record')
            ->middleware(['web', 'authorize', 'verified'])
            ->group(function () {
                Route::get('', 'OAuthRecordController@index');
                Route::post('revoke/{tokenId}', 'OAuthRecordController@revoke');
                Route::post('revoke-client/{clientId}', 'OAuthRecordController@revokeClient');
            });

        // OAuth App Hall
        Route::namespace('OAuthRecord\Controllers')
            ->prefix('oauth-apps')
            ->middleware(['web', 'authorize'])
            ->group(function () {
                Route::get('', 'AppHallController@index');
            });

        // Admin cleanup route
        Route::namespace('OAuthRecord\Controllers')
            ->prefix('admin/oauth-record')
            ->middleware(['web', 'auth', 'role:admin'])
            ->group(function () {
                Route::post('cleanup', 'ConfigController@cleanup');
                Route::post('cleanup-redundant', 'ConfigController@cleanupRedundant');
            });
    });

    if (option('oauth_record_enable_auth_record', true)) {
        Hook::addMenuItem('user', 5, [
            'title' => 'OAuthRecord::oauth-record.title',
            'link'  => 'user/oauth-record',
            'icon'  => 'fa-key',
        ]);
    }

    if (option('oauth_record_enable_app_hall', true)) {
        Hook::addMenuItem('explore', 0, [
            'title' => 'OAuthRecord::oauth-record.hall-title',
            'link'  => 'oauth-apps',
            'icon'  => 'fa-th-large',
        ]);
    }
};
