<?php

/*
 * Phase 12 — Social Publishing, for the moto workflow. Three phases, each read through an action declared for the
 * workflow and not for a release, so a project of any release resolves them:
 *
 *   phase-12            the prompt a model writes one package from          → SocialPublishingPlan 1.0.0
 *   phase-12-review     the prompt a model reviews one package with         → SocialPublishingReview 1.0.0
 *   phase-12-platforms  what each format of each platform takes, read by PHP → SocialPlatformPolicy 1.0.0 in, SocialPackage 1.0.0 out
 *
 * The two prompts are the files under resources/ai/social/prompts; the policy is frozen below. A change of any of the
 * three is a new revision in a file of its own, never an edit of what is stored.
 */

$at = '2026-11-22 12:00:00';
$root = dirname(__DIR__, 3).'/';
$schema = static function (string $id, string $key, string $name, string $file, string $note) use ($at, $root): array {
    $path = 'resources/ai/social/schemas/'.$file;
    $content = (string) file_get_contents($root.$path);

    return [
        'id' => $id,
        'namespace' => 'social',
        'schema_key' => $key,
        'version' => '1.0.0',
        'name' => $name,
        'schema_content' => $content,
        'schema_sha256' => hash('sha256', $content),
        'source_file' => $path,
        'json_schema_id' => 'https://superyacht.local/schemas/'.$key,
        'json_schema_dialect' => 'https://json-schema.org/draft/2020-12/schema',
        'dependencies' => '[]',
        'status' => 'published',
        'change_note' => $note,
        'created_by' => 'db:seed AiPhaseSeeder',
        'created_at' => $at,
        'published_at' => $at,
    ];
};
$phase = static function (string $id, string $code, string $name, string $content, ?string $file, string $note) use ($at): array {
    return [
        'id' => $id,
        'category' => 'moto',
        'workflow_code' => 'harley_custom_v1',
        'phase_code' => $code,
        'kind' => 'phase',
        'name' => $name,
        'sort_order' => 12,
        'needs' => null,
        'parallel_with' => null,
        'stop' => null,
        'status' => 'published',
        'source_file' => $file,
        'source_section_key' => null,
        'created_by' => 'db:seed AiPhaseSeeder',
        'revision' => 1,
        'prompt_content' => $content,
        'content_sha256' => hash('sha256', $content),
        'is_current' => 1,
        'based_on_id' => null,
        'change_note' => $note,
        'created_at' => $at,
        'published_at' => $at,
    ];
};
$link = static function (string $id, string $phaseId, string $schemaId, string $role, string $slot) use ($at): array {
    return [
        'id' => $id,
        'phase_id' => $phaseId,
        'schema_id' => $schemaId,
        'role' => $role,
        'slot_key' => $slot,
        'data_pointer' => '',
        'validation_mode' => 'whole',
        'is_required' => 1,
        'created_at' => $at,
    ];
};

