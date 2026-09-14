{{--
  Nam trang thai, khong duoc gop:
    chua co dong so cai   → KHONG noi gi ve tien
    co uoc tinh dong bang → goi dung ten no la uoc tinh, va noi ro chua doi soat
    co dong unpriced      → chua dinh gia, ke ca khi dong khac da co so
    co dong truoc hop dong `pricing` → chua phan loai: khong ai biet con so do la
                            uoc tinh hay tien that, nen KHONG duoc goi la da ghi nhan
    con lai               → da ghi nhan (vi du anh lat: dung 0 dong)

  So trong `cost_usd` chi mang tien da xac nhan sau Pha 3C, nhung hang cu thi chua
  chac, nen o day khong chu nao goi bat cu so nao la "thuc chi".
--}}
@if($cell['cost_recorded_has_ledger'])
    @if($cell['cost_recorded_has_estimate'])
        ước tính ${{ number_format($cell['cost_recorded_estimated'], 3) }}{{ $cell['cost_recorded'] > 0 ? '' : ' · chưa có chi phí đã đối soát' }}{{ $cell['cost_recorded_unpriced'] ? ' · còn lượt chưa định giá' : '' }}{{ ($cell['cost_recorded_has_unclassified'] ?? false) ? ' · còn lượt chưa phân loại' : '' }}
    @elseif($cell['cost_recorded_unpriced'])
        {{ $cell['cost_recorded'] > 0
            ? 'đã ghi nhận $'.number_format($cell['cost_recorded'], 3).' · còn lượt chưa định giá'
            : 'chưa định giá' }}{{ ($cell['cost_recorded_has_unclassified'] ?? false) ? ' · còn lượt chưa phân loại' : '' }}
    @elseif($cell['cost_recorded_has_unclassified'] ?? false)
        ${{ number_format($cell['cost_recorded_unclassified'], 3) }} · chưa phân loại
    @else
        đã ghi nhận ${{ number_format($cell['cost_recorded'], 3) }}
    @endif
@endif
