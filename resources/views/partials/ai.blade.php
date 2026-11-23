{{-- AI assistant: right-side panel on desktop, full-screen app screen on mobile. Opened by any [data-ai-open] element. --}}
<button class="ai-fab" id="aiFab" type="button" data-ai-open title="AI assistant" aria-label="Open AI assistant"><svg class="tm-ai-logo" viewBox="0 0 32 32" width="1em" height="1em" aria-hidden="true"><use href="#tm-logo"/></svg></button>
<section class="ai-panel" id="aiPanel" aria-label="AI assistant" aria-hidden="true"
  data-history="{{ route('ai.history') }}" data-conversations="{{ route('ai.conversations') }}" data-conv-base="{{ url('/ai/conversations') }}"
  data-stream="{{ route('ai.stream') }}" data-exec="{{ route('ai.execute') }}" data-clear="{{ route('ai.clear') }}"
  data-memories="{{ route('ai.memories') }}" data-memory-base="{{ url('/ai/memories') }}">
  <div class="ai-scrim" id="aiScrim"></div>
  <aside class="ai-side" id="aiSide">
    <div class="ai-side-top">
      <button class="ai-new" type="button" id="aiNewSide"><i class="fas fa-plus"></i> New chat</button>
      <div class="ai-search"><i class="fas fa-magnifying-glass"></i><input type="search" id="aiSearch" placeholder="Search chats" aria-label="Search chats"></div>
    </div>
    <div class="ai-side-label">Recent</div>
    <div class="ai-convs" id="aiConvs"></div>
    <button class="ai-mem-btn" type="button" id="aiMemOpen"><i class="fas fa-brain"></i><span><b>What I remember</b><small>Your preferences &amp; my notes on you</small></span></button>
  </aside>

  <div class="ai-main">
    <header class="ai-head">
      <button class="ai-ib ai-menu" type="button" id="aiMenu" aria-label="Chats"><i class="fas fa-bars"></i></button>
      <span class="ai-gem"><svg class="tm-ai-logo" viewBox="0 0 32 32" width="1em" height="1em" aria-hidden="true"><use href="#tm-logo"/></svg></span>
      <div class="ai-title"><b id="aiName">Task Assistant</b><small><i class="ai-dot" id="aiDot"></i><span id="aiMode">connecting…</span></small></div>
      <button class="ai-ib ai-newbtn" type="button" id="aiNew" title="New chat" aria-label="New chat"><i class="fas fa-pen-to-square"></i></button>
      <div class="ai-more">
        <button class="ai-ib" type="button" id="aiMoreBtn" aria-label="More"><i class="fas fa-ellipsis-vertical"></i></button>
        <div class="ai-menu-pop" id="aiMorePop">
          <button type="button" id="aiMemOpen2"><i class="fas fa-brain"></i> What I remember</button>
          <button type="button" id="aiClearAll"><i class="fas fa-broom"></i> Delete all chats</button>
        </div>
      </div>
      <button class="ai-ib ai-wide-btn" type="button" id="aiWide" title="Full screen" aria-label="Full screen"><i class="fas fa-expand"></i></button>
      <button class="ai-ib" type="button" id="aiClose" aria-label="Close"><i class="fas fa-xmark"></i></button>
    </header>

    <div class="ai-log" id="aiLog" role="log" aria-live="polite"></div>
    <button class="ai-down" id="aiDown" type="button" aria-label="Scroll to latest"><i class="fas fa-arrow-down"></i></button>

    <div class="ai-chips" id="aiChips"></div>
    <div class="ai-files" id="aiFiles"></div>
    <form class="ai-form" id="aiForm" autocomplete="off">
      <input type="file" id="aiFile" accept="image/*,audio/*" multiple hidden>
      <button class="ai-ib" type="button" id="aiAttach" title="Attach a picture" aria-label="Attach"><i class="fas fa-paperclip"></i></button>
      <textarea id="aiInput" rows="1" maxlength="2000" placeholder="Ask anything, or tell me what to do…" aria-label="Message"></textarea>
      <button class="ai-ib ai-mic" type="button" id="aiMic" title="Speak" aria-label="Voice"><i class="fas fa-microphone"></i></button>
      <button class="ai-send" type="submit" id="aiSend" aria-label="Send"><i class="fas fa-arrow-up"></i></button>
    </form>
    <div class="ai-foot">AI can make mistakes · changes always ask you to confirm</div>

    <div class="ai-mem" id="aiMem" hidden>
      <div class="ai-mem-head"><button class="ai-ib" type="button" id="aiMemBack" aria-label="Back"><i class="fas fa-arrow-left"></i></button><b>What I remember about you</b></div>
      <p class="ai-mem-note">I pick these up from our chats and use them to personalise my help. Only you can see them. Remove anything that is wrong or that you would rather I forget.</p>
      <div id="aiMemList"></div>
      <button class="ai-forget" type="button" id="aiForgetAll">Forget everything</button>
    </div>
  </div>
</section>
<script src="{{ asset('js/ai.js') }}?v={{ filemtime(public_path('js/ai.js')) }}"></script>
