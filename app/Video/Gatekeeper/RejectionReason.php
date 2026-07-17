<?php

namespace App\Video\Gatekeeper;

enum RejectionReason: string
{
    /** Quote không tìm thấy trong bài — LLM bịa cả câu trích. */
    case QuoteNotFound = 'QUOTE_NOT_FOUND';

    /** Quote có thật nhưng không nói lên giá trị được khai báo — LLM suy luận thêm. */
    case ValueNotSupported = 'VALUE_NOT_SUPPORTED';

    /** LLM không đưa quote nào. */
    case NoEvidence = 'NO_EVIDENCE';

    /** entity.type nằm ngoài ontology chung. */
    case UnknownEntityType = 'UNKNOWN_ENTITY_TYPE';

    /** Relation/Event/claim trỏ tới entity không được verify. */
    case DanglingReference = 'DANGLING_REFERENCE';

    /**
     * Entity không có tên truy được về bài, cũng không có thuộc tính nào —
     * không bằng chứng nào cho thấy nó tồn tại.
     *
     * KHÔNG dùng cho entity chỉ-có-tên: Feadship là node hợp lệ dù bài không mô
     * tả thuộc tính nào của nó. Xem Entity::isAnchorOnly().
     */
    case NoVerifiedClaims = 'NO_VERIFIED_CLAIMS';

    public function explain(): string
    {
        return match ($this) {
            self::QuoteNotFound      => 'trích dẫn không có trong bài (LLM bịa quote)',
            self::ValueNotSupported  => 'quote có thật nhưng không chống lưng cho giá trị này (LLM suy luận)',
            self::NoEvidence         => 'không có bằng chứng nào',
            self::UnknownEntityType  => 'entity type ngoài ontology chung',
            self::DanglingReference  => 'trỏ tới entity không được verify',
            self::NoVerifiedClaims   => 'không tên, không thuộc tính — không bằng chứng nào cho thấy nó tồn tại',
        };
    }
}
