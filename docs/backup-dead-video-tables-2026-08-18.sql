-- Backup 5 bang video chet truoc migration 2026_08_18_100000
-- Tao luc 2026-08-18 08:01:53 tren news24h
-- Phuc hoi: mysql -u<user> -p <db> < file nay

SET FOREIGN_KEY_CHECKS=0;

-- ===== video_claude_calls =====
DROP TABLE IF EXISTS `video_claude_calls`;
CREATE TABLE `video_claude_calls` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `article_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `stage` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `model_used` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `input_tokens` int(10) unsigned NOT NULL DEFAULT 0,
  `output_tokens` int(10) unsigned NOT NULL DEFAULT 0,
  `cost_usd` decimal(10,6) NOT NULL DEFAULT 0.000000,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `video_claude_calls_article_id_stage_index` (`article_id`,`stage`),
  CONSTRAINT `video_claude_calls_article_id_foreign` FOREIGN KEY (`article_id`) REFERENCES `articles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `video_claude_calls` (`id`,`article_id`,`stage`,`model_used`,`input_tokens`,`output_tokens`,`cost_usd`,`created_at`,`updated_at`) VALUES ('a2232758-32ae-4cbd-b945-a7753b08cefb','a1c8be06-4fc7-41d7-9dba-adde53b56454','fact_extractor','haiku','1065','1090','0.005212','2026-06-29 07:37:56','2026-06-29 07:37:56');
INSERT INTO `video_claude_calls` (`id`,`article_id`,`stage`,`model_used`,`input_tokens`,`output_tokens`,`cost_usd`,`created_at`,`updated_at`) VALUES ('a223277b-872b-4e52-99c4-0713494ecd73','a1c8be06-4fc7-41d7-9dba-adde53b56454','fact_extractor','sonnet','1066','1705','0.028773','2026-06-29 07:38:19','2026-06-29 07:38:19');
INSERT INTO `video_claude_calls` (`id`,`article_id`,`stage`,`model_used`,`input_tokens`,`output_tokens`,`cost_usd`,`created_at`,`updated_at`) VALUES ('a223284d-6bfd-45a2-b112-e56b4f0d9e87','a2231f6f-ae93-42e7-846a-5860a2ef7308','fact_extractor','haiku','1801','2529','0.011557','2026-06-29 07:40:37','2026-06-29 07:40:37');
INSERT INTO `video_claude_calls` (`id`,`article_id`,`stage`,`model_used`,`input_tokens`,`output_tokens`,`cost_usd`,`created_at`,`updated_at`) VALUES ('a223288e-4506-43a2-ba6c-919caf4a1917','a2232036-c9c4-4fe6-a254-62761655f194','fact_extractor','haiku','1172','1329','0.006254','2026-06-29 07:41:19','2026-06-29 07:41:19');
INSERT INTO `video_claude_calls` (`id`,`article_id`,`stage`,`model_used`,`input_tokens`,`output_tokens`,`cost_usd`,`created_at`,`updated_at`) VALUES ('a22328cf-e13f-4a3f-a045-a91b4d62eba4','a2231efd-ce78-48b7-87a2-db5c1441d829','fact_extractor','haiku','1703','1263','0.006414','2026-06-29 07:42:02','2026-06-29 07:42:02');
INSERT INTO `video_claude_calls` (`id`,`article_id`,`stage`,`model_used`,`input_tokens`,`output_tokens`,`cost_usd`,`created_at`,`updated_at`) VALUES ('a2232915-ff62-408e-bdfa-1958c294d291','a2231ecb-d43f-4a74-aa29-a415fd2489ed','fact_extractor','haiku','1429','2247','0.010131','2026-06-29 07:42:48','2026-06-29 07:42:48');
INSERT INTO `video_claude_calls` (`id`,`article_id`,`stage`,`model_used`,`input_tokens`,`output_tokens`,`cost_usd`,`created_at`,`updated_at`) VALUES ('a228e3d9-b588-4c79-b7e5-179a40030a65','a1c8b895-eeb0-401a-9960-8a6afba9a8da','fact_extractor','haiku','1265','521','0.003096','2026-07-02 04:04:11','2026-07-02 04:04:11');
INSERT INTO `video_claude_calls` (`id`,`article_id`,`stage`,`model_used`,`input_tokens`,`output_tokens`,`cost_usd`,`created_at`,`updated_at`) VALUES ('a228e3f2-8f3d-4737-a315-36a487b0a79c','a1c8b895-eeb0-401a-9960-8a6afba9a8da','fact_extractor','sonnet','1266','1102','0.020328','2026-07-02 04:04:27','2026-07-02 04:04:27');
INSERT INTO `video_claude_calls` (`id`,`article_id`,`stage`,`model_used`,`input_tokens`,`output_tokens`,`cost_usd`,`created_at`,`updated_at`) VALUES ('a2370337-dde9-4a1b-8b6e-36bb406d863b','a236e915-8bc4-4c97-a038-5e9184f440ea','fact_extractor','haiku','1397','1971','0.009002','2026-07-09 04:33:28','2026-07-09 04:33:28');
INSERT INTO `video_claude_calls` (`id`,`article_id`,`stage`,`model_used`,`input_tokens`,`output_tokens`,`cost_usd`,`created_at`,`updated_at`) VALUES ('a2370345-1866-46dd-a191-0ecd5efccd9e','a236e915-8bc4-4c97-a038-5e9184f440ea','story_planner','sonnet','1187','373','0.009156','2026-07-09 04:33:37','2026-07-09 04:33:37');
INSERT INTO `video_claude_calls` (`id`,`article_id`,`stage`,`model_used`,`input_tokens`,`output_tokens`,`cost_usd`,`created_at`,`updated_at`) VALUES ('a237034c-6309-4607-99ff-5270ffcde76d','a236e915-8bc4-4c97-a038-5e9184f440ea','script_generator','haiku','2728','468','0.004054','2026-07-09 04:33:42','2026-07-09 04:33:42');
-- 11 hang

