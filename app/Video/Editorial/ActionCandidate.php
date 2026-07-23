<?php

namespace App\Video\Editorial;

/**
 * MOT lua chon hanh dong hop le cho 1 scene — sinh boi Rule Engine
 * (EditorialInterpreter::candidatesFor()), Director CHI CHON, khong tu viet.
 *
 * KHONG vao RenderPlan.json — day la working memory noi bo Laravel. Chi
 * ActionSelection (Director da chon) moi duoc resolve thanh director_notes.
 */
final class ActionCandidate
{
    /**
     * @param string $target entity id — RONG khi hanh dong tu than, khong co doi
     *        tuong tac dong (vd "nas performs" tu 1 Event, khac Relation luon co
     *        2 entity). KHONG bia target gia — de rong that.
     * @param list<string> $modifiers vd ['heavy_object'] — rong = khong modifier
     */
    public function __construct(
        public readonly ActionType $type,
        public readonly string $actor,   // entity id
        public readonly string $target = '',
        public readonly array $modifiers = [],
    ) {
    }

    public function toArray(): array
    {
        $doc = [
            'type' => $this->type->value,
            'actor' => $this->actor,
        ];

        // target rong -> BO han key, khong emit '' (schema slug doi minLength 1 —
        // xem contracts/renderplan/v1.0/schema.json $defs/action). Giu TRUOC
        // modifiers khi co gia tri — khop dung thu tu cu, tranh vo test khong
        // lien quan (assertSame tren array quan tam thu tu key).
        if ($this->target !== '') {
            $doc['target'] = $this->target;
        }

        $doc['modifiers'] = $this->modifiers;

        return $doc;
    }
}
