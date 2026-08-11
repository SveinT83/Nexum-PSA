"use strict";

const CACHE_NAME = "nexum-pwa-v2";
const OFFLINE_URL = "/offline.html";
const NOTIFICATION_FALLBACK_URL = "/tech/profile/notifications";
const STATIC_ASSET_EXTENSIONS = [
    ".css",
    ".js",
    ".png",
    ".jpg",
    ".jpeg",
    ".svg",
    ".webp",
    ".ico",
    ".woff",
    ".woff2",
];

self.addEventListener("install", (event) => {
    event.waitUntil(
        caches.open(CACHE_NAME).then((cache) => cache.addAll([OFFLINE_URL]))
    );
    self.skipWaiting();
});

self.addEventListener("activate", (event) => {
    event.waitUntil(
        caches.keys().then((cacheNames) => Promise.all(
            cacheNames
                .filter((cacheName) => cacheName !== CACHE_NAME)
                .map((cacheName) => caches.delete(cacheName))
        ))
    );
    self.clients.claim();
});

self.addEventListener("fetch", (event) => {
    if (event.request.method !== "GET") {
        return;
    }

    if (event.request.mode === "navigate") {
        event.respondWith(
            fetch(event.request).catch(() => caches.match(OFFLINE_URL))
        );
        return;
    }

    const url = new URL(event.request.url);

    if (url.origin !== self.location.origin || !isStaticAsset(url.pathname)) {
        return;
    }

    event.respondWith(cacheStaticAsset(event.request));
});

self.addEventListener("push", (event) => {
    event.waitUntil(showVisiblePushNotification(event));
});

self.addEventListener("notificationclick", (event) => {
    event.notification.close();
    event.waitUntil(focusOrOpenTarget(event.notification.data?.url));
});

self.addEventListener("message", (event) => {
    const payload = event.data || {};

    if (payload.type !== "nexum-close-notifications" || !Array.isArray(payload.tags)) {
        return;
    }

    event.waitUntil(closeNotificationsByTags(payload.tags));
});

async function showVisiblePushNotification(event) {
    const payload = readPushPayload(event);
    const targetUrl = safeSameOriginUrl(payload.data?.url || payload.url);
    const title = safeText(payload.title, "New Nexum notification", 100);
    const body = safeText(payload.body, "Open Nexum to view the notification.", 180);
    const tag = safeText(payload.tag, "nexum-notification", 120);

    return self.registration.showNotification(title, {
        body,
        icon: "/logo.png",
        badge: "/logo.png",
        tag,
        data: {
            url: targetUrl,
        },
    });
}

async function closeNotificationsByTags(tags) {
    const safeTags = tags
        .filter((tag) => typeof tag === "string")
        .map((tag) => tag.trim())
        .filter((tag) => tag.length > 0 && tag.length <= 120 && /^nexum-[a-z0-9._:-]+$/i.test(tag));

    if (safeTags.length === 0 || !self.registration.getNotifications) {
        return;
    }

    const notifications = await self.registration.getNotifications();
    notifications.forEach((notification) => {
        if (safeTags.includes(notification.tag)) {
            notification.close();
        }
    });
}

function readPushPayload(event) {
    if (!event.data) {
        return {};
    }

    try {
        const payload = event.data.json();

        return payload && typeof payload === "object" ? payload : {};
    } catch (error) {
        return {};
    }
}

function safeText(value, fallback, maxLength) {
    if (typeof value !== "string" || value.trim() === "") {
        return fallback;
    }

    return value.trim().slice(0, maxLength);
}

function safeSameOriginUrl(value) {
    try {
        const url = new URL(
            typeof value === "string" && value !== "" ? value : NOTIFICATION_FALLBACK_URL,
            self.location.origin
        );

        if (url.origin !== self.location.origin) {
            return NOTIFICATION_FALLBACK_URL;
        }

        return `${url.pathname}${url.search}${url.hash}`;
    } catch (error) {
        return NOTIFICATION_FALLBACK_URL;
    }
}

async function focusOrOpenTarget(value) {
    const targetPath = safeSameOriginUrl(value);
    const targetUrl = new URL(targetPath, self.location.origin);
    const windowClients = await clients.matchAll({
        type: "window",
        includeUncontrolled: true,
    });

    const existingClient = windowClients.find((client) => {
        try {
            return new URL(client.url).origin === self.location.origin;
        } catch (error) {
            return false;
        }
    });

    if (existingClient) {
        if ("navigate" in existingClient && existingClient.url !== targetUrl.href) {
            await existingClient.navigate(targetUrl.href);
        }

        return existingClient.focus();
    }

    return clients.openWindow(targetUrl.href);
}

function isStaticAsset(pathname) {
    return pathname.startsWith("/build/")
        || pathname.startsWith("/storage/")
        || STATIC_ASSET_EXTENSIONS.some((extension) => pathname.endsWith(extension));
}

async function cacheStaticAsset(request) {
    const cache = await caches.open(CACHE_NAME);

    try {
        const response = await fetch(request);

        if (response && response.ok) {
            await cache.put(request, response.clone());
        }

        return response;
    } catch (error) {
        return await cache.match(request);
    }
}
