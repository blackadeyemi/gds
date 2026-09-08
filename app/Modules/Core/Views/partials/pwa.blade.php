{{--
    PWA wiring — installability + the service worker.
    Included from every layout <head> (admin + login) so "Install app" is
    offered on any page, including the login screen.
--}}
<link rel="manifest" href="{{ asset('manifest.webmanifest') }}" />
<meta name="theme-color" content="#2a78d6" />
<link rel="icon" type="image/png" sizes="32x32" href="{{ asset('images/pwa/favicon-32.png') }}" />
<link rel="icon" type="image/png" sizes="16x16" href="{{ asset('images/pwa/favicon-16.png') }}" />
<link rel="apple-touch-icon" href="{{ asset('images/pwa/apple-touch-icon.png') }}" />
<meta name="mobile-web-app-capable" content="yes" />
<meta name="apple-mobile-web-app-capable" content="yes" />
<meta name="apple-mobile-web-app-status-bar-style" content="default" />
<meta name="apple-mobile-web-app-title" content="GDS" />
<script>
    // Register the service worker. A new deploy (bumped VERSION in sw.js)
    // installs the new worker, which skipWaiting()s and takes control; the
    // controllerchange guard reloads the page ONCE so the update applies
    // without the user hard-refreshing. That is the whole update pipeline.
    if ('serviceWorker' in navigator) {
        window.addEventListener('load', function () {
            navigator.serviceWorker.register('/sw.js', { scope: '/' }).catch(function () {});
        });
        var reloading = false;
        navigator.serviceWorker.addEventListener('controllerchange', function () {
            if (reloading) return;
            reloading = true;
            window.location.reload();
        });
    }
</script>
