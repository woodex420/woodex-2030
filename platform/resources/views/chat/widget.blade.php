{{--
    The web chat widget (P8.5), served as /chat/widget.js. One self-contained IIFE: no dependencies,
    no eval, no inline event handlers, so it survives any Content-Security-Policy the site sets.
    The studio's side of these threads is the Conversations inbox (channel "Website chat"); this
    script only opens the thread, runs nothing on its own beyond showing the team's replies.
--}}
@php
    $wxChatConfig = ['brand' => $brand, 'start' => $startUrl, 'send' => $sendUrl, 'messages' => $messagesUrl];
@endphp
const WX_CHAT = @json($wxChatConfig);

(function () {
    if (window.__wxChatLoaded) { return; }
    window.__wxChatLoaded = true;

    var KEY = 'wx-chat-token';
    var state = { token: null, lastId: 0, open: false, poller: null, busy: false, connecting: false };

    function el(tag, css, text) {
        var node = document.createElement(tag);
        if (css) { node.style.cssText = css; }
        if (text !== undefined) { node.textContent = text; }
        return node;
    }

    function esc(value) {
        return String(value == null ? '' : value).replace(/[&<>"']/g, function (ch) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch];
        });
    }

    function api(url, body) {
        var options = { method: 'POST', headers: { 'Accept': 'application/json' } };
        if (body) {
            options.headers['Content-Type'] = 'application/json';
            options.body = JSON.stringify(body);
        }
        return fetch(url, options).then(function (response) {
            if (!response.ok) { throw new Error('http ' + response.status); }
            return response.json();
        });
    }

    function poll() {
        if (!state.token) { return; }
        fetch(WX_CHAT.messages + '?token=' + encodeURIComponent(state.token) + '&after=' + state.lastId, {
            headers: { 'Accept': 'application/json' },
        })
            .then(function (response) { if (!response.ok) { throw new Error('Poll unavailable'); } return response.json(); })
            .then(function (data) {
                if (data && data.ok) { draw(data.messages); connection('Private studio conversation'); }
            })
            .catch(function () { connection('Connection interrupted — your draft is safe', true); });
    }

    function draw(messages) {
        var list = panel.querySelector('.wx-chat-log');
        for (var i = 0; i < messages.length; i++) {
            var message = messages[i];
            if (message.id <= state.lastId) { continue; }
            state.lastId = message.id;

            var row = el('div', 'display:flex;margin:0 0 10px;' + (message.who === 'visitor' ? 'justify-content:flex-end;' : 'justify-content:flex-start;'));
            var bubble = el('div',
                'max-width:78%;padding:9px 13px;border-radius:14px;font:14px/1.45 -apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Arial,sans-serif;white-space:pre-wrap;word-break:break-word;'
                + (message.who === 'visitor'
                    ? 'background:#0f1e36;color:#fff;border-bottom-right-radius:4px;'
                    : 'background:#fff;border:1px solid #e6eaf0;color:#24303f;border-bottom-left-radius:4px;'),
                message.body);
            if (message.at) {
                var at = new Date(message.at);
                if (!isNaN(at.getTime())) { bubble.appendChild(el('span', 'display:block;font-size:10px;opacity:.6;text-align:right;margin-top:5px;', at.toLocaleTimeString([], {hour:'2-digit', minute:'2-digit'}))); }
            }
            row.appendChild(bubble);
            list.appendChild(row);
        }
        if (messages.length) {
            list.scrollTop = list.scrollHeight;
        }
    }

    function connection(text, failed) {
        if (!panel) { return; }
        panel.querySelector('.wx-chat-status').textContent = text;
        panel.querySelector('.wx-chat-status').style.color = failed ? '#b45309' : '#667085';
        panel.querySelector('.wx-chat-retry').hidden = !failed;
    }

    function startThread() {
        if (state.connecting) { return; }
        state.connecting = true;
        connection('Connecting to the studio…');
        var stored = null;
        try { stored = window.localStorage.getItem(KEY); } catch (e) {}

        return api(WX_CHAT.start + '?token=' + encodeURIComponent(stored || ''), {})
            .then(function (data) {
                if (!data.ok || !data.token) { throw new Error('Chat unavailable'); }
                state.token = data.token;
                connection('Private studio conversation');
                try { window.localStorage.setItem(KEY, data.token); } catch (e) {}
                draw(data.messages);
            })
            .catch(function () { connection('Chat unavailable — retry or use the contact form', true); })
            .finally(function () { state.connecting = false; });
    }

    function note(text) {
        var list = panel.querySelector('.wx-chat-log');
        var row = el('div', 'display:flex;justify-content:center;margin:0 0 10px;');
        row.appendChild(el('div', 'font:12px/1.4 -apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Arial,sans-serif;color:#8a8272;text-align:center;max-width:85%;', text));
        list.appendChild(row);
        list.scrollTop = list.scrollHeight;
    }

    var panel, button;

    function open() {
        if (state.open) { return; }
        state.open = true;
        button.setAttribute('aria-expanded', 'true');
        button.style.display = 'none';
        panel.style.display = 'flex';
        if (!state.token) { startThread(); }
        state.poller = window.setInterval(poll, 5000);
        var input = panel.querySelector('input');
        if (input) { input.focus(); }
    }

    function close() {
        state.open = false;
        button.setAttribute('aria-expanded', 'false');
        button.focus();
        panel.style.display = 'none';
        button.style.display = 'flex';
        if (state.poller) { window.clearInterval(state.poller); state.poller = null; }
    }

    function send(text) {
        if (!text || state.busy || !state.token) { return; }
        state.busy = true;
        var submit = panel.querySelector('button[type=submit]');
        submit.disabled = true; submit.textContent = 'Sending…';
        api(WX_CHAT.send, { token: state.token, body: text })
            .then(function (data) {
                if (!data.ok) { throw new Error('Message refused'); }
                draw(data.messages);
                var input = panel.querySelector('input');
                if (input.value.trim() === text) { input.value = ''; }
                connection('Private studio conversation');
            })
            .catch(function () { connection('Send not confirmed — draft retained. Check the thread before retrying.', true); })
            .finally(function () { state.busy = false; submit.disabled = false; submit.textContent = 'Send'; });
    }

    function build() {
        var navy = '#0f1e36';

        button = el('button',
            'position:fixed;right:20px;bottom:20px;z-index:2147483000;width:58px;height:58px;border-radius:50%;border:none;background:'
            + navy + ';color:#f7f3ec;display:flex;align-items:center;justify-content:center;cursor:pointer;box-shadow:0 6px 20px rgba(15,30,54,.35);',
            '');
        button.setAttribute('aria-label', 'Open chat');
        button.setAttribute('aria-expanded', 'false');
        button.setAttribute('aria-controls', 'wx-chat-panel');
        button.innerHTML = '<svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/></svg>';
        button.addEventListener('click', open);

        panel = el('div',
            'position:fixed;right:20px;bottom:20px;z-index:2147483000;width:min(380px,calc(100vw - 32px));height:min(600px,calc(100dvh - 40px));'
            + 'display:none;flex-direction:column;background:#f5f7fa;border:1px solid #e6eaf0;border-radius:20px;overflow:hidden;box-shadow:0 16px 50px rgba(15,30,54,.32);'
            + 'font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Arial,sans-serif;');

        panel.id = 'wx-chat-panel'; panel.setAttribute('role', 'dialog'); panel.setAttribute('aria-label', 'Chat with the Woodex studio');
        panel.addEventListener('keydown', function (event) { if (event.key === 'Escape') { close(); } });
        var head = el('div', 'background:#fff;color:' + navy + ';padding:18px;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid #e6eaf0;');
        var title = el('div');
        title.appendChild(el('div', 'font-size:15px;font-weight:600;', WX_CHAT.brand));
        var status = el('div', 'font-size:11px;color:#667085;margin-top:5px;', 'Private studio conversation'); status.className = 'wx-chat-status'; status.setAttribute('role', 'status'); title.appendChild(status);
        var closeBtn = el('button', 'background:none;border:none;color:#667085;font-size:22px;line-height:1;cursor:pointer;padding:2px 4px;', '×');
        closeBtn.setAttribute('aria-label', 'Close chat');
        closeBtn.addEventListener('click', close);
        head.appendChild(title);
        head.appendChild(closeBtn);

        var log = el('div', 'flex:1;overflow-y:auto;padding:16px;');
        log.className = 'wx-chat-log'; log.setAttribute('role', 'log'); log.setAttribute('aria-label', 'Chat messages');

        var retry = el('button', 'border:0;background:#fff;color:#0f1e36;padding:10px;font-size:12px;cursor:pointer;', 'Retry connection');
        retry.className = 'wx-chat-retry'; retry.hidden = true; retry.type = 'button'; retry.addEventListener('click', function () { if (!state.token) { startThread(); } else { poll(); } });

        var form = el('form', 'display:flex;gap:8px;padding:12px;border-top:1px solid #e7e0d2;background:#fff;');
        var input = el('input');
        input.type = 'text';
        input.placeholder = 'Type your message…';
        input.setAttribute('aria-label', 'Your message');
        input.maxLength = 2000;
        input.style.cssText = 'min-width:0;flex:1;border:1px solid #e4e7ec;border-radius:10px;padding:10px 12px;font-size:14px;background:#fff;color:#24303f;outline:none;';
        var sendBtn = el('button', 'background:' + navy + ';color:#f7f3ec;border:none;border-radius:10px;padding:10px 16px;font-size:14px;cursor:pointer;', 'Send');
        sendBtn.type = 'submit';
        form.appendChild(input);
        form.appendChild(sendBtn);
        form.addEventListener('submit', function (event) {
            event.preventDefault();
            var text = input.value.trim();

            // Never swallow what the visitor typed: while the thread is opening (busy) or before
            // the token has arrived, the words stay in the box and the next Enter sends them.
            if (!text || state.busy || !state.token) { return; }

            send(text);
        });

        panel.appendChild(head);
        panel.appendChild(log);
        panel.appendChild(retry);
        panel.appendChild(form);

        document.body.appendChild(button);
        document.body.appendChild(panel);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', build);
    } else {
        build();
    }
})();