-- ===== video_jobs =====
DROP TABLE IF EXISTS `video_jobs`;
CREATE TABLE `video_jobs` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `story_plan_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `part_number` tinyint(3) unsigned NOT NULL,
  `status` enum('script_ready','claimed','rendering','quality_check_passed','quality_check_failed','uploaded','upload_failed') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'script_ready',
  `approval_status` enum('pending_review','approved','rejected','regenerating') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reviewed_by` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `rejection_note` text COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `script_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`script_json`)),
  `claimed_by` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `claimed_at` timestamp NULL DEFAULT NULL,
  `cost_total` decimal(10,6) NOT NULL DEFAULT 0.000000,
  `video_path` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `thumbnail_path` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `youtube_video_id` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `facebook_post_id` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tiktok_post_id` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `instagram_post_id` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `error_message` text COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `video_jobs_story_plan_id_part_number_unique` (`story_plan_id`,`part_number`),
  KEY `video_jobs_status_index` (`status`),
  KEY `video_jobs_reviewed_by_foreign` (`reviewed_by`),
  CONSTRAINT `video_jobs_reviewed_by_foreign` FOREIGN KEY (`reviewed_by`) REFERENCES `admins` (`id`) ON DELETE SET NULL,
  CONSTRAINT `video_jobs_story_plan_id_foreign` FOREIGN KEY (`story_plan_id`) REFERENCES `story_plans` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `video_jobs` (`id`,`story_plan_id`,`part_number`,`status`,`approval_status`,`reviewed_by`,`reviewed_at`,`rejection_note`,`script_json`,`claimed_by`,`claimed_at`,`cost_total`,`video_path`,`thumbnail_path`,`youtube_video_id`,`facebook_post_id`,`tiktok_post_id`,`instagram_post_id`,`error_message`,`created_at`,`updated_at`) VALUES ('a237034c-659f-4354-bb23-12cb0b90d629','a2370345-1a2a-456e-a317-b67dac7fc1cc','1','quality_check_failed','pending_review',NULL,NULL,NULL,'{\"hook\":\"Animal groups to buy 1,500 beagles from Wisconsin research breeder after protests\",\"cta\":\"Follow for more stories of animals getting the endings they deserve \\u2014 and drop a \\ud83d\\udc3e if you\'re rooting for every single one of these beagles!\",\"target_seconds\":15,\"scenes\":[{\"scene_id\":\"s1\",\"beat\":\"dramatic\",\"narration\":\"After 10 years of fighting, nearly 1,500 beagles are finally leaving a Wisconsin breeding facility. Now they\'ll get medical care, vaccinations, and their first real homes.\",\"visual_description\":\"Close-up of a young beagle with large soulful brown eyes, soft tricolor fur in white, tan, and black, gently rounded muzzle, and floppy velvet ears. The dog\'s eyes catch warm golden light as it gazes directly at camera, filling the frame with shallow depth of field and soft bokeh background.\",\"image_prompt\":\"A close-up portrait of a young beagle with large soulful brown eyes, soft tricolor fur in white, tan, and black, a gently rounded muzzle, and floppy velvet ears, photographed in warm golden natural light with a shallow depth of field and soft bokeh background\",\"fact_refs\":[\"f1\",\"f3\",\"f11\",\"f7\"]},{\"scene_id\":\"s2\",\"beat\":\"end\",\"narration\":\"Follow for more stories of animals getting the endings they deserve. Drop a \\ud83d\\udc3e for these beagles.\",\"visual_description\":\"Close-up of the same young beagle\'s face filling the frame, eyes catching golden light, ears relaxed and soft, expression calm and trusting. Warm, gentle lighting emphasizing the dog\'s gentle nature.\",\"image_prompt\":\"A close-up portrait of a young beagle with large soulful brown eyes, soft tricolor fur in white, tan, and black, a gently rounded muzzle, and floppy velvet ears, photographed in warm golden natural light with a shallow depth of field and soft bokeh background\",\"fact_refs\":[\"f1\"]}]}','127.0.0.1','2026-07-09 08:41:44','0.000000',NULL,NULL,NULL,NULL,NULL,NULL,'duration_out_of_range (got 10.1s, expected ~15s)','2026-07-09 04:33:42','2026-07-09 08:45:37');
