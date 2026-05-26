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
};
