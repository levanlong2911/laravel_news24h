<?php

namespace Tests\Unit\AFOS\Passes\Temporal;

use App\Services\AI\AFOS\Ir\Temporal\CameraKeyframe;
use App\Services\AI\AFOS\Ir\Temporal\CameraTrack;
use App\Services\AI\AFOS\Ir\Temporal\EdgeStore;
use App\Services\AI\AFOS\Ir\Temporal\EventEdge;
use App\Services\AI\AFOS\Ir\Temporal\FrozenTemporalGraph;
use App\Services\AI\AFOS\Ir\Temporal\GraphSnapshot;
use App\Services\AI\AFOS\Ir\Temporal\MotionBeat;
use App\Services\AI\AFOS\Ir\Temporal\TemporalGraphView;
use App\Services\AI\AFOS\Ir\Temporal\MotionIntent;
use App\Services\AI\AFOS\Ir\Temporal\MotionTrack;
use App\Services\AI\AFOS\Ir\Temporal\NodeRef;
use App\Services\AI\AFOS\Ir\Temporal\RelationType;
use App\Services\AI\AFOS\Ir\Temporal\TemporalGraph;
use PHPUnit\Framework\TestCase;

final class TemporalGraphTest extends TestCase
{
    private function makeMotionTrack(): MotionTrack
    {
        $intent = new MotionIntent('build', 'legato', 'body', 'flowing');
        $beats  = [
            new MotionBeat('beat_1', 0.0, 2.0, 'subject', 'body', 'still',  'linear',  0.5),
            new MotionBeat('beat_2', 2.0, 5.0, 'subject', 'body', 'emerge', 'ease_in', 0.7),
        ];
        return new MotionTrack($beats, $intent);
    }

    private function motionEdge(): EventEdge
    {
        return new EventEdge(NodeRef::motion('beat_2'), NodeRef::motion('beat_1'), RelationType::Follows);
    }

    private function makeCameraTrack(): CameraTrack
    {
        return new CameraTrack([
            new CameraKeyframe('kf_1', 0.0, 'wide',   'static', 0.0, 35),
            new CameraKeyframe('kf_2', 3.0, 'medium', 'push',   0.3, 35),
        ]);
    }

    // ── Construction ─────────────────────────────────────────────────────────

    public function test_empty_graph_stores_duration(): void
    {
        $graph = TemporalGraph::empty(8.0);
        $this->assertSame(8.0, $graph->durationSec);
    }

    public function test_empty_graph_has_no_tracks(): void
    {
        $graph = TemporalGraph::empty(5.0);
        $this->assertTrue($graph->isEmpty());
        $this->assertSame(0, $graph->trackCount());
    }

    public function test_from_tracks_builds_graph_with_two_tracks(): void
    {
        $graph = TemporalGraph::fromTracks(8.0, $this->makeMotionTrack(), $this->makeCameraTrack());
        $this->assertFalse($graph->isEmpty());
        $this->assertSame(2, $graph->trackCount());
    }

    public function test_with_track_adds_track_immutably(): void
    {
        $base    = TemporalGraph::empty(8.0);
        $updated = $base->withTrack(MotionTrack::ID, $this->makeMotionTrack());

        $this->assertTrue($base->isEmpty());
        $this->assertFalse($updated->isEmpty());
        $this->assertSame(1, $updated->trackCount());
    }

    public function test_with_track_preserves_existing_tracks(): void
    {
        $graph = TemporalGraph::empty(8.0)
            ->withTrack(MotionTrack::ID, $this->makeMotionTrack())
            ->withTrack(CameraTrack::ID, $this->makeCameraTrack());

        $this->assertSame(2, $graph->trackCount());
    }

    // ── Typed accessors ───────────────────────────────────────────────────────

