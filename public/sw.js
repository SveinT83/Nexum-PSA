"use strict";

const CACHE_NAME = "nexum-pwa-v1";
const OFFLINE_URL = "/offline.html";
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
