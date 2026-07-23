<?php

namespace App\Video\Editorial;

/**
 * Hanh dong VAT LY generic — KHONG phai domain vocab (khong "weld"/"rivet"
 * rieng nganh). 8 gia tri dau rut tu vocab THAT da validate render (Sprint 1-3,
 * `install_hull_block_v1.json`) + dry-run 2-domain (`install_door_v1.json`).
 * Position them 2026-07-22 — bang chung that qua nut 🎬 that: bai bao ngoai
 * nganh cong nghiep (yacht/su kien) co quan he "docked_at" khong khop 8 gia
 * tri dau, VA quan he do la vi tri/neo dau — generic da domain (thuyen dau
 * ben, xe dau bai, may bay ha canh), khong rieng yacht. Xem ARCHITECTURE.md SS18.4.
 */
enum ActionType: string
{
    case Lift     = 'lift';      // "the gantry crane lowers/lifts the hull block"
    case Lower    = 'lower';
    case Align    = 'align';     // "a robotic arm swings the door into the opening"
    case Guide    = 'guide';     // "the hero rigger braces and guides it" / "a fitter steadies its edge"
    case Secure   = 'secure';    // "a second fitter drives bolts along the hinge line"
    case Inspect  = 'inspect';   // "a quality inspector checks the panel gap"
    case Signal   = 'signal';    // "the hero rigger to signal the operator"
    case Release  = 'release';
    case Position = 'position';  // "docked_at"/"moored_at"/"parked_at" — vị trí/neo đậu
    // Perform them 2026-07-22 — bang chung that: world.EVENTS "surprise_performance"/
    // "performed_song" (entity=nas) khong co target tu nhien (khac Relation luon co
    // 2 entity) — action tu than, KHONG bia target. Xem ActionCandidate::toArray().
    case Perform  = 'perform';
    // Triumph/Confront them 2026-07-22 — bang chung that qua video:benchmark (10 bai
    // that, $0.69): event "race_victory" (Superyacht Cup Palma, x5), "award_won"
    // (Superyacht $370M, x2) khong khop 10 gia tri tren — chien thang/thanh tich la
    // hanh dong generic da domain (dua thuyen, giai thuong, the thao, kinh doanh),
    // khong rieng yacht. "protest_clash"/"break_in" (Beagles) la doi dau vat ly that,
    // cung generic. Xem project_next_priorities_post_confidence.md (memory).
    case Triumph  = 'triumph';   // "the crew celebrates as the yacht crosses the finish line"
    case Confront = 'confront';  // "protesters clash outside the gate"
}
