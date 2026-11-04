<button class="vp-btn sm" disabled>Đang chờ hàng đợi…</button>
<form method="POST" action="{{ $reset }}"@if($ajax ?? false) data-ajax-whole @endif>
    @csrf
    <button class="vp-btn sm dg">Huỷ lượt đang chờ</button>
</form>
