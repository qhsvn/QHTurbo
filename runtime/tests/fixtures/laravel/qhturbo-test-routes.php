<?php

// Test routes for runtime/tests/frameworks.php (copied into routes/ of a
// throw-away Laravel app). Each pair of routes checks that state set by
// one request does not leak into the next one.

use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

$noCsrf = array_filter([
    'Illuminate\Foundation\Http\Middleware\PreventRequestForgery', // Laravel 13+
    'Illuminate\Foundation\Http\Middleware\ValidateCsrfToken',
    'Illuminate\Foundation\Http\Middleware\VerifyCsrfToken',
], 'class_exists');

Route::prefix('qht')->withoutMiddleware($noCsrf)->group(function () {
    Route::get('/hello', fn () => 'hello');
    Route::get('/pid', fn () => (string) getmypid());
    Route::get('/json', fn () => response()->json(['framework' => 'laravel', 'version' => App::version(), 'items' => range(1, 10)]));
    Route::get('/boom', fn () => throw new RuntimeException('secret detail'));

    Route::get('/session/inc', function (Request $r) {
        $n = $r->session()->get('n', 0) + 1;
        $r->session()->put('n', $n);
        return (string) $n;
    });

    Route::get('/user/set/{name}', function (string $name) {
        Auth::setUser(new \App\Models\User(['name' => $name]));
        return 'set';
    });
    Route::get('/user/whoami', fn () => Auth::user()?->name ?? 'guest');

    Route::get('/cookie', function () {
        Cookie::queue('qht_leak', 'v', 10);
        return 'queued';
    });

    Route::get('/config/set', function () {
        config(['app.qht_test' => 'changed']);
        return 'set';
    });
    Route::get('/config/get', fn () => config('app.qht_test', 'default'));
    // A top-level key that did not exist at boot must disappear too.
    Route::get('/config/add', function () {
        config(['qht_new' => ['k' => 'v']]);
        return 'added';
    });
    Route::get('/config/new', fn () => json_encode(config('qht_new')));

    Route::get('/locale/set', function () {
        App::setLocale('vi');
        return 'set';
    });
    Route::get('/locale/get', fn () => App::getLocale());

    Route::get('/db/open-tx', function () {
        DB::beginTransaction();
        DB::table('users')->insert(['name' => 'tx', 'email' => 'tx-' . uniqid() . '@x.test', 'password' => 'x']);
        return 'open';
    });
    Route::get('/db/state', fn () => response()->json([
        'level' => DB::transactionLevel(),
        'tx_rows' => DB::table('users')->where('name', 'tx')->count(),
    ]));

    Route::post('/upload', function (Request $r) {
        $f = $r->file('doc');
        return response()->json([
            'title' => $r->input('title'),
            'name' => $f?->getClientOriginalName(),
            'size' => $f?->getSize(),
            'valid' => $f?->isValid(),
            'content' => $f ? file_get_contents($f->getRealPath()) : null,
        ]);
    });
    Route::post('/json-body', fn (Request $r) => response()->json(['got' => $r->input('a')]));
});
