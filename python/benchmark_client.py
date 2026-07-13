"""
benchmark_client.py — sends a completed Kling render result to Laravel API.

Workflows:

  A. Full pipeline (recommended):
       php artisan benchmark:generate-prompt --fixture=nfl_quarterback_throw --seed=12345 --json > payload.json
       # → copy prompt_text → render in Kling → download MP4
       python benchmark_client.py submit-payload payload.json --artifact=renders/sprint3/nfl/run1/

  B. From artifact directory (legacy):
       python benchmark_client.py <artifact_dir>

  C. Import and call directly from your render worker:
       from benchmark_client import submit_render, submit_from_payload
"""

import json
import os
import sys
from datetime import datetime, timezone
from pathlib import Path

import requests

# ---------------------------------------------------------------------------
# Config
# ---------------------------------------------------------------------------

LARAVEL_BASE_URL = os.environ.get("LARAVEL_URL", "http://127.0.0.1:8000")
API_URL          = f"{LARAVEL_BASE_URL}/api/benchmark/render-result"
API_TIMEOUT      = 30  # seconds

# Set LARAVEL_API_TOKEN to a Sanctum personal access token, e.g.:
#   export LARAVEL_API_TOKEN=$(php artisan tinker --execute="echo \App\Models\User::first()->createToken('benchmark')->plainTextToken;")
API_TOKEN = os.environ.get("LARAVEL_API_TOKEN", "")


def _auth_headers() -> dict:
    if not API_TOKEN:
        return {}
    return {"Authorization": f"Bearer {API_TOKEN}"}


def submit_render(
    *,
    render_uuid: str,
    session_code: str,
    fixture_slug: str,
    model: str,
    resolution: str,
    duration_seconds: int,
    fps: int,
    seed: str | None,
    char_count: int,
    prompt_version: str,
    artifact_path: str,
    rendered_at: str | None = None,
    instructions: list[dict] | None = None,
    planner_outputs: list[dict] | None = None,
) -> dict:
    """
    POST render result to Laravel benchmark API.

    render_uuid is client-generated — use the UUID from the pipeline JSON payload.
    Same UUID on retry = idempotent (no duplicate rows).

    instructions list format:
      [{"catalog_code": "slow_orbit", "beat": "payoff", "variant_text": "..."}]

    planner_outputs list format:
      [{"planner_name": "CameraMotivationPlanner", "beat": "hook", "raw_text": "..."}]

    Returns the JSON response dict from Laravel (includes render_uuid, annotation_url).
    Raises requests.HTTPError on non-2xx response.
    """
    payload = {
        "render_uuid":      render_uuid,
        "session_code":     session_code,
        "fixture_slug":     fixture_slug,
        "model":            model,
        "resolution":       resolution,
        "duration_seconds": duration_seconds,
        "fps":              fps,
        "seed":             seed,
        "char_count":       char_count,
        "prompt_version":   prompt_version,
        "artifact_path":    artifact_path,
        "rendered_at":      rendered_at or datetime.now(timezone.utc).isoformat(),
        "instructions":     instructions or [],
        "planner_outputs":  planner_outputs or [],
    }

    resp = requests.post(API_URL, json=payload, headers=_auth_headers(), timeout=API_TIMEOUT)
    resp.raise_for_status()
    return resp.json()


def submit_from_payload(payload_json_path: str, artifact_path: str | None = None) -> dict:
    """
    Submit a render using the JSON output from:
      php artisan benchmark:generate-prompt --json > payload.json

    The payload already contains render_uuid, prompt_text, instructions, planner_outputs.
    Only artifact_path needs to be supplied after the render is done.

    Args:
        payload_json_path: Path to the JSON file from benchmark:generate-prompt --json
        artifact_path:     Where the rendered MP4 was saved (overrides payload value)
    """
    with open(payload_json_path, encoding="utf-8-sig") as f:
        payload = json.load(f)

    return submit_render(
        render_uuid      = payload["render_uuid"],
        session_code     = payload["session_code"],
        fixture_slug     = payload["fixture_slug"],
        model            = payload["model"],
        resolution       = payload.get("resolution", "1080p"),
        duration_seconds = payload["duration_seconds"],
        fps              = payload.get("fps", 24),
        seed             = payload.get("seed"),
        char_count       = payload["char_count"],
        prompt_version   = payload["prompt_version"],
        artifact_path    = artifact_path or payload.get("artifact_path", ""),
        rendered_at      = payload.get("rendered_at"),
        instructions     = payload.get("instructions", []),
        planner_outputs  = payload.get("planner_outputs", []),
    )


