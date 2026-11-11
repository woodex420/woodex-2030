/*
 * Woodex site tags — generated from Website → Settings.
 *
 * Every page file loads this one script, so an ID saved in the admin is live on the next page load without a
 * deploy. Nothing below runs until an ID is filled in, and signed-in staff never get a tag at all.
 */
@php
    $quote = static fn (string $id): string => (string) json_encode($id, JSON_THROW_ON_ERROR);
@endphp
@if ($gtm === '' && $ga4 === '' && $clarity === '')
/* No tracking is configured. */
@else
(function (window, document) {
    if (window.__wxTags) {
        return;
    }
    window.__wxTags = true;
@if ($gtm !== '')
    /* Google Tag Manager: one container for Analytics, Ads and anything else. */
    window.dataLayer = window.dataLayer || [];
    window.dataLayer.push({ 'gtm.start': new Date().getTime(), event: 'gtm.js' });
    (function () {
        var first = document.getElementsByTagName('script')[0];
        var tag = document.createElement('script');
        tag.async = true;
        tag.src = 'https://www.googletagmanager.com/gtm.js?id=' + {!! $quote($gtm) !!};
        first.parentNode.insertBefore(tag, first);
    })();
@elseif ($ga4 !== '')
    /* Google Analytics 4, for a site that has no Tag Manager container. */
    (function () {
        var tag = document.createElement('script');
        tag.async = true;
        tag.src = 'https://www.googletagmanager.com/gtag/js?id=' + {!! $quote($ga4) !!};
        document.head.appendChild(tag);
    })();
    window.dataLayer = window.dataLayer || [];
    function gtag() {
        window.dataLayer.push(arguments);
    }
    gtag('js', new Date());
    gtag('config', {!! $quote($ga4) !!});
@endif
@if ($clarity !== '')
    /* Microsoft Clarity: recordings and heatmaps. */
    (function (c, l, a, r, i, t, y) {
        c[a] = c[a] || function () { (c[a].q = c[a].q || []).push(arguments); };
        t = l.createElement(r);
        t.async = 1;
        t.src = 'https://www.clarity.ms/tag/' + i;
        y = l.getElementsByTagName(r)[0];
        y.parentNode.insertBefore(t, y);
    })(window, document, 'clarity', 'script', {!! $quote($clarity) !!});
@endif
})(window, document);
@endif
