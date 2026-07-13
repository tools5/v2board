/**
 * Root-scoped Web Push service worker.
 * Served at /web-push-sw.js so notifications work site-wide.
 */
self.addEventListener('install', () => {
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(self.clients.claim());
});

function resolveNotificationUrl(rawUrl) {
    const origin = self.location.origin;
    const fallbackUrl = origin + '/#/dashboard';
    if (!rawUrl) {
        return fallbackUrl;
    }

    try {
        if (/^https?:\/\//i.test(rawUrl)) {
            return rawUrl;
        }
        if (rawUrl.charAt(0) === '#') {
            return origin + '/' + rawUrl;
        }
        if (rawUrl.charAt(0) === '/') {
            return origin + rawUrl;
        }
        return new URL(rawUrl, origin + '/').href;
    } catch (error) {
        return fallbackUrl;
    }
}

self.addEventListener('push', (event) => {
    let payload = {};

    try {
        payload = event.data ? event.data.json() : {};
    } catch (error) {
        payload = { body: event.data ? event.data.text() : '' };
    }

    const notificationUrl = resolveNotificationUrl(payload.url || '/#/dashboard');
    const actions = Array.isArray(payload.actions)
        ? payload.actions.slice(0, 2).map((item) => ({
            action: String(item.action || 'open'),
            title: String(item.title || '打开'),
        }))
        : [];

    const options = {
        body: payload.body || '',
        icon: payload.icon || '/theme/blued/images/logo.png',
        badge: payload.badge || payload.icon || '/theme/blued/images/logo.png',
        tag: payload.tag || 'website-notification',
        renotify: payload.renotify !== false,
        requireInteraction: !!payload.requireInteraction,
        data: {
            url: notificationUrl,
            actions: Array.isArray(payload.actions) ? payload.actions : [],
        },
    };

    // Large image only when absolute http(s) URL — relative paths never show on desktop.
    if (payload.image && /^https?:\/\//i.test(String(payload.image))) {
        options.image = String(payload.image);
    }

    if (actions.length > 0) {
        options.actions = actions;
    }

    event.waitUntil(
        self.registration.showNotification(payload.title || '网站通知', options)
    );
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();

    const actionName = event.action || '';
    const actionList = (event.notification.data && event.notification.data.actions) || [];
    let targetUrl = (event.notification.data && event.notification.data.url)
        || resolveNotificationUrl('/#/dashboard');

    if (actionName && Array.isArray(actionList)) {
        const matched = actionList.find((item) => String(item.action) === actionName);
        if (matched && matched.url) {
            targetUrl = resolveNotificationUrl(matched.url);
        }
    }

    event.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(async (clientList) => {
            const targetOrigin = new URL(targetUrl).origin;

            for (const client of clientList) {
                if (new URL(client.url).origin !== targetOrigin) {
                    continue;
                }
                try {
                    if ('focus' in client) {
                        await client.focus();
                    }
                    if ('navigate' in client) {
                        await client.navigate(targetUrl);
                        return client;
                    }
                    return client;
                } catch (error) {
                    // try next client
                }
            }

            return self.clients.openWindow ? self.clients.openWindow(targetUrl) : null;
        })
    );
});