def submit_from_artifact(artifact_dir: str) -> dict:
    """
    Read metadata.json + instructions.json from artifact directory and submit.

    Expected artifact structure:
        artifact_dir/
            metadata.json        — render metadata (session, fixture, model, etc.)
            instructions.json    — instruction instances list
            planner_outputs.json — planner raw outputs list
            prompt_actual.txt    — exact prompt sent to Kling
            video.mp4            — rendered video
    """
    base = Path(artifact_dir)

    metadata_path = base / "metadata.json"
    if not metadata_path.exists():
        raise FileNotFoundError(f"metadata.json not found in {artifact_dir}")

    with open(metadata_path) as f:
        meta = json.load(f)

    instructions = []
    inst_path = base / "instructions.json"
    if inst_path.exists():
        with open(inst_path) as f:
            instructions = json.load(f)

    planner_outputs = []
    po_path = base / "planner_outputs.json"
    if po_path.exists():
        with open(po_path) as f:
            planner_outputs = json.load(f)

    return submit_render(
        render_uuid      = meta["render_uuid"],
        session_code     = meta["session_code"],
        fixture_slug     = meta["fixture_slug"],
        model            = meta["model"],
        resolution       = meta.get("resolution", "1080p"),
        duration_seconds = meta["duration_seconds"],
        fps              = meta.get("fps", 24),
        seed             = meta.get("seed"),
        char_count       = meta["char_count"],
        prompt_version   = meta["prompt_version"],
        artifact_path    = artifact_dir,
        rendered_at      = meta.get("rendered_at"),
        instructions     = instructions,
        planner_outputs  = planner_outputs,
    )


# ---------------------------------------------------------------------------
# CLI entry point
# ---------------------------------------------------------------------------

def _print_result(result: dict) -> None:
    print(f"\n[OK] Render recorded")
    print(f"  render_uuid:    {result['render_uuid']}")
    print(f"  render_id:      {result['render_id']}")
    print(f"  already_existed:{result.get('already_existed', False)}")
    print(f"  instructions:   {result.get('instruction_count', 0)}")
    print(f"  annotation_url: {result.get('annotation_url', 'N/A')}")


if __name__ == "__main__":
    if len(sys.argv) < 2:
        print(f"Usage:")
        print(f"  python {sys.argv[0]} submit-payload <payload.json> [--artifact=<path>]")
        print(f"  python {sys.argv[0]} <artifact_dir>")
        sys.exit(1)

    cmd = sys.argv[1]

    try:
        if cmd == "submit-payload":
            if len(sys.argv) < 3:
                print("Usage: python benchmark_client.py submit-payload <payload.json> [--artifact=<path>]")
                sys.exit(1)
            payload_path = sys.argv[2]
            artifact_override = None
            for arg in sys.argv[3:]:
                if arg.startswith("--artifact="):
                    artifact_override = arg.split("=", 1)[1]
            print(f"Submitting from payload: {payload_path}")
            result = submit_from_payload(payload_path, artifact_override)
        else:
            # Legacy: first arg is artifact directory
            artifact_dir = cmd
            print(f"Submitting render result from: {artifact_dir}")
            result = submit_from_artifact(artifact_dir)

        _print_result(result)

    except requests.HTTPError as e:
        print(f"\n[ERR] API error {e.response.status_code}: {e.response.text}")
        sys.exit(1)
    except Exception as e:
        print(f"\n[ERR] {e}")
        sys.exit(1)
