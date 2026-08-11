import assert from "node:assert/strict";
import fs from "node:fs";
import vm from "node:vm";

const source = fs.readFileSync(
    new URL("../../public/sw.js", import.meta.url),
    "utf8"
);
const handlers = new Map();
const context = {
    URL,
    caches: {},
    clients: {},
    self: {
        location: {
            origin: "https://nexum-psa.local",
        },
        addEventListener(name, handler) {
            handlers.set(name, handler);
        },
    },
};

vm.createContext(context);
vm.runInContext(source, context, {
    filename: "public/sw.js",
});

assert.equal(typeof handlers.get("fetch"), "function");
assert.equal(typeof handlers.get("push"), "function");
assert.equal(typeof handlers.get("notificationclick"), "function");

function safeTarget(value) {
    context.testTarget = value;

    return vm.runInContext("safeSameOriginUrl(testTarget)", context);
}

assert.equal(
    safeTarget("/tech/profile/notifications?source=test#devices"),
    "/tech/profile/notifications?source=test#devices"
);
assert.equal(
    safeTarget("https://nexum-psa.local/tech/tickets/123"),
    "/tech/tickets/123"
);
assert.equal(
    safeTarget("https://attacker.example.test/steal"),
    "/tech/profile/notifications"
);
assert.equal(
    safeTarget("//attacker.example.test/steal"),
    "/tech/profile/notifications"
);
assert.equal(
    safeTarget("javascript:alert(1)"),
    "/tech/profile/notifications"
);
assert.equal(
    safeTarget(null),
    "/tech/profile/notifications"
);

console.log("WEB_PUSH_SERVICE_WORKER_SMOKE_OK");
