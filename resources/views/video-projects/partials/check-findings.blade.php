{{--
    What a check found besides its RULE issues (MotoCheckRules): the conditions the next step needs,
    which keep the run from being used until they hold, and notes on the content, which never block.
    $check: a check result.
--}}
@foreach((array) ($check['requirements'] ?? []) as $requirement)
    <div class="va-lbl" style="font-weight:400;color:var(--vp-amber-fg)">Điều kiện để dùng ở bước sau: {{ $requirement }}</div>
@endforeach
@foreach((array) ($check['notes'] ?? []) as $note)
    <div class="va-lbl" style="font-weight:400;color:var(--vp-dim)">Ghi chú nội dung (không chặn): {{ $note }}</div>
@endforeach
