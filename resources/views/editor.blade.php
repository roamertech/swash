@extends('layouts.base')

@section('title', 'Editor — '.$site->name)

@section('body')
    <div class="editor">
        <header class="editor-bar">
            <div class="editor-bar-left">
                <span class="editor-site-name">{{ $site->name }}</span>
                <a href="{{ url('/') }}" class="view-site-link">View site</a>
            </div>

            <nav class="mode-tabs" role="tablist" aria-label="Workspace mode">
                <button type="button" class="mode-tab" data-mode="site" role="tab" aria-selected="false">Site</button>
                <button type="button" class="mode-tab" data-mode="write" role="tab" aria-selected="false">Write</button>
                <button type="button" class="mode-tab" data-mode="design" role="tab" aria-selected="false">Design</button>
                <button type="button" class="mode-tab" data-mode="publish" role="tab" aria-selected="false">Publish</button>
            </nav>

            <div class="editor-bar-right">
                <span class="webmcp-status" id="webmcp-status">Checking WebMCP…</span>
                <button type="button" id="reset-demo" class="btn-ghost">Reset demo</button>
            </div>
        </header>

        <div class="editor-main">
            <aside class="pane pane-pages">
                <h2>Pages</h2>
                <ul id="page-list">
                    @foreach($pages as $p)
                        @php
                            $pageKind = $p->kind instanceof \BackedEnum ? $p->kind->value : $p->kind;
                            $pageStatus = $p->status instanceof \BackedEnum ? $p->status->value : $p->status;
                        @endphp
                        <li>
                            <button type="button" class="page-item" data-page-id="{{ $p->id }}" data-slug="{{ $p->slug }}">
                                <span class="page-title">{{ $p->title }}</span>
                                <span class="page-meta">{{ $pageKind }} · {{ $pageStatus }}</span>
                            </button>
                        </li>
                    @endforeach
                </ul>
            </aside>

            <section class="pane pane-canvas">
                <div id="canvas" class="canvas" aria-live="polite">
                    <p class="empty">Open a page from the list, or ask the agent to create one.</p>
                </div>
            </section>

            <aside class="pane pane-inspector">
                <h2>Tools in scope</h2>
                <ul id="tool-list" class="tool-list"></ul>
                <p class="hint">The agent only sees the tools listed here. Switching mode replaces the whole set.</p>
            </aside>
        </div>

        <div id="modal-root"></div>
    </div>
@endsection

@push('head')
    <noscript>The editor needs JavaScript to run the WebMCP workspace.</noscript>
@endpush
