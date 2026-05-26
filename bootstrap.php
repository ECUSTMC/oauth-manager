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
    });

    Hook::addMenuItem('user', 5, [
        'title' => 'OAuthRecord::oauth-record.title',
        'link'  => 'user/oauth-record',
        'icon'  => 'fa-key',
    ]);
};
