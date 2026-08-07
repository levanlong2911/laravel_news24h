<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MOT LUOT RENDER LA SU KIEN DA XAY RA — khong lan sua nao ve sau duoc viet lai no.
 *
 * Bat bien nay khong suy ra tu sach; no la thu HONG THAT ngay 2026-08-07. Chay lai
 * `session_runner` de bo sung mot truong ke hoach da GHI DE `compiled_prompt`, trong
 * khi `artifact_path` giu nguyen. Ba dong chuoi trong DB khi do khoe mot prompt KHONG
 * HE sinh ra tam anh dung canh no. Khong loi, khong log.
 *
 * Nguyen nhan: `video_shots` dang ganh hai thu ban chat khac nhau —
 *
 *     trang thai HIEN TAI (doi duoc)   status, approved_at, compiled_prompt, render_plan
 *     su kien LICH SU (bat bien)       artifact_path, cost
 *
 * Tron chung thi sua cai thu nhat la bia lai cai thu hai. Migration nay tach chung ra.
 * `video_shots` VAN GIU cac cot cu va van la "current planning state" — khong xoa gi,
 * chi thoi bat no ke lich su.
 *
 * Chuoi truy nguyen sau khi tach:
 *
 *     article -> session -> shot -> render -> source render -> ... -> final
 *
 * Mo mot video hoan chinh la lan nguoc duoc: clip nao, shot nao, lan render thu may,
 * prompt gi, model gi, tu tam anh nao, tam anh do sinh tu render nao, chung minh trang
 * thai gi, het bao nhieu tien.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('video_renders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('shot_id');
            $table->foreign('shot_id')->references('id')->on('video_shots')->cascadeOnDelete();

            // Lan thu may cho CUNG mot shot. Render lai khong ghi de dong cu —
            // do la toan bo ly do bang nay ton tai.
            $table->unsignedSmallInteger('attempt_no')->default(1);

            // KHONG phai enum image|video: interpolation/upscale/audio/thumbnail deu
            // la nhung lan tieu tien co that sau nay, va them mot gia tri khong nen
            // phai chay migration.
            $table->string('render_kind', 20);              // image|video|audio|thumbnail|...
            $table->string('provider', 40);                 // fal|openai|...
            $table->string('model', 120);                   // openai/gpt-image-2/edit

            // NGUYEN VAN CHUOI DA GUI, khong phai chuoi trong ke hoach. Hai thu nay
            // duoc phep khac nhau: compiler co the cat bot, ghep them, an danh ten
            // rieng. Cai quyet dinh buc anh la cai cuoi cung roi khoi may.
            $table->longText('sent_prompt');
            $table->char('prompt_sha256', 64);
            $table->longText('negative_prompt')->nullable();

            /**
             * `request_sha256` KHAC `prompt_sha256`, va khac o cho quan trong:
             * cung mot cau chu voi seed khac / khung khac / anh nguon khac se ra ket
             * qua khac. Fingerprint ca request moi tra loi duoc "da render dung cai
             * nay chua" — tuc moi dung duoc cho retry va idempotency.
             */
            $table->char('request_sha256', 64)->nullable();

            /**
             * DO THI THAT, khong phai chuoi ky tu. `video_shots.artifact_path` khong
             * bieu dien duoc dieu nay: render lai `shell` thanh v2 thi motion cu van
             * phai tro ve v1 — dung tam anh da de ra no — con motion moi tro v2.
             */
            $table->uuid('source_render_id')->nullable();
            $table->foreign('source_render_id')->references('id')->on('video_renders')->nullOnDelete();
            $table->string('source_kind', 12)->nullable();  // state|anchor|text|render

            /**
             * HAI truong, khong phai mot `claimed_state` chung.
             *
             *   requires_state — lan render nay DUOC PHEP chay vi trang thai nao
             *   proves_state   — no CHUNG MINH trang thai nao cho lan sau
             *
             * Anh chuoi chi co `proves_state`; clip motion chi co `requires_state`.
             * Gop lam mot thi cau hoi kiem toan "duoc phep vi gi, chung minh duoc gi"
             * khong con tra loi duoc tu DB.
             */
            $table->string('requires_state', 40)->nullable();
            $table->string('proves_state', 40)->nullable();

            $table->string('artifact_path', 255)->nullable();  // /renders/shots/xxx.mp4
            $table->string('artifact_dir', 255)->nullable();   // work/artifacts/renders/<id>/<ts>

            $table->unsignedSmallInteger('width')->nullable();
            $table->unsignedSmallInteger('height')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->unsignedBigInteger('bytes')->nullable();

            // TIEN THAT da tra, khong phai uoc tinh. `video_shots.cost_estimate` la
            // uoc tinh va van o nguyen do — hai con so khac nhau, hai cot khac nhau.
            $table->decimal('cost_usd', 8, 4)->default(0);
            $table->unsignedInteger('provider_ms')->nullable();

            $table->string('status', 12)->default('pending'); // pending|running|succeeded|failed
            $table->text('error_message')->nullable();

            /**
             * Ho so canh anh (`proof` trong work/anchors/*.json) keo vao DB. Hien
             * resolver PHU THUOC HOAN TOAN vao nhung file do — xoa thu muc la no mu,
             * va da gap dung canh ay voi 3 tam anh thieu ho so ngay 2026-08-07.
             *
             * `proof_verified` chi duoc dat TRUE boi thu THAT SU soi pixel. Duong ghi
             * luc render khong soi gi ca, nen no luon ghi FALSE.
             */
            $table->string('proof_method', 24)->nullable();   // chain_definition|backfill|human_qa|vision_qa
            $table->boolean('proof_verified')->default(false);

            $table->timestamps();

            $table->unique(['shot_id', 'attempt_no']);
            $table->index(['status']);
            // Resolver hoi dung cau nay: "anh nao chung minh trang thai X".
            $table->index(['proves_state', 'status']);
        });

        Schema::create('video_finals', function (Blueprint $table) {
            $table->uuid('id')->primary();

            /**
             * Khoa theo SESSION, khong theo project. Cung mot project render lai lan
             * hai la MOT BAN FINAL KHAC, khong phai ban cu bi ghi de.
             */
            $table->uuid('session_id');
            $table->foreign('session_id')->references('id')->on('video_sessions')->cascadeOnDelete();

            $table->string('video_path', 255)->nullable();
            $table->string('thumbnail_path', 255)->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();

            // Tong tien THAT cua ca cay render dung nen ban nay.
            $table->decimal('cost_total', 10, 4)->default(0);

            $table->string('status', 20)->default('draft');   // draft|composing|ready|failed|published
            $table->text('error_message')->nullable();

            $table->timestamp('published_at')->nullable();
            $table->string('youtube_video_id', 64)->nullable();
            $table->string('facebook_post_id', 64)->nullable();
            $table->string('tiktok_video_id', 64)->nullable();
            $table->string('instagram_video_id', 64)->nullable();

            $table->timestamps();
            $table->index(['status']);
        });

        /**
         * THU TU va CACH DUNG, khong chi "gom nhung render nao".
         *
         * Mot mang JSON tra loi duoc cau hoi thu nhat va khong tra loi duoc cau thu
         * hai — ma dung phim thi thu tu, diem vao, do dai lay ra moi la thu quyet
         * dinh. Va JSON thi khong join duoc, khong co khoa ngoai, khong chan duoc
         * mot render_id da bi xoa.
         */
        Schema::create('video_final_renders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('final_id');
            $table->foreign('final_id')->references('id')->on('video_finals')->cascadeOnDelete();
            $table->uuid('render_id');
            // KHONG cascade: xoa mot render khong duoc lam bien mat mot canh trong ban
            // final da dung. Muon xoa thi phai go khoi final truoc — co y de nang tay.
            $table->foreign('render_id')->references('id')->on('video_renders')->restrictOnDelete();

            $table->unsignedSmallInteger('sequence_no');
            $table->unsignedInteger('start_ms')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();

            $table->timestamps();
            $table->unique(['final_id', 'sequence_no']);
        });

        Schema::table('video_sessions', function (Blueprint $table) {
            /**
             * NOI VIDEO VE BAI VIET.
             *
             * Truoc bay gio noi bang `video_projects.name == articles.title` — mot
             * phep so CHUOI. No gay ngay khi ai do sua tieu de, va da phai lam bang
             * tay ngay 2026-08-07 de tim ra bai nguon cua mot session.
             *
             * Su that DA CO SAN nhung chi duoi dang tien to chuoi: ma session la
             * `art_a27033cd_...`. Cot nay bien no thanh thu join duoc.
             *
             * Dat o SESSION chu khong o PROJECT: mot session = MOT LUOT SAN XUAT cua
             * mot bai. Mot bai co the co session A bi tu choi, session B duoc duyet,
             * session C sua lai — va khong mat lich su nao.
             *
             * Nullable: session cu (do Python day ve, hoac tao truoc cot nay) khong
             * co gia tri de dien, va bia ra mot article_id thi te hon de trong.
             */
            $table->uuid('article_id')->nullable()->after('project_id');
            $table->index('article_id');
        });
    }

    public function down(): void
    {
        Schema::table('video_sessions', function (Blueprint $table) {
            $table->dropIndex(['article_id']);
            $table->dropColumn('article_id');
        });
        Schema::dropIfExists('video_final_renders');
        Schema::dropIfExists('video_finals');
        Schema::dropIfExists('video_renders');
    }
};
