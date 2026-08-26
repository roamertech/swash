@extends('layouts.base')

@section('title', $page->title)

@section('body')
    <header class="site-header">
        <a href="{{ url('/') }}" class="site-name">{{ $site->name }}</a>

        <nav class="site-nav" aria-label="Site navigation">
            @foreach(($site->nav['items'] ?? []) as $item)
                @php
                    $navLabel = data_get($item, 'label', data_get($item, 'title', ''));
                    $navSlug = data_get($item, 'slug', '');
                @endphp

                @if($navLabel && $navSlug)
                    <a
                        href="{{ url('/p/'.$navSlug) }}"
                        @if($page->slug === $navSlug) aria-current="page" @endif
                    >{{ $navLabel }}</a>
                @endif
            @endforeach
        </nav>

        <a href="{{ url('/editor') }}" class="editor-link">Open editor</a>
    </header>

    <main class="page">
        @foreach($page->blocks as $b)
            @php
                $blockType = $b->type instanceof \BackedEnum ? $b->type->value : $b->type;
            @endphp

            @switch($blockType)
                @case('heading')
                    <h1 class="block-heading" data-block-id="{{ $b->id }}">{{ $b->content }}</h1>
                    @break

                @case('paragraph')
                    @php
                        $paragraphContent = e($b->content);
                        $paragraphContent = preg_replace_callback(
                            '/\[([^\[\]]+)\]\((\/p\/[a-z0-9\-\/_]+)\)/i',
                            static function (array $matches): string {
                                return '<a href="'.$matches[2].'">'.$matches[1].'</a>';
                            },
                            $paragraphContent
                        );
                    @endphp
                    <p class="block-paragraph" data-block-id="{{ $b->id }}">{!! $paragraphContent !!}</p>
                    @break

                @case('image')
                    <figure class="block-image" data-block-id="{{ $b->id }}">
                        <img src="{{ $b->asset?->path }}" alt="{{ $b->asset?->alt ?? '' }}" loading="lazy">
                        @if(trim((string) $b->content) !== '')
                            <figcaption>{{ $b->content }}</figcaption>
                        @endif
                    </figure>
                    @break

                @case('quote')
                    <blockquote class="block-quote" data-block-id="{{ $b->id }}">{{ $b->content }}</blockquote>
                    @break

                @case('list')
                    <ul class="block-list" data-block-id="{{ $b->id }}">
                        @foreach(preg_split('/\r?\n/', (string) $b->content) as $listItem)
                            @if(trim($listItem) !== '')
                                <li>{{ $listItem }}</li>
                            @endif
                        @endforeach
                    </ul>
                    @break

                @case('code')
                    <pre class="block-code" data-block-id="{{ $b->id }}"><code>{{ $b->content }}</code></pre>
                    @break

                @case('divider')
                    <hr class="block-divider" data-block-id="{{ $b->id }}">
                    @break
            @endswitch
        @endforeach

        @php
            $pageKind = $page->kind instanceof \BackedEnum ? $page->kind->value : $page->kind;
        @endphp

        @if($pageKind === 'post')
            <section class="submissions">
                @forelse($page->submissions ?? [] as $submission)
                    <article class="submission">
                        <p class="submission-meta">{{ $submission->submitter_name ?? $submission->name ?? 'Anonymous' }}</p>
                        <p class="submission-body">{{ $submission->body }}</p>
                    </article>
                @empty
                    <p class="empty">No notes yet.</p>
                @endforelse

                @if(session('status'))
                    <p class="status">{{ session('status') }}</p>
                @endif

                <form method="POST" action="{{ route('submissions.store', $page) }}" class="submission-form"
                      toolname="submit_reader_note"
                      tooldescription="Submit a short note or correction about this article as a reader.">
                    @csrf
                    <label for="submitter_name">Your name</label>
                    <input id="submitter_name" type="text" name="submitter_name" maxlength="64"
                           toolparamdescription="The display name to attach to the note.">
                    <label for="body">Your note</label>
                    <textarea id="body" name="body" required maxlength="2000"
                              toolparamdescription="The note itself, in plain text."></textarea>
                    <button type="submit">Send note</button>
                </form>
            </section>
        @endif
    </main>

    <footer class="site-footer">
        <p>{{ $site->name }} · Built with WebMCP</p>
    </footer>
@endsection
