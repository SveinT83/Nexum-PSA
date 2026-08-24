@php
    $composerSubmitLabel = match ($composerMode) {
        'reply_all' => 'reply all',
        'forward' => 'forward',
        'compose' => 'message',
        'provider_draft' => 'draft',
        default => 'reply',
    };
    $canUseComposerAi = $this->canUseComposerAiAction($selectedPlacement);
    $canUseComposerAiDraftReply = $this->canUseComposerAiDraftReply($selectedPlacement);
@endphp

<form
    class="mail-composer border-bottom p-3 bg-body-tertiary"
    wire:submit.prevent="sendComposer"
    x-data="{
        mode: 'visual',
        value: @entangle('composerBodyHtml'),
        collaborationEnabled: @js($collaborationEnabled),
        typingTimeout: null,
        typing(isTyping) {
            if (this.collaborationEnabled && window.EmailMailLive && {{ (int) ($selectedPlacement?->conversation_id ?? 0) }}) {
                if (isTyping) {
                    window.EmailMailLive.startTyping({{ (int) ($selectedPlacement?->conversation_id ?? 0) }});
                } else {
                    window.EmailMailLive.stopTyping({{ (int) ($selectedPlacement?->conversation_id ?? 0) }});
                }
            }
        },
        handleInput() {
            this.sync();
            if (this.typingTimeout) clearTimeout(this.typingTimeout);
            this.typing(true);
            this.typingTimeout = setTimeout(() => this.typing(false), 3000);
        },
        clean(html) {
            const doc = new DOMParser().parseFromString(html || '', 'text/html');
            doc.querySelectorAll('script, style, iframe, object, embed, form').forEach((node) => node.remove());
            doc.body.querySelectorAll('*').forEach((node) => {
                Array.from(node.attributes).forEach((attribute) => {
                    const name = attribute.name.toLowerCase();
                    const value = attribute.value.trim().toLowerCase();
                    if (name.startsWith('on') || ((name === 'href' || name === 'src') && value.startsWith('javascript:'))) {
                        node.removeAttribute(attribute.name);
                    }
                });
            });
            return doc.body.innerHTML;
        },
        sync() {
            if (this.mode === 'visual' && this.$refs.editor) {
                const cleanValue = this.clean(this.$refs.editor.innerHTML);
                if (cleanValue !== this.$refs.editor.innerHTML) {
                    this.$refs.editor.innerHTML = cleanValue;
                }
                this.value = cleanValue;
            }

            // Livewire 3 entanglement is deferred by default. Update its local
            // state synchronously so the following autosave or submit action
            // always receives the editor value from either composer mode.
            $wire.$set('composerBodyHtml', this.value || '', false);
        },
        command(name, value = null) {
            this.mode = 'visual';
            this.$nextTick(() => {
                this.$refs.editor.focus();
                document.execCommand(name, false, value);
                this.sync();
            });
        },
        link() {
            const url = window.prompt('URL');
            if (url) {
                this.command('createLink', url);
            }
        },
        setMode(nextMode) {
            if (this.mode === 'visual') {
                this.sync();
            }
            this.mode = nextMode;
            this.$nextTick(() => {
                if (this.mode === 'visual' && this.$refs.editor) {
                    this.$refs.editor.innerHTML = this.clean(this.value || '');
                    this.$refs.editor.focus();
                }
            });
        },
        init() {
            this.$nextTick(() => {
                if (this.$refs.editor) {
                    this.$refs.editor.innerHTML = this.clean(this.value || '');
                }
            });
            this.$watch('value', (value) => {
                if (this.mode === 'visual' && this.$refs.editor && document.activeElement !== this.$refs.editor && this.$refs.editor.innerHTML !== (value || '')) {
                    this.$refs.editor.innerHTML = this.clean(value || '');
                }
            });
        },
    }"
    x-on:submit.capture="sync()">
    <div class="row g-2 align-items-start">
        <div class="col-12 d-flex flex-wrap align-items-center justify-content-between gap-2">
            <div class="small text-muted">
                @if($composerDraftStatus === 'restored')
                    <span class="badge text-bg-info">Draft restored</span>
                    @if($composerDraftSavedAt)
                        <span class="ms-1">Saved {{ $composerDraftSavedAt }}</span>
                    @endif
                @elseif($composerDraftStatus === 'saved')
                    <span class="badge text-bg-light border">Draft saved</span>
                    @if($composerDraftSavedAt)
                        <span class="ms-1">{{ $composerDraftSavedAt }}</span>
                    @endif
                @else
                    <span class="badge text-bg-light border">Local draft</span>
                @endif
                @if($composerAttachments)
                    <span class="ms-1 text-warning">New attachments save with the draft.</span>
                @endif
                @if($composerDraftProviderStatus)
                    <span @class([
                        'badge',
                        'ms-1',
                        'text-bg-success' => $composerDraftProviderStatus === 'synced',
                        'text-bg-info' => $composerDraftProviderStatus === 'pending',
                        'text-bg-warning' => $composerDraftProviderStatus === 'error',
                        'text-bg-light border' => ! in_array($composerDraftProviderStatus, ['synced', 'pending', 'error'], true),
                    ]) title="{{ $composerDraftProviderMessage }}">
                        {{ $composerDraftProviderMessage }}
                    </span>
                @endif
            </div>
            <button type="button" class="btn btn-sm btn-outline-secondary" wire:click="saveComposerDraft" wire:loading.attr="disabled" wire:target="saveComposerDraft" x-on:click="sync()">
                <i class="bi bi-save me-1" aria-hidden="true"></i>Save draft
            </button>
        </div>
        <div class="col-12 col-lg-4">
            <label for="mail-composer-from" class="form-label small fw-semibold mb-1">From</label>
            @if($composerMode === 'compose')
                <select id="mail-composer-from" class="form-select form-select-sm @error('composerAccountId') is-invalid @enderror" wire:model.live="composerAccountId" wire:change="saveComposerDraft(false)">
                    @foreach($sendableAccounts as $account)
                        <option value="{{ $account->id }}">{{ $account->from_name ?: $account->address }} &lt;{{ $account->address }}&gt;</option>
                    @endforeach
                </select>
                @error('composerAccountId')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            @else
                <input id="mail-composer-from" type="text" class="form-control form-control-sm" value="{{ $selectedPlacement?->account?->from_name ?: $selectedPlacement?->account?->address ?: 'Selected mailbox' }}{{ $selectedPlacement?->account?->from_name ? ' <'.$selectedPlacement?->account?->address.'>' : '' }}" disabled>
            @endif
        </div>
        <div class="col-12 col-lg-4">
            <label for="mail-composer-to" class="form-label small fw-semibold mb-1">To</label>
            <input id="mail-composer-to" type="text" class="form-control form-control-sm @error('composerTo') is-invalid @enderror" wire:model.live.debounce.1500ms="composerTo" wire:blur="saveComposerDraft(false)">
            @error('composerTo')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
            @error('to')
                <div class="text-danger small mt-1">{{ $message }}</div>
            @enderror
        </div>
        <div class="col-12 col-lg-4">
            <label for="mail-composer-cc" class="form-label small fw-semibold mb-1">Cc</label>
            <input id="mail-composer-cc" type="text" class="form-control form-control-sm @error('composerCc') is-invalid @enderror" wire:model.live.debounce.1500ms="composerCc" wire:blur="saveComposerDraft(false)">
            @error('composerCc')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>
        <div class="col-12">
            <label for="mail-composer-subject" class="form-label small fw-semibold mb-1">Subject</label>
            <input id="mail-composer-subject" type="text" class="form-control form-control-sm @error('composerSubject') is-invalid @enderror" wire:model.live.debounce.1500ms="composerSubject" wire:blur="saveComposerDraft(false)">
            @error('composerSubject')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>
        <div class="col-12">
            <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-1">
                <label for="mail-html-editor-source" class="form-label small fw-semibold mb-0">Message</label>
                <div class="btn-group btn-group-sm" role="group" aria-label="Composer mode">
                    <button type="button" class="btn btn-outline-secondary" x-bind:class="{ 'active': mode === 'visual' }" x-on:click="setMode('visual')">Visual</button>
                    <button type="button" class="btn btn-outline-secondary" x-bind:class="{ 'active': mode === 'html' }" x-on:click="setMode('html')">HTML</button>
                </div>
            </div>
            <div class="mail-html-editor-toolbar btn-toolbar gap-2 mb-2" role="toolbar" aria-label="Formatting">
                <div class="btn-group btn-group-sm" role="group">
                    <button type="button" class="btn btn-outline-secondary" title="Bold" x-on:click="command('bold')">
                        <i class="bi bi-type-bold" aria-hidden="true"></i><span class="visually-hidden">Bold</span>
                    </button>
                    <button type="button" class="btn btn-outline-secondary" title="Italic" x-on:click="command('italic')">
                        <i class="bi bi-type-italic" aria-hidden="true"></i><span class="visually-hidden">Italic</span>
                    </button>
                    <button type="button" class="btn btn-outline-secondary" title="Underline" x-on:click="command('underline')">
                        <i class="bi bi-type-underline" aria-hidden="true"></i><span class="visually-hidden">Underline</span>
                    </button>
                </div>
                <div class="btn-group btn-group-sm" role="group">
                    <button type="button" class="btn btn-outline-secondary" title="Bulleted list" x-on:click="command('insertUnorderedList')">
                        <i class="bi bi-list-ul" aria-hidden="true"></i><span class="visually-hidden">Bulleted list</span>
                    </button>
                    <button type="button" class="btn btn-outline-secondary" title="Numbered list" x-on:click="command('insertOrderedList')">
                        <i class="bi bi-list-ol" aria-hidden="true"></i><span class="visually-hidden">Numbered list</span>
                    </button>
                </div>
                <div class="btn-group btn-group-sm" role="group">
                    <button type="button" class="btn btn-outline-secondary" title="Link" x-on:click="link()">
                        <i class="bi bi-link-45deg" aria-hidden="true"></i><span class="visually-hidden">Link</span>
                    </button>
                    <button type="button" class="btn btn-outline-secondary" title="Remove format" x-on:click="command('removeFormat')">
                        <i class="bi bi-eraser" aria-hidden="true"></i><span class="visually-hidden">Remove format</span>
                    </button>
                </div>
                @if($canUseComposerAi)
                    <div class="input-group input-group-sm mail-ai-instruction">
                        <span class="input-group-text" title="Mail AI">
                            <i class="bi bi-stars" aria-hidden="true"></i>
                            <span class="visually-hidden">Mail AI</span>
                        </span>
                        <input type="text" class="form-control @error('composerAiInstruction') is-invalid @enderror" wire:model.defer="composerAiInstruction" maxlength="1000" placeholder="Optional AI guidance">
                    </div>
                    <div class="btn-group btn-group-sm" role="group" aria-label="Mail AI composer">
                        @if($canUseComposerAiDraftReply)
                            <button type="button" class="btn btn-outline-primary" wire:click="applyComposerAi('draft_reply')" wire:loading.attr="disabled" wire:target="applyComposerAi" x-on:click="sync()" title="Draft reply">
                                <i class="bi bi-magic" aria-hidden="true"></i><span class="visually-hidden">Draft reply</span>
                            </button>
                        @endif
                        <button type="button" class="btn btn-outline-primary" wire:click="applyComposerAi('improve')" wire:loading.attr="disabled" wire:target="applyComposerAi" x-on:click="sync()" title="Improve text">
                            <i class="bi bi-pencil-square" aria-hidden="true"></i><span class="visually-hidden">Improve text</span>
                        </button>
                        <button type="button" class="btn btn-outline-primary" wire:click="applyComposerAi('shorten')" wire:loading.attr="disabled" wire:target="applyComposerAi" x-on:click="sync()" title="Shorten">
                            <i class="bi bi-arrows-collapse" aria-hidden="true"></i><span class="visually-hidden">Shorten</span>
                        </button>
                        <button type="button" class="btn btn-outline-primary" wire:click="applyComposerAi('friendly')" wire:loading.attr="disabled" wire:target="applyComposerAi" x-on:click="sync()" title="Warmer tone">
                            <i class="bi bi-chat-heart" aria-hidden="true"></i><span class="visually-hidden">Warmer tone</span>
                        </button>
                        <button type="button" class="btn btn-outline-primary" wire:click="applyComposerAi('translate_norwegian')" wire:loading.attr="disabled" wire:target="applyComposerAi" x-on:click="sync()" title="Norwegian">
                            NO<span class="visually-hidden">Norwegian</span>
                        </button>
                    </div>
                @endif
            </div>
            @if($canUseComposerAi)
                @error('composerAiInstruction')
                    <div class="text-danger small mb-2">{{ $message }}</div>
                @enderror
                <div wire:loading wire:target="applyComposerAi" class="small text-muted mb-2">
                    <span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>Mail AI drafting...
                </div>
                @if(! empty(data_get($composerAiResult, 'warnings')))
                    <div class="small text-muted mb-2">
                        {{ collect(data_get($composerAiResult, 'warnings', []))->implode(' ') }}
                    </div>
                @endif
            @endif
            @if($composerActionStatus)
                <div class="mail-composer-inline-status alert alert-{{ $composerActionStatus['type'] }} py-1 px-2 mb-2 small d-flex" role="status" aria-live="polite">
                    <i @class([
                        'bi',
                        'bi-check-circle' => $composerActionStatus['type'] === 'success',
                        'bi-info-circle' => $composerActionStatus['type'] === 'info',
                        'bi-exclamation-triangle' => $composerActionStatus['type'] === 'warning',
                        'bi-x-circle' => $composerActionStatus['type'] === 'danger',
                    ]) aria-hidden="true"></i>
                    <span>{{ $composerActionStatus['message'] }}</span>
                </div>
            @endif
            <div
                x-show="mode === 'visual'"
                x-ref="editor"
                wire:ignore
                class="mail-html-editor-surface form-control form-control-sm bg-white @error('composerBodyHtml') is-invalid @enderror"
                contenteditable="true"
                role="textbox"
                aria-multiline="true"
                x-on:input.debounce.500ms="sync(); $wire.saveComposerDraft(false)"
                x-on:blur="sync()"></div>
            <textarea
                x-show="mode === 'html'"
                x-model="value"
                id="mail-html-editor-source"
                class="mail-html-editor-source form-control form-control-sm font-monospace @error('composerBodyHtml') is-invalid @enderror"
                x-on:input.debounce.500ms="sync(); $wire.saveComposerDraft(false)"></textarea>
            @error('composerBodyHtml')
                <div class="invalid-feedback d-block">{{ $message }}</div>
            @enderror
            @error('body_html')
                <div class="text-danger small mt-1">{{ $message }}</div>
            @enderror
            @error('composer')
                <div class="text-danger small mt-1">{{ $message }}</div>
            @enderror
        </div>
        <div class="col-12">
            <div class="d-flex flex-wrap align-items-center gap-2">
                <label for="mail-composer-attachments" class="btn btn-sm btn-outline-secondary mb-0">
                    <i class="bi bi-paperclip me-1" aria-hidden="true"></i>Attach
                </label>
                <input id="mail-composer-attachments" type="file" class="visually-hidden" wire:model="composerAttachments" multiple>
                <span class="text-muted small">Up to 5 draft files, 10 MB each.</span>
            </div>
            @error('composerAttachments')
                <div class="text-danger small mt-1">{{ $message }}</div>
            @enderror
            @error('composerAttachments.*')
                <div class="text-danger small mt-1">{{ $message }}</div>
            @enderror
            @if($composerAttachments)
                <div class="d-flex flex-wrap gap-2 mt-2">
                    @foreach($composerAttachments as $attachment)
                        <span class="badge text-bg-light border d-inline-flex align-items-center gap-2">
                            <span>{{ $attachment->getClientOriginalName() }}</span>
                            <button type="button" class="btn-close btn-close-sm" aria-label="Remove {{ $attachment->getClientOriginalName() }}" wire:click="removeComposerAttachment({{ $loop->iteration }})"></button>
                        </span>
                    @endforeach
                </div>
            @endif
            @if($composerDraftAttachments)
                <div class="d-flex flex-wrap gap-2 mt-2">
                    @foreach($composerDraftAttachments as $attachment)
                        <span class="badge text-bg-secondary d-inline-flex align-items-center gap-2">
                            <i class="bi bi-paperclip" aria-hidden="true"></i>
                            <span>{{ $attachment['filename'] }}</span>
                            <span class="fw-normal opacity-75">{{ $this->composerDraftAttachmentSizeLabel((int) $attachment['size_bytes']) }}</span>
                            <button type="button" class="btn-close btn-close-white btn-close-sm" aria-label="Remove {{ $attachment['filename'] }}" wire:click="removeComposerDraftAttachment({{ $attachment['id'] }})"></button>
                        </span>
                    @endforeach
                </div>
            @endif
        </div>
        <div class="col-12 d-flex flex-wrap justify-content-end gap-2">
            <button type="button" class="btn btn-sm btn-outline-secondary" wire:click="cancelComposer" x-on:click="sync()">Close</button>
            <button type="button" class="btn btn-sm btn-outline-danger" wire:click="discardComposerDraft" onclick="return confirm('Discard this local draft?');">
                <i class="bi bi-trash me-1" aria-hidden="true"></i>Discard draft
            </button>
            <button type="submit" class="btn btn-sm btn-primary" wire:loading.attr="disabled" wire:target="sendComposer,composerAttachments">
                <i class="bi bi-send me-1" aria-hidden="true"></i>Send {{ $composerSubmitLabel }}
            </button>
        </div>
    </div>
</form>