// What each format takes. PHP reads it from the DB; no model reads it as an instruction. Frozen here: a change is a new revision.
// A value the pages read on checked_on do not give is null and nothing is checked for it; `unverified` names what was kept from earlier knowledge.
$policy = <<<'JSON'
{
  "format": "social-platform-policy-v1",
  "schema": "SocialPlatformPolicy@1.0.0",
  "package": "SocialPackage@1.0.0",
  "checked_on": "2026-11-22",
  "formats": {
    "facebook_reel": {
      "platform": "facebook",
      "label": "Facebook Reel",
      "api": {
        "name": "Facebook Graph API — Page Reels",
        "version": "v27.0",
        "adapter": "facebook-reels-v1"
      },
      "documents": [
        "https://developers.facebook.com/docs/video-api/guides/reels-publishing",
        "https://developers.facebook.com/docs/graph-api/reference/video",
        "https://developers.facebook.com/docs/graph-api/reference/page/video_reels",
        "https://developers.facebook.com/docs/graph-api/reference/video/video_insights/",
        "https://developers.facebook.com/docs/pages-api/getting-started",
        "https://developers.facebook.com/docs/facebook-login/guides/advanced/manual-flow"
      ],
      "unverified": [],
      "media": {
        "containers": ["mp4"],
        "orientation": "portrait",
        "classification": null,
        "aspect": { "width": 9, "height": 16, "tolerance_percent": 1 },
        "min_width": 540,
        "min_height": 960,
        "min_fps": 24,
        "max_fps": 60,
        "min_duration_ms": 3000,
        "max_duration_ms": 90000,
        "max_bytes": null
      },
      "text": {
        "title": { "used": true, "required": false, "max_chars": null, "max_bytes": null, "forbidden": [] },
        "body": { "used": true, "required": false, "max_chars": null, "max_bytes": null, "forbidden": [] },
        "hashtags": { "used": true, "max_count": null },
        "keywords": { "used": false, "max_total_chars": null }
      },
      "cover": {
        "width": 1080,
        "height": 1920,
        "mime": "image/jpeg",
        "max_bytes": null,
        "api": false,
        "note": "The Reels publishing guide read on checked_on links to a custom cover without describing the request, so this adapter does not set one: the cover file is exported and a person sets it on the platform."
      },
      "schedule": {
        "supported": true,
        "owner": "platform",
        "min_lead_seconds": 600,
        "max_lead_seconds": 2505600,
        "requires_privacy": null,
        "cancel": {
          "api": false,
          "note": "The Reels guide and the Video reference read on checked_on document no request that cancels or moves the schedule of a Reel, and the video_reels edge cannot be updated or deleted: a person cancels it in Meta Business Suite and records it here."
        }
      },
      "metadata": { "privacy": [], "default_privacy": null, "made_for_kids": false },
      "synthetic_media": {
        "api": false,
        "note": "The Reels publishing guide read on checked_on names no field for an AI or altered-content label, so this adapter makes no such declaration: a person makes it on the platform."
      },
      "metrics": {
        "source": "Graph API v27.0 — GET /{video-id}/video_insights (lifetime)",
        "items": [
          { "id": "blue_reels_play_count", "label": "Facebook — blue_reels_play_count (lượt phát Reel)", "group": "views", "unit": "count", "shape": "number" },
          { "id": "fb_reels_replay_count", "label": "Facebook — fb_reels_replay_count (lượt phát lại)", "group": "views", "unit": "count", "shape": "number" },
          { "id": "fb_reels_total_plays", "label": "Facebook — fb_reels_total_plays (tổng lượt phát)", "group": "views", "unit": "count", "shape": "number" },
          { "id": "post_impressions_unique", "label": "Facebook — post_impressions_unique (tài khoản tiếp cận)", "group": "reach", "unit": "count", "shape": "number" },
          { "id": "post_video_view_time", "label": "Facebook — post_video_view_time (tổng thời gian xem)", "group": "watch_time", "unit": "milliseconds", "shape": "number" },
          { "id": "post_video_avg_time_watched", "label": "Facebook — post_video_avg_time_watched (thời gian xem trung bình)", "group": "retention", "unit": "milliseconds", "shape": "number" },
          { "id": "post_video_social_actions", "label": "Facebook — post_video_social_actions (bình luận, chia sẻ)", "group": "engagement", "unit": "count", "shape": "map" },
          { "id": "post_video_likes_by_reaction_type", "label": "Facebook — post_video_likes_by_reaction_type (cảm xúc theo loại)", "group": "engagement", "unit": "count", "shape": "map" },
          { "id": "post_video_followers", "label": "Facebook — post_video_followers (lượt theo dõi từ bài)", "group": "followers", "unit": "count", "shape": "number" }
        ]
      },
      "account_notes": [
        "A Page publishes at most 30 Reels through the API in 24 hours.",
        "The connection needs pages_show_list, pages_read_engagement and pages_manage_posts on the Page; reading numbers also needs read_insights.",
        "A scheduled Reel is published by Facebook at a time more than 10 minutes and less than 29 days ahead."
      ]
    },
    "youtube_short": {
      "platform": "youtube",
      "label": "YouTube Short",
      "api": {
        "name": "YouTube Data API v3 — videos.insert (resumable upload)",
        "version": "v3",
        "adapter": "youtube-videos-v1"
      },
      "documents": [
        "https://developers.google.com/youtube/v3/docs/videos/insert",
        "https://developers.google.com/youtube/v3/docs/videos",
        "https://developers.google.com/youtube/v3/docs/videos/update",
        "https://developers.google.com/youtube/v3/guides/using_resumable_upload_protocol",
        "https://developers.google.com/youtube/data/reference/rest/v3/thumbnails/set",
        "https://developers.google.com/youtube/analytics/reference/reports/query",
        "https://developers.google.com/youtube/analytics/metrics",
        "https://developers.google.com/identity/protocols/oauth2/web-server",
        "https://support.google.com/youtube/answer/15424877"
      ],
      "unverified": [
        "cover.max_bytes: this is the ceiling the cover is made under, chosen below every limit the thumbnails.set page names.",
        "schedule.min_lead_seconds: 15 minutes is a margin chosen here; the pages read name no minimum for publishAt.",
        "schedule.cancel: no page names a request that takes a schedule back. videos.update deletes a property its request leaves out, so the adapter sends the status read from the video with privacyStatus private and no publishAt, and counts the schedule as cancelled only when the video reads back with none."
      ],
      "media": {
        "containers": ["mp4"],
        "orientation": "any",
        "classification": { "name": "YouTube Shorts", "orientation": "portrait_or_square", "max_duration_ms": 180000, "member": true, "instead": "YouTube Video" },
        "aspect": null,
        "min_width": null,
        "min_height": null,
        "min_fps": null,
        "max_fps": null,
        "min_duration_ms": null,
        "max_duration_ms": null,
        "max_bytes": 274877906944
      },
      "text": {
        "title": { "used": true, "required": true, "max_chars": 100, "max_bytes": null, "forbidden": ["<", ">"] },
        "body": { "used": true, "required": false, "max_chars": null, "max_bytes": 5000, "forbidden": ["<", ">"] },
        "hashtags": { "used": true, "max_count": null },
        "keywords": { "used": true, "max_total_chars": 500 }
      },
      "cover": {
        "width": 1080,
        "height": 1920,
        "mime": "image/jpeg",
        "max_bytes": 2097152,
        "api": true,
        "note": "Set with thumbnails.set once YouTube has finished processing the video. Whether a channel may set a custom thumbnail is answered by YouTube when the request is made; a refusal is recorded on that step and the video stays as posted."
      },
      "schedule": {
        "supported": true,
        "owner": "platform",
        "min_lead_seconds": 900,
        "max_lead_seconds": null,
        "requires_privacy": "public",
        "cancel": {
          "api": true,
          "note": "videos.update with part=status: the writable status fields read from the video are sent back with privacyStatus private and no publishAt, then the video is read again; needs the youtube.force-ssl scope. The video stays on the channel as private."
        }
      },
      "metadata": { "privacy": ["public", "unlisted", "private"], "default_privacy": "public", "made_for_kids": true },
      "synthetic_media": {
        "api": true,
        "note": "Sent as status.containsSyntheticMedia."
      },
      "metrics": {
        "source": "YouTube Analytics API v2 — reports.query, filters=video==ID",
        "items": [
          { "id": "views", "label": "YouTube — views (lượt xem)", "group": "views", "unit": "count", "shape": "number" },
          { "id": "estimatedMinutesWatched", "label": "YouTube — estimatedMinutesWatched (số phút xem)", "group": "watch_time", "unit": "minutes", "shape": "number" },
          { "id": "averageViewDuration", "label": "YouTube — averageViewDuration (thời gian xem trung bình)", "group": "retention", "unit": "seconds", "shape": "number" },
          { "id": "averageViewPercentage", "label": "YouTube — averageViewPercentage (tỷ lệ xem trung bình)", "group": "retention", "unit": "percent", "shape": "number" },
          { "id": "likes", "label": "YouTube — likes", "group": "engagement", "unit": "count", "shape": "number" },
          { "id": "comments", "label": "YouTube — comments", "group": "engagement", "unit": "count", "shape": "number" },
          { "id": "shares", "label": "YouTube — shares", "group": "engagement", "unit": "count", "shape": "number" },
          { "id": "subscribersGained", "label": "YouTube — subscribersGained (người đăng ký mới)", "group": "followers", "unit": "count", "shape": "number" }
        ]
      },
      "account_notes": [
        "videos.insert draws on the daily upload allowance of the API project (documented as 100 calls a day); thumbnails.set costs about 50 quota units.",
        "A video scheduled with publishAt is uploaded as private and made public by YouTube at that time.",
        "The connection needs the youtube.upload scope; reading numbers needs youtube.readonly or yt-analytics.readonly."
      ]
    },
    "youtube_video": {
      "platform": "youtube",
      "label": "YouTube Video",
      "api": {
        "name": "YouTube Data API v3 — videos.insert (resumable upload)",
        "version": "v3",
        "adapter": "youtube-videos-v1"
      },
      "documents": [
        "https://developers.google.com/youtube/v3/docs/videos/insert",
        "https://developers.google.com/youtube/v3/docs/videos",
        "https://developers.google.com/youtube/v3/docs/videos/update",
        "https://developers.google.com/youtube/v3/guides/using_resumable_upload_protocol",
        "https://developers.google.com/youtube/data/reference/rest/v3/thumbnails/set",
        "https://developers.google.com/youtube/analytics/reference/reports/query",
        "https://developers.google.com/youtube/analytics/metrics",
        "https://developers.google.com/identity/protocols/oauth2/web-server",
        "https://support.google.com/youtube/answer/15424877"
      ],
      "unverified": [
        "cover.max_bytes: this is the ceiling the cover is made under, chosen below every limit the thumbnails.set page names.",
        "schedule.min_lead_seconds: 15 minutes is a margin chosen here; the pages read name no minimum for publishAt.",
        "schedule.cancel: no page names a request that takes a schedule back. videos.update deletes a property its request leaves out, so the adapter sends the status read from the video with privacyStatus private and no publishAt, and counts the schedule as cancelled only when the video reads back with none."
      ],
      "media": {
        "containers": ["mp4"],
        "orientation": "any",
        "classification": { "name": "YouTube Shorts", "orientation": "portrait_or_square", "max_duration_ms": 180000, "member": false, "instead": "YouTube Short" },
        "aspect": null,
        "min_width": null,
        "min_height": null,
        "min_fps": null,
        "max_fps": null,
        "min_duration_ms": null,
        "max_duration_ms": null,
        "max_bytes": 274877906944
      },
      "text": {
        "title": { "used": true, "required": true, "max_chars": 100, "max_bytes": null, "forbidden": ["<", ">"] },
        "body": { "used": true, "required": false, "max_chars": null, "max_bytes": 5000, "forbidden": ["<", ">"] },
        "hashtags": { "used": true, "max_count": null },
        "keywords": { "used": true, "max_total_chars": 500 }
      },
      "cover": {
        "width": 1280,
        "height": 720,
        "mime": "image/jpeg",
        "max_bytes": 2097152,
        "api": true,
        "note": "Set with thumbnails.set once YouTube has finished processing the video. Whether a channel may set a custom thumbnail is answered by YouTube when the request is made; a refusal is recorded on that step and the video stays as posted."
      },
      "schedule": {
        "supported": true,
        "owner": "platform",
        "min_lead_seconds": 900,
        "max_lead_seconds": null,
        "requires_privacy": "public",
        "cancel": {
          "api": true,
          "note": "videos.update with part=status: the writable status fields read from the video are sent back with privacyStatus private and no publishAt, then the video is read again; needs the youtube.force-ssl scope. The video stays on the channel as private."
        }
      },
      "metadata": { "privacy": ["public", "unlisted", "private"], "default_privacy": "public", "made_for_kids": true },
      "synthetic_media": {
        "api": true,
        "note": "Sent as status.containsSyntheticMedia."
      },
      "metrics": {
        "source": "YouTube Analytics API v2 — reports.query, filters=video==ID",
        "items": [
          { "id": "views", "label": "YouTube — views (lượt xem)", "group": "views", "unit": "count", "shape": "number" },
          { "id": "estimatedMinutesWatched", "label": "YouTube — estimatedMinutesWatched (số phút xem)", "group": "watch_time", "unit": "minutes", "shape": "number" },
          { "id": "averageViewDuration", "label": "YouTube — averageViewDuration (thời gian xem trung bình)", "group": "retention", "unit": "seconds", "shape": "number" },
          { "id": "averageViewPercentage", "label": "YouTube — averageViewPercentage (tỷ lệ xem trung bình)", "group": "retention", "unit": "percent", "shape": "number" },
          { "id": "likes", "label": "YouTube — likes", "group": "engagement", "unit": "count", "shape": "number" },
          { "id": "comments", "label": "YouTube — comments", "group": "engagement", "unit": "count", "shape": "number" },
          { "id": "shares", "label": "YouTube — shares", "group": "engagement", "unit": "count", "shape": "number" },
          { "id": "subscribersGained", "label": "YouTube — subscribersGained (người đăng ký mới)", "group": "followers", "unit": "count", "shape": "number" }
        ]
      },
      "account_notes": [
        "videos.insert draws on the daily upload allowance of the API project (documented as 100 calls a day); thumbnails.set costs about 50 quota units.",
        "A video scheduled with publishAt is uploaded as private and made public by YouTube at that time.",
        "The connection needs the youtube.upload scope; reading numbers needs youtube.readonly or yt-analytics.readonly."
      ]
    }
  }
}
JSON;

