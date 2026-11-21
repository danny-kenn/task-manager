{{-- Floating AI assistant. Same widget on every page; history is loaded the first time it opens. --}}
<button class="ai-fab" id="aiFab" title="AI assistant" aria-label="Open AI assistant"><i class="fas fa-wand-magic-sparkles"></i></button>
<section class="ai-panel" id="aiPanel" aria-label="AI assistant" data-history="{{ route('ai.history') }}" data-chat="{{ route('ai.chat') }}" data-exec="{{ route('ai.execute') }}" data-clear="{{ route('ai.clear') }}">
  <div class="ai-head">
    <span class="avatar" style="background:linear-gradient(135deg,var(--teal),var(--gold))"><i class="fas fa-wand-magic-sparkles"></i></span>
    <div class="t">Task Assistant <small class="text-muted" id="aiMode">connecting…</small></div>
    <button class="icon-btn" id="aiClear" title="Clear conversation"><i class="fas fa-broom"></i></button>
    <button class="icon-btn" id="aiWide" title="Full screen"><i class="fas fa-expand"></i></button>
    <button class="icon-btn" id="aiClose" title="Close"><i class="fas fa-xmark"></i></button>
  </div>
  <div class="ai-log" id="aiLog" role="log" aria-live="polite"></div>
  <div class="chips" id="aiChips"></div>
  <form class="ai-form" id="aiForm" autocomplete="off">
    <textarea id="aiInput" class="form-control" rows="1" maxlength="1000" placeholder="Ask, or say “add task call the bank tomorrow”…" aria-label="Message"></textarea>
    <button class="btn btn-primary" type="submit" id="aiSend" aria-label="Send"><i class="fas fa-paper-plane"></i></button>
  </form>
</section>
@push('scripts')<script src="{{ asset('js/ai.js') }}?v={{ filemtime(public_path('js/ai.js')) }}"></script>@endpush
