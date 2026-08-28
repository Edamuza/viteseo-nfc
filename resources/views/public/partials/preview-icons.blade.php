{{-- Every platform mark the live preview can need, rendered once and cloned by
     JavaScript. The same component the real tapped pages use, so a preview
     cannot show a mark the saved page would not. --}}
<template id="pv-icons">
  @foreach (array_keys(\App\Services\LinkPlatform::iconChoices()) as $iconKey)
    @continue($iconKey === \App\Services\LinkPlatform::NONE)
    <span data-k="{{ $iconKey }}"><x-platform-icon :platform="$iconKey" /></span>
  @endforeach
</template>
