{{-- Where the owner is in setup. $step is 1, 2 or 3.

     Named for what the owner does, not for what the system does: "Unlock
     card", never "Authenticate". --}}
@php $stepLabels = [1 => 'Pick a type', 2 => 'Unlock card', 3 => 'Add details']; @endphp
<ol class="steps" aria-label="Setup progress">
  @foreach ($stepLabels as $n => $stepLabel)
    <li class="{{ $n < $step ? 'done' : ($n === $step ? 'now' : '') }}"
        @if ($n === $step) aria-current="step" @endif>
      <span class="bar"></span>
      <span>{{ $n }}. {{ $stepLabel }}</span>
    </li>
  @endforeach
</ol>
