<x-filament-panels::page class="fi-assistant-page">
    <div
        x-data="{ runId: null }"
        class="flex h-[calc(100vh-14rem)] min-h-[620px] overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-xs dark:border-gray-800 dark:bg-gray-900"
        dir="rtl"
    >
        <!-- Sidebar -->
        <aside class="flex w-72 md:w-80 shrink-0 flex-col border-l border-gray-200 bg-gray-50/70 dark:border-gray-800 dark:bg-gray-950/40">
            <!-- Sidebar Header & New Chat Button -->
            <div class="p-3 border-b border-gray-200 dark:border-gray-800 flex items-center justify-between gap-2">
                <span class="text-xs font-bold text-gray-700 dark:text-gray-300">سجل المحادثات</span>
                <button
                    type="button"
                    wire:click="newConversation"
                    class="inline-flex items-center gap-1.5 rounded-lg bg-emerald-600 px-3 py-1.5 text-xs font-medium text-white shadow-xs hover:bg-emerald-500 focus:outline-hidden dark:bg-emerald-500 dark:hover:bg-emerald-400 transition"
                >
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                    </svg>
                    <span>محادثة جديدة</span>
                </button>
            </div>

            <!-- Search -->
            <div class="p-2.5 border-b border-gray-200 dark:border-gray-800">
                <div class="relative">
                    <input
                        type="text"
                        wire:model.live.debounce.300ms="search"
                        placeholder="بحث في المحادثات…"
                        class="w-full rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-xs text-gray-900 placeholder:text-gray-400 focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 dark:placeholder:text-gray-500 transition"
                    />
                </div>
            </div>

            <!-- Conversation List -->
            <div class="flex-1 overflow-y-auto p-2 space-y-1">
                @php
                    $conversations = $this->getConversations();
                @endphp

                @forelse($conversations as $conv)
                    <div
                        wire:key="conv-{{ $conv->conversation_id }}"
                        wire:click="selectConversation('{{ $conv->conversation_id }}')"
                        class="group cursor-pointer rounded-xl p-2.5 text-xs transition flex flex-col gap-1 {{ $selectedConversationId === $conv->conversation_id ? 'bg-emerald-50 border border-emerald-200 text-emerald-950 font-medium dark:bg-emerald-950/40 dark:border-emerald-800 dark:text-emerald-200' : 'hover:bg-gray-100 dark:hover:bg-gray-800/60 text-gray-700 dark:text-gray-300' }}"
                    >
                        <div class="flex items-center justify-between gap-1 w-full">
                            <span class="truncate font-medium flex items-center gap-1.5">
                                @if($conv->pinned)
                                    <span class="text-amber-500 shrink-0" title="مثبتة">📌</span>
                                @endif
                                <span>{{ $conv->title ?: 'محادثة بدون عنوان' }}</span>
                            </span>
                            <span class="text-[10px] text-gray-400 dark:text-gray-500 shrink-0">
                                {{ $conv->last_activity_at?->diffForHumans(short: true) }}
                            </span>
                        </div>
                    </div>
                @empty
                    <div class="p-6 text-center text-xs text-gray-400 dark:text-gray-500">
                        لا توجد محادثات سابقة
                    </div>
                @endforelse
            </div>
        </aside>

        <!-- Message Pane -->
        <main class="flex flex-1 flex-col overflow-hidden bg-white dark:bg-gray-900">
            <!-- Chat Header -->
            <div class="border-b border-gray-200 px-4 py-3 dark:border-gray-800 flex items-center justify-between bg-white dark:bg-gray-900">
                <div class="flex items-center gap-2">
                    <div class="h-2 w-2 rounded-full bg-emerald-500"></div>
                    <h2 class="text-xs font-semibold text-gray-800 dark:text-gray-200">
                        @if($selectedConversationId && ($selectedConv = $this->getSelectedConversation()))
                            {{ $selectedConv->title ?: 'محادثة جارية' }}
                        @else
                            محادثة جديدة
                        @endif
                    </h2>
                </div>
                <div class="text-[11px] text-gray-400 dark:text-gray-500">
                    قناة المساعد: محادثة مباشرة
                </div>
            </div>

            <!-- Messages Area -->
            <div class="flex-1 overflow-y-auto p-4 space-y-4">
                <!-- Error Banner -->
                @if($errorMessage)
                    <div class="rounded-xl border border-rose-200 bg-rose-50 p-3 text-xs text-rose-700 dark:border-rose-900/50 dark:bg-rose-950/40 dark:text-rose-300 flex items-center justify-between">
                        <span>{{ $errorMessage }}</span>
                        <button type="button" wire:click="$set('errorMessage', null)" class="text-[11px] font-medium underline hover:no-underline">إغلاق</button>
                    </div>
                @endif

                <!-- Empty Conversation State / Starter Prompts -->
                @if(empty($messages) && empty($streamedAnswer) && ! $isStreaming)
                    <div class="flex h-full flex-col items-center justify-center p-6 text-center my-auto">
                        <div class="mb-3 flex h-12 w-12 items-center justify-center rounded-2xl bg-emerald-50 text-emerald-600 dark:bg-emerald-950/50 dark:text-emerald-400 border border-emerald-100 dark:border-emerald-800/60 shadow-xs">
                            <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
                            </svg>
                        </div>
                        <h3 class="text-sm font-semibold text-gray-800 dark:text-gray-100 mb-1">المساعد الذكي لمنصة المطاعم</h3>
                        <p class="text-xs text-gray-500 dark:text-gray-400 max-w-md mb-6 leading-relaxed">
                            اسأل أي سؤال تحليلي عن مبيعاتك، أرباحك، وتكاليفك باللغة العربية واحصل على إجابة دقيقة وموثوقة مبنية على أرقامك الفعلية.
                        </p>

                        <!-- Starter Prompts Cards -->
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-2.5 w-full max-w-lg">
                            @foreach($this->getStarterPrompts() as $promptOption)
                                <button
                                    type="button"
                                    @click.prevent="runId = crypto.randomUUID(); $wire.useStarterPrompt(@js($promptOption), runId)"
                                    class="text-right p-3 rounded-xl border border-gray-200 bg-gray-50/60 hover:bg-emerald-50/80 hover:border-emerald-300 hover:text-emerald-800 dark:border-gray-800 dark:bg-gray-800/40 dark:hover:bg-emerald-950/30 dark:hover:border-emerald-700 dark:hover:text-emerald-300 transition text-xs font-medium text-gray-700 dark:text-gray-300 shadow-2xs"
                                >
                                    {{ $promptOption }}
                                </button>
                            @endforeach
                        </div>
                    </div>
                @else
                    <!-- Message Bubbles -->
                    @foreach($messages as $msg)
                        @if($msg['role'] === 'user')
                            <div wire:key="msg-{{ $msg['id'] }}" class="flex justify-start">
                                <div class="max-w-[80%] rounded-2xl rounded-tr-xs bg-emerald-600 px-4 py-2.5 text-xs text-white shadow-xs">
                                    <p class="whitespace-pre-wrap leading-relaxed">{{ $msg['content'] }}</p>
                                    @if(!empty($msg['created_at']))
                                        <span class="block mt-1 text-[10px] text-emerald-100 opacity-80 text-left">{{ $msg['created_at'] }}</span>
                                    @endif
                                </div>
                            </div>
                        @else
                            <div wire:key="msg-{{ $msg['id'] }}" class="flex justify-end">
                                <div class="max-w-[85%] rounded-2xl rounded-tl-xs border border-gray-200 bg-gray-50 px-4 py-3 text-xs text-gray-800 shadow-xs dark:border-gray-800 dark:bg-gray-800/70 dark:text-gray-100">
                                    <div class="prose prose-xs dark:prose-invert max-w-none space-y-2 leading-relaxed">
                                        {!! $this->renderMarkdown($msg['content']) !!}
                                    </div>
                                    @if(!empty($msg['created_at']))
                                        <span class="block mt-1 text-[10px] text-gray-400 dark:text-gray-500 text-left">{{ $msg['created_at'] }}</span>
                                    @endif
                                </div>
                            </div>
                        @endif
                    @endforeach

                    <!-- Streaming / In-Progress Answer Container -->
                    @if($isStreaming || !empty($streamedAnswer))
                        <div class="flex justify-end" id="assistant-streaming-container">
                            <div class="max-w-[85%] rounded-2xl rounded-tl-xs border border-emerald-200 bg-emerald-50/40 px-4 py-3 text-xs text-gray-800 shadow-xs dark:border-emerald-900/50 dark:bg-emerald-950/20 dark:text-gray-100">
                                <!-- Tool Status Chip -->
                                <div wire:stream.replace="status" class="mb-2">
                                    @if($toolStatus)
                                        <span class="inline-flex items-center gap-1.5 rounded-full bg-amber-100 px-2.5 py-0.5 text-[11px] font-medium text-amber-800 dark:bg-amber-950/60 dark:text-amber-300 border border-amber-200 dark:border-amber-800">
                                            {{ $toolStatus }}
                                        </span>
                                    @endif
                                </div>

                                <!-- Text Stream -->
                                <div wire:stream="answer" class="prose prose-xs dark:prose-invert max-w-none space-y-2 leading-relaxed">
                                    @if(!empty($streamedAnswer))
                                        {!! $this->renderMarkdown($streamedAnswer) !!}
                                    @endif
                                </div>
                            </div>
                        </div>
                    @endif
                @endif
            </div>

            <!-- Input & Controls Area -->
            <div class="border-t border-gray-200 p-3 bg-white dark:border-gray-800 dark:bg-gray-900">
                <form
                    @submit.prevent="runId = crypto.randomUUID(); $wire.send(runId)"
                    class="flex items-center gap-2"
                >
                    <input
                        type="text"
                        wire:model="prompt"
                        placeholder="اكتب سؤالك هنا…"
                        class="flex-1 rounded-xl border border-gray-300 bg-gray-50 px-3.5 py-2.5 text-xs text-gray-900 placeholder:text-gray-400 focus:border-emerald-500 focus:bg-white focus:outline-hidden focus:ring-1 focus:ring-emerald-500 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-100 dark:placeholder:text-gray-500 dark:focus:bg-gray-900 transition"
                        @if($isStreaming) disabled @endif
                    />

                    <!-- Send Button -->
                    <button
                        type="submit"
                        wire:loading.attr="disabled"
                        class="inline-flex items-center justify-center gap-1 rounded-xl bg-emerald-600 px-4 py-2.5 text-xs font-semibold text-white shadow-xs hover:bg-emerald-500 focus:outline-hidden disabled:opacity-50 dark:bg-emerald-500 dark:hover:bg-emerald-400 transition"
                        @if($isStreaming) disabled @endif
                    >
                        <span>إرسال</span>
                    </button>

                    <!-- Stop Button -->
                    <button
                        type="button"
                        @click.prevent="if (runId) { $wire.stop(runId); }"
                        class="inline-flex items-center justify-center gap-1 rounded-xl border border-rose-300 bg-rose-50 px-3 py-2.5 text-xs font-semibold text-rose-700 hover:bg-rose-100 focus:outline-hidden dark:border-rose-800 dark:bg-rose-950/50 dark:text-rose-300 dark:hover:bg-rose-900/60 transition {{ ! $isStreaming ? 'opacity-40 pointer-events-none' : '' }}"
                        title="إيقاف الإجابة"
                    >
                        <span>إيقاف</span>
                    </button>
                </form>
            </div>
        </main>
    </div>
</x-filament-panels::page>
