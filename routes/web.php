<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| SPA Entrypoint: All non-API web requests return the Blade layout which
| mounts the Vue 3 application.
|
*/

Route::get('/{any?}', function () {
    return view('layouts.app');
})->where('any', '^(?!api).*$');
