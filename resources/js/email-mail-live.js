/**
 * Email Mail Live Invalidation Handler
 *
 * This handles private channel subscriptions for email invalidation events
 * and coordinates with Livewire for UI updates.
 */

class EmailMailLive {
    constructor() {
        this.initialized = false;
        this.userId = null;
        this.channel = null;
        this.reconnectTimeout = null;
    }

    init(userId) {
        if (this.initialized || !userId) return;

        this.userId = userId;
        this.initialized = true;

        if (window.Echo) {
            this.subscribe();
        } else {
            document.addEventListener('echo-ready', () => this.subscribe());
        }
    }

    subscribe() {
        if (!window.Echo || !this.userId) return;

        // Use the custom auth endpoint for Email module
        // Echo configuration can be overridden per subscription if needed,
        // but normally we'd want the global Echo to use the right auth endpoint
        // IF it's an email-only page.
        // However, we'll try to use the global instance.

        this.channel = window.Echo.private(`email.user.${this.userId}`)
            .listen('.email.projection.invalidated.v1', (e) => {
                this.handleInvalidation(e);
            });

        console.log(`[EmailLive] Subscribed to private channel for user ${this.userId}`);
    }

    handleInvalidation(event) {
        console.log('[EmailLive] Invalidation event received:', event);

        // Dispatch to Livewire components
        // We use a browser event that Livewire can listen for
        window.dispatchEvent(new CustomEvent('email-mail-invalidated', {
            detail: { payload: event }
        }));
    }

    startTyping(conversationId) {
        this.whisperTyping(conversationId, true);
    }

    stopTyping(conversationId) {
        this.whisperTyping(conversationId, false);
    }

    whisperTyping(conversationId, isTyping) {
        if (!this.channel) return;

        this.channel.whisper('typing', {
            conversation_id: conversationId,
            user_id: this.userId,
            is_typing: isTyping
        });
    }

    whisperReading(conversationId) {
        if (!this.channel) return;

        this.channel.whisper('reading', {
            conversation_id: conversationId,
            user_id: this.userId
        });
    }

    onPresence(callback) {
        if (!this.channel) return;

        this.channel.listenForWhisper('typing', (e) => callback('typing', e));
        this.channel.listenForWhisper('reading', (e) => callback('reading', e));
    }
}

window.EmailMailLive = new EmailMailLive();
