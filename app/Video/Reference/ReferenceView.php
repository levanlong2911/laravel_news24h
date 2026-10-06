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
    case TOP_DOWN = 'top_down';
    case LOW_ANGLE = 'low_angle';

    public const CAMERA_VERSION = 'reference-camera-v5';

    /** @var list<string> */
    public const SUPPORTED_CAMERA_VERSIONS = [self::CAMERA_VERSION];

    public const DESIGN_ANCHOR_VIEW = 'bow_three_quarter_port';

    /** @var list<string> */
    public const ANCHOR_VIEWS = [
        'stern_three_quarter_port', 'stern_three_quarter_starboard', 'bow_three_quarter_port', 'bow_three_quarter_starboard',
    ];

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** @return array<string, string> */
    public static function anchorViewRequirements(?string $view): array
    {
        if ($view === null) {
            return [];
        }

        if (! in_array($view, self::ANCHOR_VIEWS, true)) {
            throw new \InvalidArgumentException('Unsupported design anchor view');
        }

        return [
            'view' => $view,
            'elevation' => 'moderately elevated, oblique, not top-down',
            'framing' => 'whole vessel, moderate telephoto, no cropping',
            'near_end' => str_starts_with($view, 'stern_') ? 'stern' : 'bow',
            'proof_rule' => 'Prove only geometry visible from this view; do not relocate geometry to expose it.',
        ];
    }

    /** @return array{near_end: string, side: string, hidden: string} */
    public static function suppliedImageCamera(string $anchorView): array
    {
        if (! preg_match('/^(bow|stern)_three_quarter_(port|starboard)$/', $anchorView, $parts)) {
            throw new \InvalidArgumentException('Unsupported design anchor view');
        }

        $farSide = $parts[2] === 'port' ? 'starboard' : 'port';
        $farEnd = $parts[1] === 'bow'
            ? 'the stern\'s aft face (transom, the breadth of the stern platform and terrace edges seen from aft)'
            : 'the bow\'s forward face (stem and the breadth of the bow seen from ahead)';

        return ['near_end' => $parts[1], 'side' => $parts[2], 'hidden' => $farEnd.' and the whole '.$farSide.' side'];
    }

    /** @return array{near_end: ?string, side: ?string, aspects: list<string>} */
    public function frame(): array
    {
        return match ($this) {
            self::BOW_FRONT => ['near_end' => 'bow', 'side' => null, 'aspects' => ['forward']],
            self::STERN_REAR => ['near_end' => 'stern', 'side' => null, 'aspects' => ['aft']],
            self::PORT_SIDE => ['near_end' => null, 'side' => 'port', 'aspects' => ['port']],
            self::STARBOARD_SIDE => ['near_end' => null, 'side' => 'starboard', 'aspects' => ['starboard']],
            self::BOW_THREE_QUARTER => ['near_end' => 'bow', 'side' => 'starboard', 'aspects' => ['forward', 'starboard']],
            self::STERN_THREE_QUARTER => ['near_end' => 'stern', 'side' => 'port', 'aspects' => ['aft', 'port']],
            self::TOP_DOWN => ['near_end' => null, 'side' => null, 'aspects' => ['upward']],
            self::TOP_DECK => ['near_end' => 'bow', 'side' => 'port', 'aspects' => ['upward', 'forward', 'port']],
            self::DECK_OVERVIEW => ['near_end' => 'bow', 'side' => null, 'aspects' => ['upward', 'forward']],
            self::LOW_ANGLE => ['near_end' => 'bow', 'side' => 'port', 'aspects' => ['below', 'forward', 'port']],
        };
    }

    /** @return list<string> */
    public function proofViews(): array
    {
        return match ($this) {
            self::BOW_THREE_QUARTER => ['bow_three_quarter_starboard'],
            self::STERN_THREE_QUARTER => ['stern_three_quarter_port'],
            self::PORT_SIDE => ['profile_port'],
            self::STARBOARD_SIDE => ['profile_starboard'],
            self::BOW_FRONT => ['head_on_bow'],
            self::STERN_REAR => ['head_on_stern'],
            self::TOP_DOWN => ['plan_above'],
            self::LOW_ANGLE => ['low_angle_bow_port'],
            self::TOP_DECK => ['high_angle_bow_port'],
            default => [],
        };
    }

    public static function hiddenEnd(string $nearEnd, ?string $side): string
    {
        $outline = $side === null ? 'the outline beyond the '.$nearEnd.' and the superstructure' : 'the outline of the '.$side.' flank';
        $outboard = $side === null ? 'outboard of the hull sides' : 'onto or outboard of the '.$side.' flank';

        return $nearEnd === 'bow'
            ? 'The stern faces away from the camera: its centreline stairs, landings, pool and water platform lie behind '
                .'the superstructure and within the hull sides and stay out of view; the stern reads only as '.$outline
                .($side === null ? '' : ' and its stepped deck edges').'. No stair, landing or pool is moved '.$outboard.' to be seen.'
            : 'The bow faces away from the camera: its foredeck, stem face and forward parts lie beyond the superstructure '
                .'and stay out of view; the bow reads only as '.$outline.($side === null ? '' : ' and its sheer').'. No part is moved '
                .$outboard.' to be seen.';
    }

    public static function bowEdge(string $side): string
    {
        return $side === 'port' ? 'left' : 'right';
    }

    public static function frameDirection(string $side): string
    {
        $bowEdge = self::bowEdge($side);
        $sternEdge = $bowEdge === 'left' ? 'right' : 'left';

        return "The bow points toward the {$bowEdge} side of the frame and the stern toward the {$sternEdge} side.";
    }

    /** @return list<self> */
    public static function menu(): array
    {
        return [
            self::BOW_FRONT,
            self::STERN_REAR,
            self::PORT_SIDE,
            self::STARBOARD_SIDE,
            self::BOW_THREE_QUARTER,
            self::STERN_THREE_QUARTER,
            self::TOP_DOWN,
            self::LOW_ANGLE,
            self::TOP_DECK,
        ];
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
            self::TOP_DOWN => 9,
            self::LOW_ANGLE => 10,
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
            self::BOW_THREE_QUARTER => 'Bow Three-quarter Starboard',
            self::STERN_THREE_QUARTER => 'Stern Three-quarter Port',
            self::TOP_DECK => 'Top Deck',
            self::DECK_OVERVIEW => 'Deck Overview',
            self::TOP_DOWN => 'Top Down',
            self::LOW_ANGLE => 'Low Angle',
        };
    }

    public function displayLabel(): string
    {
        return match ($this) {
            self::BOW_FRONT => 'Chính diện',
            self::STERN_REAR => 'Chính hậu',
            self::PORT_SIDE => 'Mặt bên trái / port',
            self::STARBOARD_SIDE => 'Mặt bên phải / starboard',
            self::BOW_THREE_QUARTER => 'Góc 3/4 phía trước (mạn phải)',
            self::STERN_THREE_QUARTER => 'Góc 3/4 phía sau (mạn trái)',
            self::TOP_DOWN => 'Nhìn từ trên xuống',
            self::LOW_ANGLE => 'Góc máy thấp',
            self::TOP_DECK => 'Góc máy cao',
            self::DECK_OVERVIEW => 'Toàn cảnh boong (cũ)',
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
                .'and the stern to the right edge. Bow tip and stern both stay inside the frame and the full '
                .'length of the sheerline stays in frame.',

            self::STARBOARD_SIDE => 'The camera is level with the hull at mid-freeboard height and square to the '
                .'starboard side, its axis perpendicular to the centreline. The bow points to the right edge of '
                .'the frame and the stern to the left edge. Bow tip and stern both stay inside the frame and '
                .'the full length of the sheerline stays in frame.',

            self::BOW_FRONT => 'The camera stands on the longitudinal centreline ahead of the bow, level with the '
                .'main deck, looking straight aft on a moderate telephoto lens with low optical distortion. The '
                .'centreline runs vertically through the centre of the frame. The frame holds the vessel from '
                .'the waterline to the highest point and across the full beam at its widest, with clear space '
                .'on all four sides. '.self::hiddenEnd('bow', null),

            self::STERN_REAR => 'The camera stands on the longitudinal centreline behind the stern, level with '
                .'the main deck, looking straight forward on a moderate telephoto lens with low optical '
                .'distortion. The centreline runs vertically through the centre of the frame and both quarters '
                .'are equally visible. The frame holds the vessel from the waterline to the highest point and '
                .'across the full beam at its widest, with clear space on all four sides.',

            self::BOW_THREE_QUARTER => 'The camera sits about thirty-five degrees off the bow centreline toward '
                .'the starboard side, its line of sight about fifteen degrees below horizontal, on a moderate telephoto '
                .'lens at a distance great enough to keep optical distortion low. The bow is nearer the camera than '
                .'the stern; the bow face and the starboard flank are both fully visible and the flank still reads as '
                .'a vertical face. '.self::frameDirection('starboard').' The frame holds the whole vessel from '
                .'stem to stern and the hull keeps its true length rather than compressing toward the stern. '
                .self::hiddenEnd('bow', 'starboard'),

            self::STERN_THREE_QUARTER => 'The camera sits about thirty-five degrees off the stern centreline '
                .'toward the port side, its line of sight about fifteen degrees below horizontal, on a moderate '
                .'telephoto lens at a distance great enough to keep optical distortion low. The stern and the '
                .'port flank are both fully visible and the flank still reads as a vertical face. '
                .self::frameDirection('port').' The frame holds the whole vessel from stern to stem.',

            self::TOP_DECK => 'The camera sits high, about forty degrees off the bow centreline toward the port side, '
                .'its line of sight about thirty-five degrees below horizontal, on a moderate telephoto lens at a '
                .'distance great enough to keep optical distortion low. This is an oblique view from above, never a '
                .'plan view: upward-facing surfaces stay distinguishable from the levels above and below them while '
                .'the port flank still reads as a vertical face, and the deck edges stay distinguishable from the '
                .'surfaces above them. '.self::frameDirection('port'),

            self::DECK_OVERVIEW => 'The camera is high above the longitudinal centreline and forward of the bow, '
                .'looking aft and downward at about sixty-five degrees, on a moderate telephoto lens at a distance '
                .'great enough to keep optical distortion low. The bow is nearest the camera and the stern '
                .'farthest, and the deck arrangement reads as a plan inside one continuous hull outline, with '
                .'bow and stern both in frame.',

            self::TOP_DOWN => 'The camera is directly above the vessel, looking straight down, on a moderate '
                .'telephoto lens at a distance great enough to keep optical distortion low. The vessel reads as '
                .'a plan: bow toward the top edge of the frame, stern toward the bottom edge, the whole outline '
                .'in frame.',

            self::LOW_ANGLE => 'The camera stands low, close to the waterline, about thirty-five degrees off the bow '
                .'centreline toward the port side, its line of sight about ten degrees above horizontal, on a moderate telephoto lens at a '
                .'distance great enough to keep optical distortion low. '.self::frameDirection('port').' The whole '
                .'vessel stays in frame from stem to stern and its height is not exaggerated. '.self::hiddenEnd('bow', 'port'),
        };
    }

    public function cameraBlock(): string
    {
        return 'REFERENCE VIEW OVERRIDE: Replace any previous CAMERA / VIEW instruction with the following. '
            .'Keep all canonical identity, topology and proportions unchanged. '
            .$this->cameraOverride();
    }
}
