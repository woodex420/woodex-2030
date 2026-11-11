<?php

use App\Http\Controllers\Api\Bridge\BridgeController;
use App\Http\Controllers\Api\Bridge\McpHttpController;
use App\Http\Controllers\Api\Public\ChatWidgetController;
use App\Http\Controllers\Api\Public\LeadController;
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

// Furniture API v1 foundation. Public capture only: this is not a staff/authenticated API.
Route::prefix('v1')->name('api.v1.')->middleware('module:lead_api')->group(function (): void {
    Route::post('/leads', [LeadController::class, 'store'])
        ->middleware('throttle:public-leads')->name('leads.store');
    Route::get('/leads', fn () => response()->json(['message' => 'Use POST to submit an enquiry.'], 405)->header('Allow', 'POST'));
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
