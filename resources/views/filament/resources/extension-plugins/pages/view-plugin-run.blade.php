<x-filament-panels::page>
    @php
        /** @var \App\Models\ExtensionPluginRun $run */
        $run = $this->runRecord;
        $statusColors = [
            'completed' => 'bg-success-50 text-success-700 ring-success-200 dark:bg-success-950/40 dark:text-success-300 dark:ring-success-800',
            'failed' => 'bg-danger-50 text-danger-700 ring-danger-200 dark:bg-danger-950/40 dark:text-danger-300 dark:ring-danger-800',
            'running' => 'bg-warning-50 text-warning-700 ring-warning-200 dark:bg-warning-950/40 dark:text-warning-300 dark:ring-warning-800',
        ];
        $statusClass = $statusColors[$run->status] ?? 'bg-gray-50 text-gray-700 ring-gray-200 dark:bg-gray-900 dark:text-gray-300 dark:ring-gray-800';
        $payload = json_encode($run->payload ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $result = json_encode($run->result ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $latestMessage = $this->logs->last()?->message;
    @endphp

    <div class="space-y-6">
        <section class="overflow-hidden rounded-3xl border border-gray-200/80 bg-gradient-to-br from-white via-primary-50/20 to-white shadow-sm dark:border-gray-800 dark:from-gray-950 dark:via-primary-950/20 dark:to-gray-950">
            <div class="grid gap-6 px-6 py-6 lg:grid-cols-[minmax(0,1.2fr)_minmax(280px,0.8fr)] lg:px-8">
                <div class="space-y-4">
                    <div class="flex flex-wrap items-center gap-3">
                        <span class="inline-flex items-center rounded-full px-3 py-1 text-sm font-medium ring-1 ring-inset {{ $statusClass }}">
                            {{ \Illuminate\Support\Str::headline($run->status) }}
                        </span>
                        @if($run->dry_run)
                            <span class="inline-flex items-center rounded-full bg-primary-50 px-3 py-1 text-sm font-medium text-primary-700 ring-1 ring-inset ring-primary-200 dark:bg-primary-950/40 dark:text-primary-300 dark:ring-primary-800">
                                Dry run
                            </span>
                        @endif
                        <span class="inline-flex items-center rounded-full bg-gray-100 px-3 py-1 text-sm font-medium text-gray-700 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:text-gray-300 dark:ring-gray-800">
                            {{ \Illuminate\Support\Str::headline($run->trigger) }}
                        </span>
                    </div>

                    <div>
                        <p class="text-sm font-medium uppercase tracking-[0.24em] text-primary-600 dark:text-primary-300">Plugin run detail</p>
                        <h2 class="mt-2 text-2xl font-semibold tracking-tight text-gray-950 dark:text-white">
                            {{ $run->plugin?->name ?? 'Unknown plugin' }}
                        </h2>
                        <p class="mt-2 max-w-2xl text-sm leading-6 text-gray-600 dark:text-gray-300">
                            {{ $run->summary ?: 'This run is active, but no summary has been written yet. Use the activity stream below to inspect each step as it happens.' }}
                        </p>
                    </div>

                    <div class="grid gap-3 sm:grid-cols-3">
                        <div class="rounded-2xl border border-gray-200 bg-white/80 p-4 backdrop-blur dark:border-gray-800 dark:bg-gray-900/80">
                            <div class="text-[11px] font-semibold uppercase tracking-[0.18em] text-gray-500 dark:text-gray-400">Operator action</div>
                            <div class="mt-2 text-sm font-medium text-gray-950 dark:text-white">{{ $run->action ? \Illuminate\Support\Str::headline($run->action) : 'Hook-driven run' }}</div>
                            <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $run->hook ? 'Triggered by '.\Illuminate\Support\Str::headline($run->hook) : 'Manually queued from the plugin page' }}</div>
                        </div>
                        <div class="rounded-2xl border border-gray-200 bg-white/80 p-4 backdrop-blur dark:border-gray-800 dark:bg-gray-900/80">
                            <div class="text-[11px] font-semibold uppercase tracking-[0.18em] text-gray-500 dark:text-gray-400">Current signal</div>
                            <div class="mt-2 text-sm font-medium text-gray-950 dark:text-white">{{ $latestMessage ?: 'Waiting for activity…' }}</div>
                            <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">The latest emitted activity line from this job.</div>
                        </div>
                        <div class="rounded-2xl border border-gray-200 bg-white/80 p-4 backdrop-blur dark:border-gray-800 dark:bg-gray-900/80">
                            <div class="text-[11px] font-semibold uppercase tracking-[0.18em] text-gray-500 dark:text-gray-400">Queued by</div>
                            <div class="mt-2 text-sm font-medium text-gray-950 dark:text-white">{{ $run->user?->name ?? 'System' }}</div>
                            <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ optional($run->created_at)->toDateTimeString() ?: 'Unknown time' }}</div>
                        </div>
                    </div>
                </div>

                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-1">
                    <div class="rounded-2xl border border-gray-200 bg-white/90 p-5 shadow-xs dark:border-gray-800 dark:bg-gray-900/90">
                        <div class="text-[11px] font-semibold uppercase tracking-[0.18em] text-gray-500 dark:text-gray-400">Lifecycle</div>
                        <dl class="mt-4 space-y-3 text-sm text-gray-600 dark:text-gray-300">
                            <div>
                                <dt class="text-xs uppercase tracking-wide text-gray-400 dark:text-gray-500">Queued</dt>
                                <dd class="mt-1 font-medium text-gray-950 dark:text-white">{{ optional($run->created_at)->toDateTimeString() }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs uppercase tracking-wide text-gray-400 dark:text-gray-500">Started</dt>
                                <dd class="mt-1 font-medium text-gray-950 dark:text-white">{{ optional($run->started_at)->toDateTimeString() ?? 'Not started' }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs uppercase tracking-wide text-gray-400 dark:text-gray-500">Finished</dt>
                                <dd class="mt-1 font-medium text-gray-950 dark:text-white">{{ optional($run->finished_at)->toDateTimeString() ?? 'Still running' }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs uppercase tracking-wide text-gray-400 dark:text-gray-500">Invocation</dt>
                                <dd class="mt-1 font-medium text-gray-950 dark:text-white">{{ \Illuminate\Support\Str::headline($run->invocation_type) }}</dd>
                            </div>
                        </dl>
                    </div>

                    <div class="rounded-2xl border border-gray-200 bg-white/90 p-5 shadow-xs dark:border-gray-800 dark:bg-gray-900/90">
                        <div class="text-[11px] font-semibold uppercase tracking-[0.18em] text-gray-500 dark:text-gray-400">What to inspect</div>
                        <ul class="mt-4 space-y-2 text-sm leading-6 text-gray-600 dark:text-gray-300">
                            <li>Watch the activity stream for progress and warnings.</li>
                            <li>Use the payload snapshot to confirm which playlist and EPG were targeted.</li>
                            <li>Use the result snapshot to verify what the plugin actually changed.</li>
                        </ul>
                    </div>
                </div>
            </div>
        </section>

        <div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_380px]">
            <section class="overflow-hidden rounded-3xl border border-gray-200 bg-white shadow-sm dark:border-gray-800 dark:bg-gray-900">
                <div class="border-b border-gray-200 px-6 py-5 dark:border-gray-800">
                    <h2 class="text-sm font-semibold text-gray-950 dark:text-white">Activity stream</h2>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">This is the actual job trail. Each line is recorded while the plugin is executing.</p>
                </div>
                <div class="divide-y divide-gray-100 dark:divide-gray-800/80">
                    @forelse($this->logs as $log)
                        <article class="grid gap-4 px-6 py-4 lg:grid-cols-[170px_110px_minmax(0,1fr)]">
                            <div class="text-sm text-gray-500 dark:text-gray-400">
                                {{ optional($log->created_at)->toDateTimeString() }}
                            </div>
                            <div>
                                <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-medium ring-1 ring-inset {{ $log->level === 'error' ? 'bg-danger-50 text-danger-700 ring-danger-200 dark:bg-danger-950/40 dark:text-danger-300 dark:ring-danger-800' : ($log->level === 'warning' ? 'bg-warning-50 text-warning-700 ring-warning-200 dark:bg-warning-950/40 dark:text-warning-300 dark:ring-warning-800' : 'bg-info-50 text-info-700 ring-info-200 dark:bg-info-950/40 dark:text-info-300 dark:ring-info-800') }}">
                                    {{ \Illuminate\Support\Str::headline($log->level) }}
                                </span>
                            </div>
                            <div class="space-y-2">
                                <div class="text-sm font-medium text-gray-950 dark:text-white">{{ $log->message }}</div>
                                @if(! empty($log->context))
                                    <dl class="grid gap-2 rounded-2xl bg-gray-50 p-3 text-xs text-gray-600 dark:bg-gray-950/60 dark:text-gray-300 sm:grid-cols-2">
                                        @foreach(collect($log->context)->take(8) as $key => $value)
                                            <div>
                                                <dt class="font-semibold text-gray-700 dark:text-gray-200">{{ $key }}</dt>
                                                <dd class="mt-1 break-words">{{ is_scalar($value) || $value === null ? json_encode($value) : '[…]' }}</dd>
                                            </div>
                                        @endforeach
                                    </dl>
                                @endif
                            </div>
                        </article>
                    @empty
                        <div class="px-6 py-14 text-center text-sm text-gray-500 dark:text-gray-400">
                            No activity has been recorded for this run yet.
                        </div>
                    @endforelse
                </div>
            </section>

            <div class="space-y-6">
                <section class="rounded-3xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-800 dark:bg-gray-900">
                    <h2 class="text-sm font-semibold text-gray-950 dark:text-white">Request payload</h2>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">The exact arguments that queued this run.</p>
                    <pre class="mt-4 overflow-x-auto rounded-2xl bg-gray-950 px-4 py-4 text-xs leading-6 text-gray-100">{{ $payload !== false ? $payload : '{}' }}</pre>
                </section>

                <section class="rounded-3xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-800 dark:bg-gray-900">
                    <h2 class="text-sm font-semibold text-gray-950 dark:text-white">Result snapshot</h2>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Structured output saved by the plugin when the run finished.</p>
                    <pre class="mt-4 overflow-x-auto rounded-2xl bg-gray-950 px-4 py-4 text-xs leading-6 text-gray-100">{{ $result !== false ? $result : '{}' }}</pre>
                </section>
            </div>
        </div>
    </div>
</x-filament-panels::page>