    public function test_motion_accessor_returns_motion_track(): void
    {
        $motion = $this->makeMotionTrack();
        $graph  = TemporalGraph::empty(8.0)->withTrack(MotionTrack::ID, $motion);

        $this->assertSame($motion, $graph->motion());
        $this->assertNull($graph->camera());
    }

    public function test_camera_accessor_returns_camera_track(): void
    {
        $camera = $this->makeCameraTrack();
        $graph  = TemporalGraph::empty(8.0)->withTrack(CameraTrack::ID, $camera);

        $this->assertSame($camera, $graph->camera());
        $this->assertNull($graph->motion());
    }

    // ── TrackId-based has/get ─────────────────────────────────────────────────

    public function test_has_returns_true_by_track_id(): void
    {
        $graph = TemporalGraph::empty(8.0)->withTrack(CameraTrack::ID, $this->makeCameraTrack());

        $this->assertTrue($graph->has(CameraTrack::ID));
        $this->assertFalse($graph->has(MotionTrack::ID));
    }

    public function test_get_returns_track_by_track_id(): void
    {
        $camera = $this->makeCameraTrack();
        $graph  = TemporalGraph::empty(8.0)->withTrack(CameraTrack::ID, $camera);

        $this->assertSame($camera, $graph->get(CameraTrack::ID));
        $this->assertNull($graph->get(MotionTrack::ID));
    }

    public function test_all_returns_all_tracks(): void
    {
        $graph = TemporalGraph::fromTracks(8.0, $this->makeMotionTrack(), $this->makeCameraTrack());
        $this->assertCount(2, $graph->all());
    }

    public function test_track_ids_returns_domain_ids(): void
    {
        $graph = TemporalGraph::fromTracks(8.0, $this->makeMotionTrack(), $this->makeCameraTrack());
        $ids   = $graph->trackIds();

        $this->assertContains(MotionTrack::ID, $ids);
        $this->assertContains(CameraTrack::ID, $ids);
    }

    // ── EdgeStore ─────────────────────────────────────────────────────────────

    public function test_edges_returns_edge_store(): void
    {
        $graph = TemporalGraph::empty(8.0)->withTrack(MotionTrack::ID, $this->makeMotionTrack());
        $this->assertInstanceOf(EdgeStore::class, $graph->edges());
    }

    public function test_with_track_does_not_add_edges_automatically(): void
    {
        // Edges are pure data; graph structure is added separately via withEdge()
        $graph = TemporalGraph::empty(8.0)->withTrack(MotionTrack::ID, $this->makeMotionTrack());
        $this->assertSame(0, $graph->edges()->count());
    }

    public function test_with_edge_adds_to_edge_store_immutably(): void
    {
        $graph   = TemporalGraph::empty(8.0)->withTrack(MotionTrack::ID, $this->makeMotionTrack());
        $updated = $graph->withEdge($this->motionEdge());

        $this->assertSame(0, $graph->edges()->count());   // original unchanged
        $this->assertSame(1, $updated->edges()->count());
    }

    public function test_edges_from_returns_correct_edges(): void
    {
        $graph = TemporalGraph::empty(8.0)
            ->withTrack(MotionTrack::ID, $this->makeMotionTrack())
            ->withEdge($this->motionEdge());

        $edges = $graph->edges()->edgesFrom(NodeRef::motion('beat_2'));
        $this->assertCount(1, $edges);
        $this->assertSame(RelationType::Follows, $edges[0]->type);
        $this->assertSame('beat_1', $edges[0]->to->eventId);
    }

    // ── freeze() ─────────────────────────────────────────────────────────────

    public function test_freeze_returns_frozen_temporal_graph(): void
    {
        $frozen = TemporalGraph::empty(8.0)->freeze();
        $this->assertInstanceOf(FrozenTemporalGraph::class, $frozen);
    }

