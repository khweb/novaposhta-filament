@once
    <style>
        .np-report {
            --np-text: var(--gray-950);
            --np-muted: var(--gray-600);
            --np-border: var(--gray-200);
            --np-surface: var(--gray-50);
            display: grid;
            grid-template-columns: minmax(0, 1fr);
            gap: 1.5rem;
            min-width: 0;
        }
        .dark .np-report {
            --np-text: var(--gray-50);
            --np-muted: var(--gray-400);
            --np-border: var(--gray-800);
            --np-surface: var(--gray-950);
        }
        .np-report .fi-in-entry-label { color: var(--np-muted); font-size: .8125rem; }
        .np-report .np-secondary { color: var(--np-muted); font-size: .8125rem; line-height: 1.6; }
        .np-report-progress {
            position: relative;
            isolation: isolate;
            overflow: hidden;
            border: 1px solid var(--np-border);
            border-radius: .75rem;
            background: var(--np-surface);
            color: var(--np-text);
        }
        .np-report-progress > progress { position: absolute; inset: 0; width: 100%; height: 100%; opacity: 0; }
        .np-report-progress-fill {
            position: absolute;
            inset-block: 0;
            inset-inline-start: 0;
            width: var(--np-progress-width);
            background: color-mix(in oklab, var(--np-progress-color) 20%, transparent);
            border-inline-end: 2px solid var(--np-progress-color);
            z-index: -1;
        }
        .np-report-progress-content {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: .75rem 1.5rem;
            min-height: 5.5rem;
            padding: 1rem 1.25rem;
        }
        .np-report-progress-content strong { display: block; font-size: 1rem; font-weight: 600; line-height: 1.5; }
        .np-report-progress-meta { display: block; margin-top: .25rem; font-size: .8125rem; line-height: 1.6; color: var(--np-muted); }
        .np-report-progress-counter { font-variant-numeric: tabular-nums; white-space: nowrap; }
        .np-report-log { margin: 0; padding: 0; list-style: none; }
        .np-report-event { position: relative; padding: .75rem .875rem; border-bottom: 1px solid var(--np-border); }
        .np-report-event:last-child { border-bottom: 0; }
        .np-report-event[data-level="error"] { background: color-mix(in oklab, var(--danger-500) 6%, transparent); }
        .np-report-event[data-level="warning"] { background: color-mix(in oklab, var(--warning-500) 6%, transparent); }
        .np-report-event-meta { display: flex; flex-wrap: wrap; align-items: center; gap: .25rem .75rem; padding-inline-end: 4.5rem; color: var(--np-muted); font-size: .75rem; line-height: 1.5; }
        .np-report-event-meta time { font-variant-numeric: tabular-nums; }
        .np-report-event-message { margin-top: .25rem; color: var(--np-text); font-size: .875rem; line-height: 1.6; font-weight: 500; overflow-wrap: anywhere; }
        .np-report-event-details > summary {
            position: absolute;
            inset-block-start: .375rem;
            inset-inline-end: .375rem;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: .25rem;
            min-height: 2rem;
            min-width: 4rem;
            padding: .25rem .5rem;
            border-radius: .375rem;
            color: var(--np-muted);
            font-size: .75rem;
            cursor: pointer;
            list-style: none;
        }
        .np-report-event-details > summary::-webkit-details-marker { display: none; }
        .np-report-event-details > summary::after { content: '+'; font-size: 1rem; }
        .np-report-event-details[open] > summary::after { content: '−'; }
        .np-report-event-details > summary:hover { background: var(--np-surface); color: var(--np-text); }
        .np-report-event-details > summary:focus-visible { outline: 2px solid var(--primary-500); outline-offset: 2px; }
        .np-report-event-details dl { display: grid; gap: .375rem; margin-top: .625rem; padding: .625rem .75rem; background: var(--np-surface); border-radius: .5rem; font-size: .75rem; color: var(--np-muted); }
        .np-report-event-details dl > div { display: flex; flex-wrap: wrap; justify-content: space-between; gap: .25rem 1rem; }
        .np-report-event-details dd { color: var(--np-text); font-variant-numeric: tabular-nums; overflow-wrap: anywhere; }
        @media (max-width: 600px) {
            .np-report-progress-content { padding: 1rem; }
            .np-report-progress-content .np-report-progress-counter { font-size: .875rem; }
            .np-report-event { padding: .75rem .5rem; }
            .np-report-event-meta { padding-inline-end: 4.75rem; }
            .np-report-event-details > summary { min-height: 2.75rem; min-width: 4.5rem; inset-block-start: 0; inset-inline-end: 0; }
        }
    </style>
@endonce
