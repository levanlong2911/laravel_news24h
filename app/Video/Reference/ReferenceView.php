<?php

namespace App\Video\Reference;

enum ReferenceView: string
{
    case PORT_SIDE = 'port_side';
    case STARBOARD_SIDE = 'starboard_side';
    case BOW_FRONT = 'bow_front';
    case STERN_REAR = 'stern_rear';
    case BOW_THREE_QUARTER = 'bow_three_quarter';
    case STERN_THREE_QUARTER = 'stern_three_quarter';
    case TOP_DECK = 'top_deck';
    case DECK_OVERVIEW = 'deck_overview';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function slot(): int
    {
        return match ($this) {
            self::PORT_SIDE => 1,
            self::STARBOARD_SIDE => 2,
            self::BOW_FRONT => 3,
            self::STERN_REAR => 4,
            self::BOW_THREE_QUARTER => 5,
            self::STERN_THREE_QUARTER => 6,
            self::TOP_DECK => 7,
            self::DECK_OVERVIEW => 8,
        };
    }

    public function mirrorPartner(): ?self
    {
        return match ($this) {
            self::PORT_SIDE => self::STARBOARD_SIDE,
            self::STARBOARD_SIDE => self::PORT_SIDE,
            default => null,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::PORT_SIDE => 'Port Side',
            self::STARBOARD_SIDE => 'Starboard Side',
            self::BOW_FRONT => 'Bow Front',
            self::STERN_REAR => 'Stern Rear',
            self::BOW_THREE_QUARTER => 'Bow Three-quarter',
            self::STERN_THREE_QUARTER => 'Stern Three-quarter',
            self::TOP_DECK => 'Top Deck',
            self::DECK_OVERVIEW => 'Deck Overview',
        };
    }

    /**
     * Moi cau khoa GOC CHUC, ONG KINH va YEU CAU KHUNG. Khong cau nao khai cao do
     * may quay: cao do, khoang cach va goc chuc la ba mat cua mot bien, khai hai
     * cai la tu tao mau thuan. Khong cau nao nhac nen hay moi truong — phan do
     * thuoc `ReferenceEnvironment`.
     */
    public function cameraOverride(): string
    {
        return match ($this) {
            self::PORT_SIDE => 'The camera is level with the hull at mid-freeboard height and square to the port '
                .'side, its axis perpendicular to the centreline. The bow points to the left edge of the frame '
                .'and the stern to the right edge. Bow tip and transom both stay inside the frame and the whole '
                .'sheerline reads as one continuous curve.',

            self::STARBOARD_SIDE => 'The camera is level with the hull at mid-freeboard height and square to the '
                .'starboard side, its axis perpendicular to the centreline. The bow points to the right edge of '
                .'the frame and the stern to the left edge. Bow tip and transom both stay inside the frame and '
                .'the whole sheerline reads as one continuous curve.',

            self::BOW_FRONT => 'The camera stands on the longitudinal centreline ahead of the bow, level with the '
                .'main deck, looking straight aft on a moderate telephoto lens with low optical distortion. Port '
                .'and starboard read as mirror halves of one symmetrical face and the stem line runs vertically '
                .'through the centre of the frame. The frame holds the vessel from the waterline to the highest '
                .'roofline and across the full beam at its widest, with clear space on all four sides.',

            self::STERN_REAR => 'The camera stands on the longitudinal centreline astern of the transom, level '
                .'with the main deck, looking straight forward on a moderate telephoto lens with low optical '
                .'distortion. The transom fills the centre of the frame as one symmetrical face and both quarters '
                .'are equally visible. The frame holds the vessel from the waterline to the highest roofline and '
                .'across the full beam at its widest, with clear space on all four sides.',

            self::BOW_THREE_QUARTER => 'The camera sits about thirty-five degrees off the bow centreline toward '
                .'the port side, its line of sight about fifteen degrees below horizontal, on a moderate telephoto '
                .'lens at a distance great enough to keep optical distortion low. The bow face and the port flank '
                .'are both fully visible and the flank still reads as a vertical face. The frame holds the whole '
                .'vessel from stem to transom and the hull keeps its true length rather than compressing toward '
                .'the stern.',

            self::STERN_THREE_QUARTER => 'The camera sits about thirty-five degrees off the stern centreline '
                .'toward the port side, its line of sight about fifteen degrees below horizontal, on a moderate '
                .'telephoto lens at a distance great enough to keep optical distortion low. The transom and the '
                .'port flank are both fully visible and the flank still reads as a vertical face. The frame holds '
                .'the whole vessel from transom to stem and the aft terraces read as separate stepped levels.',

            self::TOP_DECK => 'The camera sits about forty degrees off the bow centreline toward the port side, '
                .'its line of sight about thirty-five degrees below horizontal, on a moderate telephoto lens at a '
                .'distance great enough to keep optical distortion low. Every superstructure tier roof is visible '
                .'as a separate surface while the port flank still reads as a vertical face, and the deck edges '
                .'stay distinguishable from the roofs above them.',

            self::DECK_OVERVIEW => 'The camera is high above the longitudinal centreline and forward of the bow, '
                .'looking aft and downward at about sixty-five degrees, on a moderate telephoto lens at a distance '
                .'great enough to keep optical distortion low. The bow is nearest the camera and the transom '
                .'farthest, and the deck arrangement reads as a plan: each tier footprint, the open foredeck, the '
                .'stepped aft terraces and the transom platform are distinct areas inside one continuous hull '
                .'outline, with bow and transom both in frame.',
        };
    }
}