-- 1 hang

-- ===== video_analytics =====
DROP TABLE IF EXISTS `video_analytics`;
CREATE TABLE `video_analytics` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `video_job_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `platform` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `date` date NOT NULL,
  `views` bigint(20) unsigned NOT NULL DEFAULT 0,
  `watch_time_seconds` bigint(20) unsigned NOT NULL DEFAULT 0,
  `avg_view_duration` double(8,2) NOT NULL DEFAULT 0.00,
  `retention_rate` double(8,2) NOT NULL DEFAULT 0.00,
  `ctr` double(8,2) NOT NULL DEFAULT 0.00,
  `likes` int(10) unsigned NOT NULL DEFAULT 0,
  `comments` int(10) unsigned NOT NULL DEFAULT 0,
  `shares` int(10) unsigned NOT NULL DEFAULT 0,
  `saves` int(10) unsigned NOT NULL DEFAULT 0,
  `raw_payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`raw_payload`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `video_analytics_video_job_id_platform_date_unique` (`video_job_id`,`platform`,`date`),
  KEY `video_analytics_platform_date_index` (`platform`,`date`),
  CONSTRAINT `video_analytics_video_job_id_foreign` FOREIGN KEY (`video_job_id`) REFERENCES `video_jobs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 0 hang

-- ===== video_assets =====
DROP TABLE IF EXISTS `video_assets`;
CREATE TABLE `video_assets` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `shot_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `asset_type` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'image',
  `provider` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `remote_url` text COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `local_path` varchar(600) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `meta` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`meta`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `video_assets_shot_id_status_index` (`shot_id`,`status`),
  CONSTRAINT `video_assets_shot_id_foreign` FOREIGN KEY (`shot_id`) REFERENCES `video_shots` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 0 hang

-- ===== video_outputs =====
DROP TABLE IF EXISTS `video_outputs`;
CREATE TABLE `video_outputs` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `project_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `video_path` varchar(600) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `thumbnail_path` varchar(600) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `youtube_video_id` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `duration_seconds` smallint(5) unsigned DEFAULT NULL,
  `status` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `error_message` text COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `video_outputs_project_id_status_index` (`project_id`,`status`),
  CONSTRAINT `video_outputs_project_id_foreign` FOREIGN KEY (`project_id`) REFERENCES `video_projects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 0 hang

SET FOREIGN_KEY_CHECKS=1;
