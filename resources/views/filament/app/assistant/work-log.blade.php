{{--
    Who worked on an answer and what they found. Expanded while the agents work; once answer text
    exists it collapses to one line («استعان بـ: … · N خطوات») that opens on click.
    Expects: $log (plan + agents), $collapsed (bool).
--}}
@if (! empty($log['agents']) || filled($log['plan']))
    <div class="aa-log" x-data="{ open: @js(! $collapsed) }">
        @if ($collapsed)
            <button type="button" class="aa-log-toggle" x-on:click="open = ! open" x-bind:aria-expanded="open.toString()">
                <span class="aa-log-sum aa-num">{{ $this->workLogSummary($log) }}</span>
                <x-filament::icon :icon="\Filament\Support\Icons\Heroicon::OutlinedChevronDown" class="aa-i-sm aa-log-chev" x-bind:class="{ 'is-open': open }" />
            </button>
        @endif

        <div class="aa-log-body" x-show="open" @if ($collapsed) x-cloak @endif>
            @if (filled($log['plan']))
                <p class="aa-log-plan">{{ $log['plan'] }}</p>
            @endif

            <ol class="aa-log-agents">
                @foreach ($log['agents'] as $agent)
                    <li class="aa-log-agent" wire:key="log-{{ $agent['agent'] }}">
                        <span class="aa-log-state is-{{ $agent['status'] }}" aria-hidden="true">
                            @if ($agent['status'] === 'running')
                                <span class="aa-spin"></span>
                            @elseif ($agent['status'] === 'done')
                                ✓
                            @else
                                ✗
                            @endif
                        </span>
                        <div class="aa-log-main">
                            <div class="aa-log-head">
                                <span class="aa-log-label">{{ $agent['label'] }}</span>
                                <span class="aa-sr">
                                    {{ match ($agent['status']) { 'running' => 'شغال', 'done' => 'خلص', default => 'ما كملش' } }}
                                </span>
                                @if (filled($agent['step']))
                                    <span class="aa-log-step">{{ $agent['step'] }}</span>
                                @endif
                            </div>
                            @if (! empty($agent['findings']))
                                <ul class="aa-log-findings aa-num">
                                    @foreach ($agent['findings'] as $finding)
                                        <li>{{ $finding }}</li>
                                    @endforeach
                                </ul>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ol>
        </div>
    </div>
@endif
