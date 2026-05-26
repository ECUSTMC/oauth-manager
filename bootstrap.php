<?php

use App\Services\Hook;
use Illuminate\Support\Facades\Route;

return function () {
    Hook::addRoute(function () {
        Route::namespace('OAuthRecord\Controllers')
            ->prefix('user/oauth-record')
            ->middleware(['web', 'authorize', 'verified'])
            ->group(function () {
                Route::get('', 'OAuthRecordController@index');
                Route::post('revoke/{tokenId}', 'OAuthRecordController@revoke');
                Route::post('revoke-client/{clientId}', 'OAuthRecordController@revokeClient');
            });

        // OAuth App Hall (public, login required)
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

    Hook::addMenuItem('user', 5, [
        'title' => 'OAuthRecord::oauth-record.title',
        'link'  => 'user/oauth-record',
        'icon'  => 'fa-key',
    ]);

    Hook::addMenuItem('explore', 0, [
        'title' => 'OAuthRecord::oauth-record.hall-title',
        'link'  => 'oauth-apps',
        'icon'  => 'fa-th-large',
    ]);
};
