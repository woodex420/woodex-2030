<?php

use App\Http\Controllers\Api\Bridge\BridgeController;
use App\Http\Controllers\Api\Bridge\McpHttpController;
use App\Http\Controllers\Api\Public\ChatWidgetController;
use App\Http\Controllers\Api\Public\LeadController;
use App\Http\Controllers\Api\V1;
use App\Http\Controllers\Website\LegacySiteBridgeController;
use App\Http\Middleware\AuthenticateMcpBridge;
use Illuminate\Support\Facades\Route;

/*
| Public, stateless JSON API (website forms, estimator, booking, chat in P4, live-site bridge).
| Prefix "api" is added by the framework: POST /api/public/leads
*/
Route::prefix('public')->name('api.public.')->group(function (): void {
    Route::post('/leads', [LeadController::class, 'store'])
        ->middleware(['module:lead_api', 'throttle:public-leads'])
        ->name('leads.store');

    // The web chat widget (P8.5): open a thread, send a message, poll for replies. Stateless;
    // the visitor's token is the only credential; the owner's switch (Website Settings) gates all three.
    Route::post('/chat/start', [ChatWidgetController::class, 'start'])
        ->middleware(['module:inbox', 'throttle:chat-write'])
        ->name('chat.start');

    Route::post('/chat/send', [ChatWidgetController::class, 'send'])
        ->middleware(['module:inbox', 'throttle:chat-write'])
        ->name('chat.send');

    Route::get('/chat/messages', [ChatWidgetController::class, 'messages'])
        ->middleware(['module:inbox', 'throttle:chat-poll'])
        ->name('chat.messages');
});

/*
| /api/v1: what the shop and outside clients use. Its rules are docs/woodex-os/29-API-V1-RULES.md, its contract is
| resources/api/v1.openapi.json, and tests/Feature/Api/V1ContractTest.php holds every address to both: a route
| added here without its entry in the contract fails that test.
|
| Every route is named and carries "api.v1" (App\Http\Middleware\ApiV1) with what it takes: "key" for a create,
| "query=a|b" for the query parameters it reads. Every answer is made by App\Support\Api\ApiAnswer, every error by
| App\Support\Api\ApiErrors. Nothing here needs a sign-in, and nothing here is for staff: the admin's screens are
| drawn by the server and have no JSON API.
*/
Route::prefix('v1')->name('api.v1.')->group(function (): void {
    // What a visitor of the shop sends.
    Route::middleware('module:lead_api')->group(function (): void {
        Route::post('/leads', [V1\LeadController::class, 'store'])
            ->middleware(['throttle:public-leads', 'api.v1:key'])->name('leads.store');
        Route::post('/leads/whatsapp-handoff', [V1\LeadHandoffController::class, 'store'])
            ->middleware(['throttle:20,1', 'api.v1'])->name('leads.handoff');
        Route::post('/quote-requests', [V1\QuoteRequestController::class, 'store'])
            ->middleware(['module:quote_requests', 'throttle:public-leads', 'api.v1:key'])->name('quote-requests.store');
    });

    // What the shop reads: the published category tree with its pages, independent of the enquiry module.
    Route::get('/catalogue/categories', [V1\CategoryController::class, 'index'])
        ->middleware(['throttle:120,1', 'api.v1'])->name('catalogue.categories');
    Route::get('/catalogue/categories/{category}/hero/{revision}', [V1\CategoryController::class, 'hero'])
        ->whereNumber(['category', 'revision'])->middleware(['throttle:120,1', 'api.v1'])->name('catalogue.categories.hero');

    // What the shop reads of the products: only the published copy of a product that is not archived, in pages
    // (T-105). Its filters are named here, and a query parameter that is not named is refused by the door.
    Route::get('/catalogue/products', [V1\ProductController::class, 'index'])
        ->middleware(['throttle:120,1', 'api.v1:query=page|per_page|category|series|since'])->name('catalogue.products');
    Route::get('/catalogue/products/{product}', [V1\ProductController::class, 'show'])
        ->middleware(['throttle:120,1', 'api.v1'])->name('catalogue.products.show');
    Route::get('/catalogue/products/{product}/picture/{image}/{version}', [V1\ProductController::class, 'picture'])
        ->whereNumber(['image', 'version'])->middleware(['throttle:120,1', 'api.v1'])->name('catalogue.products.picture');

    // Only explicitly published React media mappings; never private product images or the whole library.
    Route::get('/media', [V1\MediaController::class, 'index'])
        ->middleware(['throttle:120,1', 'api.v1'])->name('media');
    Route::get('/media/{media}/{version}', [V1\MediaController::class, 'file'])
        ->whereNumber(['media', 'version'])->middleware(['throttle:120,1', 'api.v1'])->name('media.file');

    // The last route of the group: whatever nothing above owns, for every method (404, or 405 with Allow).
    Route::any('/{unknown?}', V1\UnknownAddress::class)
        ->where('unknown', '.*')->middleware('throttle:120,1')->name('unknown');
});

/*
| The two addresses the original website's JavaScript calls (docs/11 §2). The 142 pages are served as the old site
| made them, so they come with their own scripts, which post here. Answering in the shape they expect means the
| pages work without a single character being changed on them, and an enquiry lands in the CRM like any other.
*/
Route::get('/forms.php', [LegacySiteBridgeController::class, 'config'])
    ->middleware(['module:legacy_bridge', 'throttle:public-site'])
    ->name('legacy.forms.config');

Route::post('/forms.php', [LegacySiteBridgeController::class, 'forms'])
    ->middleware(['module:legacy_bridge', 'throttle:public-site'])
    ->name('legacy.forms.store');

Route::match(['get', 'post'], '/chat.php', [LegacySiteBridgeController::class, 'chat'])
    ->middleware(['module:legacy_bridge', 'throttle:public-site'])
    ->name('legacy.chat');

// Remote MCP (JSON-RPC 2.0 over HTTPS) for any MCP client: URL + `wx_mcp_` bearer key. Read-only tools only.
Route::post('/mcp', McpHttpController::class)
    ->middleware(['module:mcp_bridge', 'throttle:120,1', AuthenticateMcpBridge::class])
    ->name('api.mcp');
// Streamable HTTP clients may probe with GET; this endpoint answers JSON-RPC over POST only.
Route::get('/mcp', fn () => response()->json(['error' => 'Use POST with a JSON-RPC body.'], 405)->header('Allow', 'POST'))->middleware('module:mcp_bridge');

// Read-only REST backing for the local stdio MCP adapter. Kept for older clients; /api/mcp is the same tools.
Route::prefix('bridge/v1')->name('api.bridge.')->middleware([
    'module:mcp_bridge',
    'throttle:120,1',
    AuthenticateMcpBridge::class,
])->group(function (): void {
    Route::get('/status', [BridgeController::class, 'status'])->name('status');
    Route::get('/leads', [BridgeController::class, 'leads'])->name('leads');
    Route::get('/quotes', [BridgeController::class, 'quotes'])->name('quotes');
});
