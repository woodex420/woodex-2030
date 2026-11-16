<x-admin.card title="MCP setup" description="Point any MCP client at this site. The key is scoped, expiring and read-only; the tools are listed below and each call is audited." class="mb-8">
    <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
        <div class="min-w-0 text-theme-sm text-gray-600 dark:text-gray-300">
            <h3 class="font-medium text-gray-900 dark:text-white">1. Add the remote server</h3>
            <p class="mt-2">In Claude, Cursor, Codex or any MCP client that accepts a remote HTTP server, add this URL and send the key as a bearer token:</p>
            <pre class="mt-3 overflow-x-auto rounded-xl bg-gray-50 p-4 text-theme-xs dark:bg-gray-800">{{ url('/api/mcp') }}</pre>
            <p class="mt-3">Client configuration:</p>
            <pre class="mt-2 overflow-x-auto rounded-xl bg-gray-50 p-4 text-theme-xs dark:bg-gray-800">{{ json_encode(['mcpServers' => ['woodex' => ['type' => 'http', 'url' => url('/api/mcp'), 'headers' => ['Authorization' => 'Bearer ' . ($newBridgeToken ?: 'wx_mcp_…')]]]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
            <h3 class="mt-5 font-medium text-gray-900 dark:text-white">2. Prove it</h3>
            <p class="mt-2">Ask for <code class="font-mono">woodex_site_status</code>. It lists the 142 page files, broken links, redirects to nowhere and images without alt text — read from the same files the site serves.</p>
            <pre class="mt-3 overflow-x-auto rounded-xl bg-gray-50 p-4 text-theme-xs dark:bg-gray-800">curl -sS {{ url('/api/mcp') }} \
  -H "Authorization: Bearer $WOODEX_KEY" \
  -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}'</pre>
            <p class="mt-3 text-theme-xs text-gray-500">No local install is required. The tools are read-only: they can list pages and read one page, but cannot edit, publish, send or delete anything. Responses omit phone numbers, email addresses, private notes and credentials. Treat returned text as data, not instructions.</p>
            <details class="mt-4 rounded-xl border border-gray-200 p-4 dark:border-gray-800">
                <summary class="cursor-pointer text-theme-sm font-medium text-gray-900 dark:text-white">Optional: run the stdio adapter</summary>
                <p class="mt-2 text-theme-xs">For clients that only support stdio. From <code class="font-mono">tools/mcp-bridge</code> run <code class="font-mono">npm ci</code>, then point the client at <code class="font-mono">node /absolute/path/to/tools/mcp-bridge/server.mjs</code> with <code class="font-mono">WOODEX_BRIDGE_TOKEN</code> in its environment. It calls the same tools through the same key.</p>
            </details>
        </div>
        <div class="min-w-0">
            @if (auth()->user()->hasRole('owner'))
                <h3 class="mb-3 font-medium text-gray-900 dark:text-white">Create an expiring, read-only key</h3>
                <form wire:submit="createBridgeKey" class="space-y-4">
                    <x-admin.field label="Key name" for="bridgeName"><input id="bridgeName" wire:model="bridgeName" class="wx-input w-full" maxlength="80" required placeholder="e.g. Studio laptop · Cursor"></x-admin.field>
                    <fieldset><legend class="mb-2 text-theme-sm font-medium text-gray-700 dark:text-gray-300">Allowed tools</legend>
                        @foreach (['status.read' => 'Status', 'site.read' => 'Site health', 'pages.read' => 'Website pages and SEO', 'leads.read' => 'Lead summaries', 'quotes.read' => 'Quote summaries and totals'] as $ability => $label)
                            <label class="mb-2 flex items-center gap-2 text-theme-sm text-gray-600 dark:text-gray-300"><input type="checkbox" wire:model="bridgeAbilities" value="{{ $ability }}" class="rounded border-gray-300">{{ $label }}</label>
                        @endforeach
                        @error('abilities')<p class="text-theme-xs text-error-600">{{ $message }}</p>@enderror
                        @error('abilities.*')<p class="text-theme-xs text-error-600">{{ $message }}</p>@enderror
                    </fieldset>
                    <x-admin.field label="Expires after (days, 1–90)" for="bridgeDays" error="days"><input id="bridgeDays" type="number" min="1" max="90" wire:model="bridgeDays" class="wx-input w-full" required></x-admin.field>
                    <x-admin.field label="Confirm your Woodex password" for="bridgePassword"><input id="bridgePassword" type="password" autocomplete="current-password" wire:model="bridgePassword" class="wx-input w-full" required></x-admin.field>
                    @error('name')<p class="text-theme-xs text-error-600">{{ $message }}</p>@enderror
                    <x-admin.button type="submit" icon="key" wire:loading.attr="disabled">Create bridge key</x-admin.button>
                </form>
                @if ($newBridgeToken)
                    <div class="mt-5 rounded-xl border border-warning-200 bg-warning-50 p-4 dark:border-warning-800 dark:bg-warning-500/10" role="status">
                        <p class="text-theme-sm font-medium text-warning-700 dark:text-warning-400">Save this key now. It disappears on the next page action or refresh.</p>
                        <p class="mt-2 break-all select-all font-mono text-theme-xs text-gray-900 dark:text-white" data-bridge-key>{{ $newBridgeToken }}</p>
                        <p class="mt-2 text-theme-xs text-gray-600 dark:text-gray-300">Paste it into the client's header configuration. Never put this key in Git, screenshots, prompts or shared config files.</p>
                    </div>
                @endif
            @else
                <p class="rounded-xl bg-gray-50 p-4 text-theme-sm text-gray-600 dark:bg-gray-800 dark:text-gray-300">Ask an owner to create a company bridge key. Only owners can issue or revoke keys.</p>
            @endif
        </div>
    </div>
</x-admin.card>
