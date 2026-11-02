@php
    use Filament\Support\Icons\Heroicon;

    $conversations = $this->getConversations();
    $selected = $this->getSelectedConversation();
    $readiness = $this->getReadiness();
    $notReady = $readiness !== null && ! $readiness->ready;
    $settingsUrl = $this->getSettingsUrl();
    $groups = [
        'المثبتة' => $conversations->where('pinned', true),
        'الأحدث' => $conversations->where('pinned', false),
    ];
    $starterIcons = [Heroicon::OutlinedArrowTrendingDown, Heroicon::OutlinedListBullet, Heroicon::OutlinedFlag, Heroicon::OutlinedChartBar];
    $setupMessage = match ($readiness?->reason) {
        'missing_key' => 'مزود الذكاء الاصطناعي المختار محتاج مفتاح اتصال.',
        'data_sharing_not_acknowledged' => 'لازم توافق على مشاركة الأرقام المجمعة مع مزود الذكاء الاصطناعي قبل أول سؤال.',
        default => 'لسه ما اتحددش مزود الذكاء الاصطناعي اللي هيجاوب على أسئلتك.',
    };
@endphp

<x-filament-panels::page class="fi-assistant-page">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Readex+Pro:wght@300..700&display=swap" rel="stylesheet">

    {{-- Self-contained styles: this page does not depend on the theme build picking up new utility classes. --}}
    <style>
        .aa-root {
            --aa-canvas: var(--gray-50);
            --aa-surface: #fff;
            --aa-line: var(--gray-200);
            --aa-ink: var(--gray-950);
            --aa-soft: var(--gray-600);
            --aa-faint: var(--gray-400);
            --aa-brand: var(--primary-600);
            --aa-brand-soft: color-mix(in oklab, var(--primary-500) 9%, transparent);
            --aa-ticket-bg: #fff8e8;
            --aa-ticket-line: #e9cf96;
            --aa-ticket-ink: #6f4b12;
            --aa-danger: var(--danger-600);
            --aa-danger-soft: color-mix(in oklab, var(--danger-500) 8%, transparent);

            position: relative;
            display: flex;
            height: calc(100dvh - 6.5rem);
            min-height: 34rem;
            overflow: hidden;
            font-family: 'Readex Pro', ui-sans-serif, system-ui, sans-serif;
            color: var(--aa-ink);
            background: var(--aa-canvas);
            border: 1px solid var(--aa-line);
            border-radius: 0.75rem;
        }

        .dark .aa-root {
            --aa-canvas: var(--gray-950);
            --aa-surface: var(--gray-900);
            --aa-line: color-mix(in oklab, var(--gray-700) 65%, transparent);
            --aa-ink: var(--gray-50);
            --aa-soft: var(--gray-300);
            --aa-faint: var(--gray-500);
            --aa-brand: var(--primary-400);
            --aa-brand-soft: color-mix(in oklab, var(--primary-400) 12%, transparent);
            --aa-ticket-bg: color-mix(in oklab, #f5c451 9%, transparent);
            --aa-ticket-line: color-mix(in oklab, #f5c451 35%, transparent);
            --aa-ticket-ink: #f3d28c;
            --aa-danger: var(--danger-400);
            --aa-danger-soft: color-mix(in oklab, var(--danger-400) 10%, transparent);
        }

        .aa-root *, .aa-root *::before, .aa-root *::after { box-sizing: border-box; }
        .aa-root button { font: inherit; cursor: pointer; }
        .aa-root :focus-visible { outline: 2px solid var(--aa-brand); outline-offset: 2px; }
        .aa-i { width: 1.25rem; height: 1.25rem; flex-shrink: 0; }
        .aa-i-sm { width: 1rem; height: 1rem; flex-shrink: 0; }
        .aa-num { font-variant-numeric: tabular-nums; }
        .aa-sr { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; }

        .aa-icon-btn {
            display: inline-grid; place-items: center; padding: 0.4rem; border: 0; border-radius: 0.5rem;
            color: var(--aa-faint); background: transparent; transition: background 0.15s, color 0.15s;
        }
        .aa-icon-btn:hover { color: var(--aa-ink); background: var(--aa-canvas); }

        /* History */
        .aa-side {
            display: flex; flex-direction: column; width: 17rem; flex-shrink: 0;
            background: var(--aa-surface); border-inline-end: 1px solid var(--aa-line);
        }
        .aa-side-head { display: flex; align-items: center; gap: 0.5rem; padding: 0.75rem; }
        .aa-new {
            flex: 1; display: inline-flex; align-items: center; justify-content: center; gap: 0.5rem;
            padding: 0.55rem 0.75rem; border: 0; border-radius: 0.5rem; font-size: 0.875rem; font-weight: 500;
            color: var(--aa-surface); background: var(--aa-ink); transition: opacity 0.15s;
        }
        .aa-new:hover { opacity: 0.88; }
        .aa-search {
            display: flex; align-items: center; gap: 0.5rem; margin: 0 0.75rem 0.5rem; padding: 0 0.65rem;
            border: 1px solid var(--aa-line); border-radius: 0.5rem; background: var(--aa-canvas); color: var(--aa-faint);
        }
        .aa-search:focus-within { border-color: var(--aa-brand); }
        .aa-search input {
            width: 100%; padding: 0.5rem 0; border: 0; background: transparent; font: inherit; font-size: 0.875rem;
            color: var(--aa-ink); box-shadow: none; outline: none;
        }
        .aa-search input::placeholder { color: var(--aa-faint); }
        .aa-list { flex: 1; overflow-y: auto; padding: 0 0.5rem 0.75rem; scrollbar-width: thin; scrollbar-color: var(--aa-line) transparent; }
        .aa-group { padding: 0.9rem 0.6rem 0.3rem; font-size: 0.75rem; color: var(--aa-faint); }
        .aa-conv-row { position: relative; list-style: none; }
        .aa-conv {
            display: flex; width: 100%; flex-direction: column; align-items: flex-start; gap: 0.1rem;
            padding: 0.5rem 0.65rem; padding-inline-end: 2.25rem; border: 0; border-radius: 0.5rem;
            text-align: start; background: transparent; transition: background 0.15s;
        }
        .aa-conv:hover { background: var(--aa-canvas); }
        .aa-conv.is-active { background: var(--aa-brand-soft); }
        .aa-conv-title {
            display: -webkit-box; -webkit-line-clamp: 1; -webkit-box-orient: vertical; overflow: hidden;
            font-size: 0.875rem; color: var(--aa-soft);
        }
        .aa-conv.is-active .aa-conv-title { color: var(--aa-ink); font-weight: 500; }
        .aa-conv-time { font-size: 0.75rem; color: var(--aa-faint); }
        .aa-pin {
            position: absolute; inset-inline-end: 0.35rem; top: 0.45rem; padding: 0.25rem; border: 0; border-radius: 0.375rem;
            background: transparent; color: var(--aa-faint); opacity: 0; transition: opacity 0.15s;
        }
        .aa-conv-row:hover .aa-pin, .aa-pin:focus-visible, .aa-pin.is-pinned { opacity: 1; }
        .aa-pin.is-pinned { color: var(--aa-brand); }
        .aa-list-empty { padding: 2rem 0.75rem; text-align: center; font-size: 0.875rem; line-height: 1.6rem; color: var(--aa-faint); }
        .aa-scrim { display: none; }
        .aa-only-narrow { display: none; }

        /* Conversation */
        .aa-main { display: flex; min-width: 0; flex: 1; flex-direction: column; }
        .aa-head {
            display: flex; align-items: center; gap: 0.75rem; padding: 0.75rem 1rem;
            background: var(--aa-surface); border-bottom: 1px solid var(--aa-line);
        }
        .aa-head-text { min-width: 0; flex: 1; }
        .aa-head h1 { margin: 0; font-size: 1rem; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .aa-head p { margin: 0.1rem 0 0; font-size: 0.75rem; color: var(--aa-faint); }
        .aa-thread { flex: 1; overflow-y: auto; scrollbar-width: thin; scrollbar-color: var(--aa-line) transparent; }
        .aa-column { display: flex; flex-direction: column; gap: 2rem; width: 100%; max-width: 46rem; margin: 0 auto; padding: 2rem 1.5rem; }

        .aa-hello { display: flex; flex-direction: column; gap: 1.75rem; padding-top: 2.5rem; }
        .aa-hello h2 { margin: 0; font-size: 1.875rem; line-height: 1.3; font-weight: 600; letter-spacing: -0.01em; }
        .aa-lede { margin: 0.75rem 0 0; max-width: 34rem; font-size: 0.9375rem; line-height: 1.95; color: var(--aa-soft); }
        .aa-starters { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 0.5rem; margin: 0; padding: 0; list-style: none; }
        .aa-starter {
            display: flex; width: 100%; align-items: center; gap: 0.75rem; padding: 0.8rem 0.9rem;
            border: 1px solid var(--aa-line); border-radius: 0.6rem; background: var(--aa-surface);
            font-size: 0.875rem; line-height: 1.5; color: var(--aa-ink); text-align: start; transition: border-color 0.15s;
        }
        .aa-starter:hover { border-color: var(--aa-brand); }
        .aa-starter .aa-i { color: var(--aa-brand); }

        .aa-setup { display: flex; max-width: 26rem; margin: 0 auto; flex-direction: column; align-items: center; gap: 0.9rem; padding: 3rem 0; text-align: center; }
        .aa-setup-icon { display: grid; place-items: center; width: 3rem; height: 3rem; border-radius: 9999px; color: var(--aa-brand); background: var(--aa-brand-soft); }
        .aa-setup h2 { margin: 0; font-size: 1.125rem; font-weight: 600; }
        .aa-setup p { margin: 0; font-size: 0.875rem; line-height: 1.8; color: var(--aa-soft); }
        .aa-cta {
            display: inline-block; padding: 0.55rem 1rem; border-radius: 0.5rem; font-size: 0.875rem; font-weight: 500;
            color: #fff; background: var(--aa-brand); text-decoration: none; transition: opacity 0.15s;
        }
        .aa-cta:hover { opacity: 0.9; }

        .aa-q-row { display: flex; justify-content: flex-start; }
        .aa-q {
            max-width: 85%; margin: 0; padding: 0.6rem 1rem; border: 1px solid var(--aa-line);
            border-radius: 1rem; border-start-start-radius: 0.25rem; background: var(--aa-surface);
            font-size: 0.9375rem; line-height: 1.75; color: var(--aa-ink); white-space: pre-wrap;
        }
        .aa-msg { display: flex; flex-direction: column; gap: 0.75rem; }
        .aa-sources { display: flex; flex-wrap: wrap; align-items: center; gap: 0.4rem; }
        .aa-sources-label { font-size: 0.75rem; color: var(--aa-faint); }
        .aa-meta { display: flex; align-items: center; gap: 0.75rem; font-size: 0.75rem; color: var(--aa-faint); }
        .aa-copy {
            display: inline-flex; align-items: center; gap: 0.25rem; padding: 0.1rem 0.25rem; border: 0; border-radius: 0.25rem;
            background: transparent; color: inherit; font-size: 0.75rem; opacity: 0; transition: opacity 0.15s;
        }
        .aa-msg:hover .aa-copy, .aa-copy:focus-visible { opacity: 1; }
        .aa-copy:hover { color: var(--aa-ink); }

        /* Order-ticket chips: which data an answer was built from. */
        .aa-ticket {
            position: relative; display: inline-flex; align-items: center;
            padding: 0.15rem 0.85rem 0.15rem 0.6rem; padding-inline: 0.6rem 0.9rem;
            font-size: 0.75rem; line-height: 1.25rem; color: var(--aa-ticket-ink);
            background: var(--aa-ticket-bg); border: 1px solid var(--aa-ticket-line);
            border-inline-end-style: dashed; border-radius: 0.25rem;
        }
        .aa-ticket::after {
            content: ''; position: absolute; inset-inline-end: 0.3rem; top: 50%; width: 0.25rem; height: 0.25rem;
            margin-top: -0.125rem; border-radius: 9999px; background: var(--aa-ticket-line);
        }

        /* Answers read as a short report. */
        .aa-answer { font-size: 0.9375rem; line-height: 1.9; color: var(--aa-soft); font-variant-numeric: tabular-nums; }
        .aa-answer > :first-child { margin-top: 0; font-size: 1.0625rem; line-height: 1.7; font-weight: 600; color: var(--aa-ink); }
        .aa-answer > :last-child { margin-bottom: 0; }
        .aa-answer p { margin: 0.5rem 0; }
        .aa-answer strong { color: var(--aa-ink); font-weight: 600; }
        .aa-answer h1, .aa-answer h2, .aa-answer h3 { margin: 1.1rem 0 0.3rem; font-size: 1rem; font-weight: 600; color: var(--aa-ink); }
        .aa-answer ul, .aa-answer ol { margin: 0.5rem 0; padding-inline-start: 1.25rem; }
        .aa-answer ul { list-style: disc; }
        .aa-answer ol { list-style: decimal; }
        .aa-answer li { margin: 0.2rem 0; }
        .aa-answer li::marker { color: var(--aa-faint); }
        .aa-answer code { font-size: 0.85em; padding: 0.05rem 0.3rem; border-radius: 0.25rem; background: var(--aa-canvas); }
        .aa-answer a { color: var(--aa-brand); text-decoration: underline; text-underline-offset: 3px; }
        .aa-table { margin: 0.75rem 0; overflow-x: auto; border: 1px solid var(--aa-line); border-radius: 0.5rem; background: var(--aa-surface); }
        .aa-answer table { width: 100%; border-collapse: collapse; font-size: 0.8125rem; }
        .aa-answer th { padding: 0.5rem 0.75rem; text-align: start; font-weight: 500; white-space: nowrap; color: var(--aa-faint); border-bottom: 1px solid var(--aa-line); }
        .aa-answer td { padding: 0.5rem 0.75rem; white-space: nowrap; color: var(--aa-ink); border-bottom: 1px solid var(--aa-line); }
        .aa-answer tr:last-child td { border-bottom: 0; }
        .aa-answer .aa-suggest {
            list-style: none; margin: 0.75rem 0; padding: 0.6rem 0.85rem; color: var(--aa-ink);
            background: var(--aa-brand-soft); border-inline-start: 3px solid var(--aa-brand); border-radius: 0.25rem;
        }
        .aa-stream { white-space: pre-wrap; }

        .aa-live-block { display: none; flex-direction: column; gap: 2rem; }
        .aa-live-row { display: flex; flex-wrap: wrap; align-items: center; gap: 0.45rem; }
        .aa-dot { width: 0.5rem; height: 0.5rem; border-radius: 9999px; background: var(--aa-brand); }
        .aa-status { font-size: 0.75rem; color: var(--aa-soft); }
        .aa-live-sources { display: inline-flex; flex-wrap: wrap; gap: 0.4rem; }

        /* Work log: which specialists worked on the answer and what each found. */
        .aa-log { display: flex; flex-direction: column; gap: 0.5rem; }
        .aa-log [x-cloak] { display: none; }
        .aa-log-toggle {
            display: inline-flex; align-items: center; gap: 0.35rem; align-self: flex-start; padding: 0.2rem 0.6rem;
            border: 1px solid var(--aa-line); border-radius: 9999px; background: var(--aa-surface);
            font-size: 0.75rem; color: var(--aa-soft); transition: color 0.15s, border-color 0.15s;
        }
        .aa-log-toggle:hover { color: var(--aa-ink); border-color: var(--aa-faint); }
        .aa-log-chev { transition: transform 0.15s; }
        .aa-log-chev.is-open { transform: rotate(180deg); }
        .aa-log-body {
            display: flex; flex-direction: column; gap: 0.6rem; padding: 0.75rem 0.9rem;
            border: 1px solid var(--aa-line); border-radius: 0.75rem; background: var(--aa-surface);
        }
        .aa-log-plan { margin: 0; font-size: 0.8125rem; line-height: 1.7; color: var(--aa-soft); }
        .aa-log-agents { display: flex; flex-direction: column; gap: 0.55rem; margin: 0; padding: 0; list-style: none; }
        .aa-log-agent { display: flex; align-items: flex-start; gap: 0.6rem; }
        .aa-log-state {
            display: grid; place-items: center; width: 1.25rem; height: 1.25rem; flex-shrink: 0; margin-top: 0.1rem;
            border-radius: 9999px; font-size: 0.7rem; font-weight: 700; line-height: 1;
        }
        .aa-log-state.is-running { color: var(--aa-brand); background: var(--aa-brand-soft); }
        .aa-log-state.is-done { color: var(--aa-brand); background: var(--aa-brand-soft); }
        .aa-log-state.is-failed { color: var(--aa-danger); background: var(--aa-danger-soft); }
        .aa-spin {
            width: 0.7rem; height: 0.7rem; border-radius: 9999px;
            border: 2px solid color-mix(in oklab, var(--aa-brand) 25%, transparent); border-top-color: var(--aa-brand);
        }
        .aa-log-main { flex: 1; min-width: 0; }
        .aa-log-head { display: flex; flex-wrap: wrap; align-items: baseline; gap: 0.5rem; }
        .aa-log-label { font-size: 0.8125rem; font-weight: 600; color: var(--aa-ink); }
        .aa-log-step { font-size: 0.75rem; color: var(--aa-soft); }
        .aa-log-findings { margin: 0.3rem 0 0; padding-inline-start: 1rem; list-style: disc; font-size: 0.8125rem; line-height: 1.7; color: var(--aa-soft); }
        .aa-log-findings li::marker { color: var(--aa-faint); }

        .aa-alert {
            display: flex; align-items: flex-start; gap: 0.75rem; padding: 0.75rem 1rem; font-size: 0.875rem;
            color: var(--aa-ink); background: var(--aa-danger-soft);
            border: 1px solid color-mix(in oklab, var(--aa-danger) 30%, transparent); border-radius: 0.5rem;
        }
        .aa-alert .aa-i { color: var(--aa-danger); margin-top: 0.15rem; }
        .aa-alert-body { flex: 1; line-height: 1.6; }
        .aa-alert-body p { margin: 0; }
        .aa-alert a { color: var(--aa-danger); text-decoration: underline; text-underline-offset: 4px; }

        /* Composer */
        .aa-compose { padding: 0.5rem 1.5rem 1rem; background: var(--aa-canvas); }
        .aa-form {
            display: flex; align-items: flex-end; gap: 0.5rem; max-width: 46rem; margin: 0 auto; padding: 0.5rem;
            border: 1px solid var(--aa-line); border-radius: 1rem; background: var(--aa-surface);
            box-shadow: 0 1px 2px color-mix(in oklab, var(--gray-950) 6%, transparent); transition: border-color 0.15s;
        }
        .aa-form:focus-within { border-color: var(--aa-brand); }
        .aa-input {
            flex: 1; min-height: 2.5rem; max-height: 11rem; padding: 0.45rem 0.5rem; border: 0; resize: none;
            background: transparent; font: inherit; font-size: 0.9375rem; line-height: 1.75; color: var(--aa-ink);
            box-shadow: none; outline: none;
        }
        .aa-root .aa-input:focus-visible { outline: none; }
        .aa-input::placeholder { color: var(--aa-faint); }
        .aa-input:disabled { opacity: 0.6; }
        .aa-send, .aa-stop {
            display: grid; place-items: center; width: 2.5rem; height: 2.5rem; flex-shrink: 0; border: 0; border-radius: 0.75rem;
            transition: opacity 0.15s;
        }
        .aa-send { color: #fff; background: var(--aa-brand); }
        .aa-send:disabled { opacity: 0.4; cursor: not-allowed; }
        .aa-stop { display: none; color: var(--aa-surface); background: var(--aa-ink); }
        .aa-stop.is-on { display: grid; }
        .aa-send:hover:not(:disabled), .aa-stop:hover { opacity: 0.88; }
        .aa-stop-square { width: 0.75rem; height: 0.75rem; border-radius: 0.15rem; background: currentColor; }
        .aa-hint { max-width: 46rem; margin: 0.5rem auto 0; text-align: center; font-size: 0.75rem; color: var(--aa-faint); }

        @media (prefers-reduced-motion: no-preference) {
            .aa-dot { animation: aa-pulse 1.4s ease-in-out infinite; }
            .aa-spin { animation: aa-spin 0.8s linear infinite; }
            @keyframes aa-spin { to { transform: rotate(360deg); } }
            .aa-side { transition: transform 0.2s ease; }
            @keyframes aa-pulse { 50% { opacity: 0.3; } }
        }

        @media (min-width: 1024px) {
            .aa-root { height: calc(100dvh - 8rem); }
        }

        /* Layout follows the space the page actually gets (the app sidebar takes a lot), not the window. */
        .aa-root { container: aa / inline-size; }
        .aa-main { container: aamain / inline-size; }

        @container aa (max-width: 880px) {
            .aa-side { position: absolute; inset-block: 0; inset-inline-start: 0; z-index: 30; transform: translateX(100%); }
            .aa-side.is-open { transform: translateX(0); box-shadow: 0 10px 30px rgb(0 0 0 / 0.25); }
            .aa-scrim { position: absolute; inset: 0; z-index: 20; background: rgb(3 7 18 / 0.45); }
            .aa-scrim.is-open { display: block; }
            .aa-only-narrow { display: inline-grid; }
        }

        @container aamain (max-width: 600px) {
            .aa-column { padding: 1.25rem 1rem; gap: 1.5rem; }
            .aa-compose { padding: 0.5rem 0.75rem 0.75rem; }
            .aa-hello { padding-top: 0.5rem; gap: 1.25rem; }
            .aa-hello h2 { font-size: 1.5rem; }
            .aa-starters { grid-template-columns: 1fr; }
            .aa-hint { display: none; }
        }
    </style>

    <div
        dir="rtl"
        x-data="{
            runId: null,
            pendingQuestion: '',
            historyOpen: false,
            copiedId: null,
            submit() {
                const text = ($wire.prompt || '').trim();
                if (! text || $wire.isStreaming) return;
                this.pendingQuestion = text;
                this.runId = crypto.randomUUID();
                $wire.send(this.runId);
                this.$nextTick(() => this.resize());
            },
            ask(prompt) {
                this.pendingQuestion = prompt;
                this.runId = crypto.randomUUID();
                $wire.useStarterPrompt(prompt, this.runId);
            },
            stop() {
                // The server knows the current run, also one resumed after a reload.
                $wire.stop();
            },
            resize() {
                const el = this.$refs.composer;
                if (! el) return;
                el.style.height = 'auto';
                el.style.height = Math.min(el.scrollHeight, 176) + 'px';
            },
            toBottom() {
                const el = this.$refs.thread;
                if (el) el.scrollTop = el.scrollHeight;
            },
            copy(id, text) {
                navigator.clipboard?.writeText(text);
                this.copiedId = id;
                setTimeout(() => { if (this.copiedId === id) this.copiedId = null }, 1600);
            },
        }"
        x-init="
            $nextTick(() => toBottom());
            new MutationObserver(() => toBottom()).observe($refs.thread, { childList: true, subtree: true, characterData: true });
        "
        x-on:keydown.escape="historyOpen = false"
        class="aa-root"
    >
        <div class="aa-scrim" x-bind:class="{ 'is-open': historyOpen }" x-on:click="historyOpen = false"></div>

        {{-- History --}}
        <aside class="aa-side" x-bind:class="{ 'is-open': historyOpen }" aria-label="المحادثات">
            <div class="aa-side-head">
                <button type="button" wire:click="newConversation" x-on:click="historyOpen = false" class="aa-new">
                    <x-filament::icon :icon="Heroicon::OutlinedPencilSquare" class="aa-i-sm" />
                    سؤال جديد
                </button>
                <button type="button" x-on:click="historyOpen = false" class="aa-icon-btn aa-only-narrow" aria-label="إغلاق المحادثات">
                    <x-filament::icon :icon="Heroicon::OutlinedXMark" class="aa-i" />
                </button>
            </div>

            <label class="aa-search">
                <span class="aa-sr">ابحث في المحادثات</span>
                <x-filament::icon :icon="Heroicon::OutlinedMagnifyingGlass" class="aa-i-sm" />
                <input type="search" wire:model.live.debounce.300ms="search" placeholder="ابحث في المحادثات">
            </label>

            <nav class="aa-list">
                @foreach ($groups as $groupLabel => $group)
                    @if ($group->isNotEmpty())
                        <p class="aa-group">{{ $groupLabel }}</p>
                        <ul style="margin: 0; padding: 0;">
                            @foreach ($group as $conv)
                                @php $isActive = $conv->conversation_id === $selectedConversationId; @endphp
                                <li wire:key="conv-{{ $conv->conversation_id }}" class="aa-conv-row">
                                    <button
                                        type="button"
                                        wire:click="selectConversation('{{ $conv->conversation_id }}')"
                                        x-on:click="historyOpen = false"
                                        @class(['aa-conv', 'is-active' => $isActive])
                                        @if ($isActive) aria-current="true" @endif
                                    >
                                        <span class="aa-conv-title">{{ $conv->title ?: 'محادثة بدون عنوان' }}</span>
                                        <span class="aa-conv-time">{{ $conv->last_activity_at?->diffForHumans() }}</span>
                                    </button>
                                    <button
                                        type="button"
                                        wire:click="togglePin('{{ $conv->conversation_id }}')"
                                        @class(['aa-pin', 'is-pinned' => $conv->pinned])
                                        aria-label="{{ $conv->pinned ? 'إلغاء التثبيت' : 'تثبيت المحادثة' }}"
                                    >
                                        <x-filament::icon :icon="$conv->pinned ? Heroicon::Bookmark : Heroicon::OutlinedBookmark" class="aa-i-sm" />
                                    </button>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                @endforeach

                @if ($conversations->isEmpty())
                    <p class="aa-list-empty">
                        {{ filled($search) ? 'مفيش محادثات بالاسم ده.' : 'أسئلتك هتتحفظ هنا عشان ترجع لها.' }}
                    </p>
                @endif
            </nav>
        </aside>

        {{-- Conversation --}}
        <section class="aa-main">
            <header class="aa-head">
                <button type="button" x-on:click="historyOpen = true" class="aa-icon-btn aa-only-narrow" aria-label="عرض المحادثات">
                    <x-filament::icon :icon="Heroicon::OutlinedBars3" class="aa-i" />
                </button>
                <div class="aa-head-text">
                    <h1>{{ $selected?->title ?: 'المساعد الذكي' }}</h1>
                    <p>بيجاوب من بيانات الفروع المتاحة لك بس</p>
                </div>
                @if ($settingsUrl)
                    <a href="{{ $settingsUrl }}" class="aa-icon-btn" aria-label="إعدادات المساعد" title="إعدادات المساعد">
                        <x-filament::icon :icon="Heroicon::OutlinedCog6Tooth" class="aa-i" />
                    </a>
                @endif
            </header>

            <div x-ref="thread" class="aa-thread">
                <div class="aa-column">
                    @if ($notReady && empty($messages))
                        <div class="aa-setup">
                            <span class="aa-setup-icon">
                                <x-filament::icon :icon="Heroicon::OutlinedWrenchScrewdriver" class="aa-i" />
                            </span>
                            <h2>المساعد محتاج يتضبط الأول</h2>
                            <p>{{ $setupMessage }}</p>
                            @if ($settingsUrl)
                                <a href="{{ $settingsUrl }}" class="aa-cta">اضبط المساعد</a>
                            @else
                                <p>اطلب من مالك الحساب يضبطه من صفحة إعدادات المساعد الذكي.</p>
                            @endif
                        </div>
                    @elseif (empty($messages) && blank($streamedAnswer))
                        <div class="aa-hello" wire:loading.remove wire:target="send,useStarterPrompt">
                            <div>
                                <h2>{{ $this->getGreeting() }}</h2>
                                <p class="aa-lede">
                                    اسأل عن المبيعات والأرباح والأصناف والمستهدفات. كل رقم في الإجابة بيتحسب من بيانات فروعك، ومكتوب تحته اتجاب منين.
                                </p>
                            </div>
                            <ul class="aa-starters">
                                @foreach ($this->getStarterPrompts() as $i => $promptOption)
                                    <li>
                                        <button type="button" x-on:click="ask(@js($promptOption))" class="aa-starter">
                                            <x-filament::icon :icon="$starterIcons[$i % count($starterIcons)]" class="aa-i" />
                                            <span>{{ $promptOption }}</span>
                                        </button>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    @foreach ($messages as $msg)
                        @if ($msg['role'] === 'user')
                            <div wire:key="msg-{{ $msg['id'] }}" class="aa-q-row">
                                <p class="aa-q aa-num">{{ $msg['content'] }}</p>
                            </div>
                        @else
                            <article wire:key="msg-{{ $msg['id'] }}" class="aa-msg">
                                @if (! empty($msg['workLog']))
                                    @include('filament.app.assistant.work-log', ['log' => $msg['workLog'], 'collapsed' => true])
                                @endif
                                @if (! empty($msg['sources']))
                                    <div class="aa-sources">
                                        <span class="aa-sources-label">اعتمدت على</span>
                                        @foreach ($msg['sources'] as $source)
                                            <span class="aa-ticket">{{ $source }}</span>
                                        @endforeach
                                    </div>
                                @endif
                                <div class="aa-answer">{!! $this->renderMarkdown($msg['content']) !!}</div>
                                <div class="aa-meta">
                                    <span class="aa-num">{{ $msg['created_at'] }}</span>
                                    <button type="button" class="aa-copy" x-on:click="copy(@js($msg['id']), @js($msg['content']))">
                                        <x-filament::icon :icon="Heroicon::OutlinedClipboardDocument" class="aa-i-sm" />
                                        <span x-text="copiedId === @js($msg['id']) ? 'اتنسخت' : 'نسخ'">نسخ</span>
                                    </button>
                                </div>
                            </article>
                        @endif
                    @endforeach

                    {{-- The current run, read from its log every second; also an answer that was never stored (e.g. stopped early). --}}
                    @if ($isStreaming || filled($streamedAnswer) || ! empty($workLog['agents']) || filled($workLog['plan']))
                        <article class="aa-msg" aria-live="polite" wire:key="live-{{ $currentRunId }}">
                            @if ($isStreaming)
                                <div wire:poll.1s="poll" wire:key="poll-{{ $currentRunId }}"></div>
                            @endif

                            <div wire:key="live-log-{{ filled($streamedAnswer) ? 'collapsed' : 'open' }}">
                                @include('filament.app.assistant.work-log', ['log' => $workLog, 'collapsed' => filled($streamedAnswer)])
                            </div>

                            @if ($isStreaming)
                                <div class="aa-live-row">
                                    <span class="aa-dot"></span>
                                    <span class="aa-status">{{ $toolStatus ?? (filled($streamedAnswer) ? 'بكتب الإجابة…' : 'بقرأ سؤالك…') }}</span>
                                    <span class="aa-live-sources">
                                        @foreach ($liveSources as $source)
                                            <span class="aa-ticket">{{ $source }}</span>
                                        @endforeach
                                    </span>
                                </div>
                            @endif

                            @if (filled($streamedAnswer))
                                <div class="aa-answer">{!! $this->renderMarkdown($streamedAnswer) !!}</div>
                            @endif
                        </article>
                    @endif

                    {{-- While the question is being sent: show it before the server answers. --}}
                    <div class="aa-live-block" wire:loading.flex wire:target="send,useStarterPrompt">
                        <div class="aa-q-row">
                            <p class="aa-q aa-num" x-text="pendingQuestion"></p>
                        </div>
                        <article class="aa-msg">
                            <div class="aa-live-row">
                                <span class="aa-dot"></span>
                                <span class="aa-status">بقرأ سؤالك…</span>
                            </div>
                        </article>
                    </div>

                    @if ($errorMessage)
                        <div role="alert" class="aa-alert">
                            <x-filament::icon :icon="Heroicon::OutlinedExclamationTriangle" class="aa-i" />
                            <div class="aa-alert-body">
                                <p>{{ $errorMessage }}</p>
                                @if (in_array($errorCode, ['provider_error', 'provider_auth'], true) && $settingsUrl)
                                    <a href="{{ $settingsUrl }}">راجع إعدادات المزود</a>
                                @endif
                            </div>
                            <button type="button" wire:click="$set('errorMessage', null)" class="aa-icon-btn" aria-label="إخفاء الرسالة">
                                <x-filament::icon :icon="Heroicon::OutlinedXMark" class="aa-i-sm" />
                            </button>
                        </div>
                    @endif
                </div>
            </div>

            {{-- Composer --}}
            <div class="aa-compose">
                <form class="aa-form" x-on:submit.prevent="submit()">
                    <label for="aa-composer" class="aa-sr">سؤالك</label>
                    <textarea
                        id="aa-composer"
                        x-ref="composer"
                        wire:model="prompt"
                        x-on:input="resize()"
                        x-on:keydown.enter="if (! $event.shiftKey && ! $event.isComposing) { $event.preventDefault(); submit(); }"
                        rows="1"
                        placeholder="{{ $notReady ? 'المساعد مش متاح لحد ما يتضبط' : 'اسأل عن فروعك…' }}"
                        @disabled($notReady || $isStreaming)
                        wire:loading.attr="disabled"
                        wire:target="send,useStarterPrompt"
                        class="aa-input aa-num"
                    ></textarea>

                    @if ($isStreaming)
                        <button type="button" class="aa-stop is-on" x-on:click="stop()" aria-label="إيقاف الإجابة" title="إيقاف">
                            <span class="aa-stop-square"></span>
                        </button>
                    @else
                        <button type="submit" class="aa-send" wire:loading.remove wire:target="send,useStarterPrompt" @disabled($notReady) aria-label="إرسال">
                            <x-filament::icon :icon="Heroicon::OutlinedArrowUp" class="aa-i" />
                        </button>
                        <button type="button" class="aa-stop" wire:loading.grid wire:target="send,useStarterPrompt" x-on:click="stop()" aria-label="إيقاف الإجابة" title="إيقاف">
                            <span class="aa-stop-square"></span>
                        </button>
                    @endif
                </form>
                <p class="aa-hint">Enter للإرسال، وShift+Enter لسطر جديد. راجع الأرقام المهمة قبل ما تاخد قرار.</p>
            </div>
        </section>
    </div>
</x-filament-panels::page>