$write = (string) file_get_contents($root.'resources/ai/social/prompts/phase-12.md');
$review = (string) file_get_contents($root.'resources/ai/social/prompts/phase-12-review.md');

return [
    'schemas' => [
        $schema('d7cedae4-becd-496d-ac85-400417d78ba7', 'SocialPublishingPlan', 'Phase 12 — what a model proposes for one package (social-publishing-plan-v1)', 'SocialPublishingPlan-1.0.0.schema.json', 'The answer of the model that writes one package: strategy, hooks, copy, claims with the sources each rests on, cover proposals by the id of an asset of the input, and what a person must confirm. Channel, platform, format and language are given by the input and are no field here. null for a text the format does not use, [] for an empty list, no empty string.'),
        $schema('319ef93d-2aa9-492c-bdb5-f87645618869', 'SocialPublishingReview', 'Phase 12 — what a model found in one package (social-publishing-review-v1)', 'SocialPublishingReview-1.0.0.schema.json', 'The answer of the model that reviews one package revision against the sources of its film: findings with where, how much it matters, the sources and a suggestion in words, and what it could not review. It changes and approves nothing.'),
        $schema('ae69937b-d017-42f9-8939-efb4a518e942', 'SocialPackage', 'Phase 12 — the package that is approved, exported and posted (social-package-v1)', 'SocialPackage-1.0.0.schema.json', 'Assembled by code from what a person edited: the film by its hash, the target, the final text as posted, the cover, the disclosure choices and the metadata of the posting, under the platform policy it names. Its hash is the package hash. No model writes it.'),
        $schema('5af7423e-a072-4384-9873-93a55dc2d786', 'SocialPlatformPolicy', 'Phase 12 — what each format of each platform takes (social-platform-policy-v1)', 'SocialPlatformPolicy-1.0.0.schema.json', 'Stored by phase-12-platforms as its prompt_content and read by PHP alone: media, text limits, cover, scheduling, metadata, the synthetic-content declaration, the numbers a platform gives and the pages all of it was read from, with the day they were read.'),
    ],
    'phases' => [
        $phase('0ce7becd-de4d-4c71-afd5-9f44f0459c26', 'phase-12', 'Phase 12 — Social Publishing: write the package for one channel', $write, 'resources/ai/social/prompts/phase-12.md', 'social-publishing-plan-v1 from social-publishing-input-v1, read through action spec.social_package: for one channel, one format and one language a model proposes the strategy, the hooks, the copy, the claims with their sources and the covers, from the sources of the approved film alone. It chooses no channel, no time and no visibility, writes no link and no disclosure sentence; code adds those and a person edits and approves.'),
        $phase('37f7900b-7a97-4770-b97a-47d9b4f58d06', 'phase-12-review', 'Phase 12 — Social Publishing: review one package', $review, 'resources/ai/social/prompts/phase-12-review.md', 'social-publishing-review-v1 from social-publishing-review-input-v1, read through action spec.social_review: a model reads one package revision against the sources of its film and reports findings. It runs only when a person asks, changes nothing and approves nothing; a package never reviewed says so.'),
        $phase('304b261f-85cd-48bd-a1c4-1c19daeecb0f', 'phase-12-platforms', 'Phase 12 — platform policy (read by code, no model)', $policy, null, 'social-platform-policy-v1, read through action spec.social_publish with executor none, from the documentation read on 2026-11-22: Facebook Reel (mp4, 9:16, at least 540×960, 24–60 fps, 3–90 s; description and title; scheduled by Facebook 10 minutes to 29 days ahead; cover and AI label set by hand), YouTube Short and YouTube Video told apart the way YouTube sorts an upload — a square or vertical file of up to 3 minutes is a Short, any other is a video (title up to 100 characters, description up to 5000 bytes, tags up to 500 characters, no < or >; scheduled by YouTube with publishAt, a schedule taken back with videos.update and read back; thumbnail and containsSyntheticMedia through the API). A Reel schedule is cancelled by a person. Values kept from earlier knowledge are listed under unverified. Limits of an account are notes for a person and are checked by the platform, not here.'),
    ],
    'links' => [
        $link('1443a8c0-a111-4d22-9234-79cbf9c28592', '0ce7becd-de4d-4c71-afd5-9f44f0459c26', 'd7cedae4-becd-496d-ac85-400417d78ba7', 'output', 'social_plan'),
        $link('6189c10a-d799-49ef-a52d-8426fe50c357', '37f7900b-7a97-4770-b97a-47d9b4f58d06', '319ef93d-2aa9-492c-bdb5-f87645618869', 'output', 'social_review'),
        $link('76b18954-96ce-4401-b4c8-569301c414d9', '304b261f-85cd-48bd-a1c4-1c19daeecb0f', '5af7423e-a072-4384-9873-93a55dc2d786', 'input', 'platform_policy'),
        $link('882436a5-6657-4e12-8a60-a331e5f33d2c', '304b261f-85cd-48bd-a1c4-1c19daeecb0f', 'ae69937b-d017-42f9-8939-efb4a518e942', 'output', 'social_package'),
    ],
];
