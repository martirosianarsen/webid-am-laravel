<?php

use Illuminate\Support\Facades\Route;
use Webid\Laravel\Http\SiteConnectController;
use Webid\Laravel\Http\WebidController;

// Called by webid's server, not by a browser: no session, cookies or page token.
Route::post(config('webid.routes.prefix').'/backchannel-logout', [WebidController::class, 'backchannelLogout'])->middleware('throttle:60,1')->name('webid.backchannel-logout');

// Asked by webid's server while the site's administrator connects the site in one click: the state, if it started here.
Route::get('.well-known/webid-connect/{state}', [SiteConnectController::class, 'answer'])->where('state', '[A-Za-z0-9_-]{32,128}')->middleware('throttle:60,1')->name('webid.site-connect-answer');

Route::prefix(config('webid.routes.prefix'))->middleware(config('webid.routes.middleware'))->name('webid.')->group(function () {
    Route::get('connected', [SiteConnectController::class, 'connected'])->middleware('throttle:30,1')->name('site-connected');
    Route::get('login', [WebidController::class, 'login'])->middleware('throttle:30,1')->name('login');
    Route::get('callback', [WebidController::class, 'callback'])->middleware('throttle:30,1')->name('callback');
    Route::get('connect', [WebidController::class, 'connect'])->middleware('throttle:30,1')->name('connect');
    Route::get('token', [WebidController::class, 'token'])->middleware('throttle:120,1')->name('token');
    Route::post('logout', [WebidController::class, 'logout'])->name('logout');
});