    public function test_frozen_graph_has_no_mutation_methods(): void
    {
        $frozen = TemporalGraph::empty(8.0)->freeze();
        // Type-level immutability: FrozenTemporalGraph has no withTrack/withEdge.
        $this->assertFalse(method_exists($frozen, 'withTrack'));
        $this->assertFalse(method_exists($frozen, 'withEdge'));
        $this->assertFalse(method_exists($frozen, 'withEdges'));
    }

    public function test_freeze_produces_snapshot(): void
    {
        $frozen = TemporalGraph::fromTracks(8.0, $this->makeMotionTrack(), $this->makeCameraTrack())->freeze();
        $snap   = $frozen->snapshot();

        $this->assertInstanceOf(GraphSnapshot::class, $snap);
        $this->assertSame(2, $snap->trackCount);
        $this->assertSame(4, $snap->nodeCount); // MotionTrack 2 beats + CameraTrack 2 keyframes
        $this->assertGreaterThan(0.0, $snap->frozenAtUs);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}@\d+$/', $snap->id);
    }

    public function test_freeze_canonicalizes_tracks(): void
    {
        // MotionTrack::ID = 'motion', CameraTrack::ID = 'camera' — 'camera' < 'motion' alphabetically
        $frozen = TemporalGraph::empty(8.0)
            ->withTrack(MotionTrack::ID, $this->makeMotionTrack())
            ->withTrack(CameraTrack::ID, $this->makeCameraTrack())
            ->freeze();

        $ids = $frozen->trackIds();
        $this->assertSame(CameraTrack::ID, $ids[0]);
        $this->assertSame(MotionTrack::ID, $ids[1]);
    }

    public function test_freeze_stores_validation_result(): void
    {
        $frozen = TemporalGraph::fromTracks(8.0, $this->makeMotionTrack())->freeze();
        $this->assertTrue($frozen->validationResult()->isValid());
    }

    public function test_frozen_temporal_graph_implements_temporal_graph_view(): void
    {
        $frozen = TemporalGraph::empty(5.0)->freeze();
        $this->assertInstanceOf(TemporalGraphView::class, $frozen);
    }

    // ── findEvent ─────────────────────────────────────────────────────────────

    public function test_find_event_locates_event_across_tracks(): void
    {
        $graph = TemporalGraph::fromTracks(8.0, $this->makeMotionTrack(), $this->makeCameraTrack());

        $beat = $graph->findEvent('beat_1');
        $this->assertNotNull($beat);
        $this->assertSame('beat_1', $beat->id);

        $kf = $graph->findEvent('kf_2');
        $this->assertNotNull($kf);
        $this->assertSame('kf_2', $kf->id);
    }

    public function test_find_event_returns_null_for_unknown_id(): void
    {
        $graph = TemporalGraph::fromTracks(8.0, $this->makeMotionTrack());
        $this->assertNull($graph->findEvent('ghost_id'));
    }

    // ── edges() ───────────────────────────────────────────────────────────────

    public function test_edges_empty_when_no_edges_added(): void
    {
        $graph = TemporalGraph::fromTracks(8.0, $this->makeMotionTrack(), $this->makeCameraTrack());
        $this->assertTrue($graph->edges()->isEmpty());
    }

    public function test_edges_accumulates_across_with_edges(): void
    {
        $e1 = new EventEdge(NodeRef::motion('beat_1'), NodeRef::camera('kf_1'), RelationType::Supports);
        $e2 = $this->motionEdge();
        $graph = TemporalGraph::fromTracks(8.0, $this->makeMotionTrack(), $this->makeCameraTrack())
            ->withEdges($e1, $e2);

        $this->assertSame(2, $graph->edges()->count());
    }

    // ── validate ─────────────────────────────────────────────────────────────

    public function test_validate_returns_valid_for_clean_tracks(): void
    {
        $graph  = TemporalGraph::fromTracks(8.0, $this->makeMotionTrack(), $this->makeCameraTrack());
        $result = $graph->validate();

        $this->assertTrue($result->isValid());
    }
}
