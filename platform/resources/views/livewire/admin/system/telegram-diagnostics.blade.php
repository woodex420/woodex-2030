                    @if ($card['key'] === 'telegram' && $telegramConfiguration !== null)
                        <div class="mt-4 text-theme-xs text-gray-600 dark:text-gray-300" role="status">
                            <p>Active settings file: <span class="font-mono">{{ $telegramConfiguration['path'] }}</span></p>
                            <p>File status: {{ $telegramConfiguration['file_status'] }} · Configuration cached: {{ $telegramConfiguration['cached'] ? 'yes' : 'no' }}</p>
                            <p>TELEGRAM_BOT_TOKEN: {{ $telegramConfiguration['token_loaded'] ? 'loaded' : 'NOT loaded' }} · {{ $telegramConfiguration['token_in_file'] ? 'present in active file' : 'not found in active file' }}</p>
                            <p>TELEGRAM_WEBHOOK_SECRET: {{ $telegramConfiguration['secret_loaded'] ? 'loaded' : 'NOT loaded' }} · {{ $telegramConfiguration['secret_in_file'] ? 'present in active file' : 'not found in active file' }}</p>
                            @if ($telegramConfiguration['alternate_path'])
                                <p>Another private settings file exists at {{ $telegramConfiguration['alternate_path'] }}, but this request selected the active file above. Do not delete either file or change database settings. Ask hosting support to check which file should be active.</p>
                            @endif
                            @if ($telegramConfiguration['file_status'] === 'invalid')
                                <p>The active .env has a syntax error. Correct its syntax privately; never share its contents or parser errors.</p>
                            @elseif ($telegramConfiguration['file_status'] === 'unreadable')
                                <p>The active .env cannot be read. Check its location and permissions with hosting support.</p>
                            @elseif ($telegramConfiguration['file_matches_runtime'] === false)
                                <p>Saved values differ from the running configuration. Reload configuration after saving. If the difference remains, ask hosting support to check server environment overrides.</p>
                            @endif
                            <p>Reload clears Laravel configuration cache only, then reloads this page. It does not edit .env, create credentials, change database settings or register the webhook. Other saved .env changes also take effect on the next request.</p>
                            <x-admin.button variant="secondary" size="sm" wire:click="reloadTelegramConfiguration" wire:confirm="Clear cached server configuration and reload? All saved .env changes will take effect on the next request." wire:loading.attr="disabled">Reload server configuration</x-admin.button>
                        </div>
                    @endif
                    @if ($card['key'] === 'telegram' && $telegramStatus !== null)
                        <div class="mt-4 text-theme-xs text-gray-600 dark:text-gray-300" role="status">
                            <p>Telegram API: {{ $telegramStatus['api_ok'] ? 'reachable' : 'failed — check token and outbound HTTPS in the log below' }}</p>
                            <p>Bot: {{ $telegramStatus['bot_username'] ? '@'.$telegramStatus['bot_username'] : 'unavailable' }} · {{ $telegramStatus['bot_matches'] ? 'correct bot' : 'MISMATCH — check the token belongs to WoodexInteriorBot' }}</p>
                            <p>Webhook: {{ ! $telegramStatus['webhook_set'] ? 'not registered' : ($telegramStatus['webhook_matches'] ? 'matches this site' : 'MISMATCH — press Connect webhook on this site') }}</p>
                            <p>Pending updates: {{ $telegramStatus['pending_updates'] }}</p>
                            <p>Last delivery error: {{ $telegramStatus['last_error'] ?: 'none reported' }}{{ $telegramStatus['last_error_at'] ? ' (Unix time '.$telegramStatus['last_error_at'].')' : '' }}</p>
                            <p>Last received here: {{ $telegramStatus['last_received'] ?? 'none' }}</p>
                            <p>Last processed here: {{ $telegramStatus['last_processed'] ?? 'none' }}</p>
                            <p>Send /start to the bot, wait a few seconds, then check again. Registration alone does not prove delivery. A delivery error may be historical; compare its time with your latest message.</p>
                        </div>
                    @endif
