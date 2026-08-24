{{-- The NFC link editor: the ordered table plus the add-link form.

     Shared by Links mode and Profile mode, because both publish these same
     nfc_links rows - Links mode as buttons or a redirect, Profile mode as the
     public page's Accounts panel. Extracted rather than copied so that an
     admin editing a Profile card never has to switch the mode back to Links to
     reach the table, which would change what the live public URL serves while
     they work.

     Expects $card and $links (all of them, active and not - the table shows
     both, with a checkbox for the state). --}}

@if ($links->count())
  <div class="section-sep">
    <label class="fld">Links</label>
    <div class="tablewrap">
    <table>
      <thead><tr><th style="width:1%">#</th><th>Label</th><th>URL</th><th style="width:1%">Active</th><th style="width:1%"></th></tr></thead>
      <tbody>
      @foreach ($links as $i => $link)
        <tr>
          <td data-label="#" class="mono muted">{{ $i + 1 }}</td>
          <td colspan="3" style="padding-top:8px;padding-bottom:8px">
            <form method="POST" action="{{ route('admin.cards.nfc.update', [$card->public_id, $link->id]) }}" class="row row-tight">
              @csrf @method('PATCH')
              <input type="text" name="title" value="{{ $link->title }}" maxlength="120" style="width:140px" aria-label="Link label" required>
              <input type="url" name="url" value="{{ $link->url }}" style="flex:1;min-width:180px" aria-label="Link URL" required>
              <label class="small row row-tight" style="gap:5px;white-space:nowrap">
                <input type="checkbox" name="is_active" value="1" @checked($link->is_active) style="width:auto"> on
              </label>
              <button class="btn btn-sm" type="submit">Save</button>
            </form>
          </td>
          <td>
            <div class="row row-tight" style="flex-wrap:nowrap;justify-content:flex-end">
              @if (! $loop->first)
                <form method="POST" action="{{ route('admin.cards.nfc.move', [$card->public_id, $link->id]) }}">
                  @csrf @method('PATCH')<input type="hidden" name="direction" value="up">
                  <button class="btn btn-sm btn-ghost" type="submit" title="Move up" aria-label="Move up">&uarr;</button>
                </form>
              @endif
              @if (! $loop->last)
                <form method="POST" action="{{ route('admin.cards.nfc.move', [$card->public_id, $link->id]) }}">
                  @csrf @method('PATCH')<input type="hidden" name="direction" value="down">
                  <button class="btn btn-sm btn-ghost" type="submit" title="Move down" aria-label="Move down">&darr;</button>
                </form>
              @endif
              <form method="POST" action="{{ route('admin.cards.nfc.delete', [$card->public_id, $link->id]) }}"
                    onsubmit="return confirm('Delete this link?')">
                @csrf @method('DELETE')
                <button class="btn btn-sm btn-d" type="submit">Delete</button>
              </form>
            </div>
          </td>
        </tr>
      @endforeach
      </tbody>
    </table>
    </div>
  </div>
@endif

<div class="section-sep">
  <form method="POST" action="{{ route('admin.cards.nfc.add', $card->public_id) }}">
    @csrf
    <label class="fld">Add link</label>
    <div class="row row-tight">
      <input type="text" name="title" placeholder="Label (e.g. Instagram)" maxlength="120" style="width:170px" required>
      <input type="url" name="url" placeholder="https://…" style="flex:1;min-width:200px" required>
      <button class="btn btn-sm btn-p" type="submit">Add link</button>
      @if ($links->count())
        <button class="btn btn-sm btn-d" type="submit" form="reset-nfc">Reset NFC</button>
      @endif
    </div>
  </form>
  @if ($links->count())
    <form id="reset-nfc" method="POST" action="{{ route('admin.cards.nfc.reset', $card->public_id) }}"
          onsubmit="return confirm('Reset NFC links?\n\nAll links plus the landing page title, subtitle and logo are deleted. The QR destination and any saved contact/phone/email data are NOT affected.')">
      @csrf @method('DELETE')
    </form>
  @endif
</div>
