<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 12 — Social Publishing. Six tables of its own: nothing here reads, restores or drops the older
 * `video_publications` / `video_publication_metrics`, which stay as they are wherever they still exist.
 *
 * A package revision, a publication and an event are never rewritten once they stand for something a person
 * approved, exported or posted: a change is a new row.
 */
return new class extends Migration
{
    public function up(): void
    {
        // An account a person connected to a platform. The tokens are stored encrypted by the model and are read by the publishers only.
        Schema::create('video_social_connections', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('platform', 20);
            $table->string('label', 191);
            $table->string('external_account_id', 191)->nullable();
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->dateTime('token_expires_at')->nullable();
            $table->json('scopes')->nullable();
            // The pages or channels the account gives access to, each with the id the platform knows it by; encrypted, since a page comes with its own token.
            $table->longText('accounts')->nullable();
            $table->string('status', 20)->default('active');
            $table->text('last_error')->nullable();
            $table->uuid('connected_by')->nullable();
            $table->timestamps();

            $table->index(['platform', 'status']);
        });

        // The profile of one Fanpage or one YouTube channel: where a package goes and how it speaks.
        Schema::create('video_social_channels', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('platform', 20);
            $table->string('display_name', 191);
            // The id the platform knows the page or channel by. Entered by a person until a connection proves it; never matched by name.
            $table->string('external_id', 191)->nullable();
            $table->string('url', 500)->nullable();
            $table->string('language', 10);
            $table->string('timezone', 64);
            $table->text('audience')->nullable();
            $table->text('brand_voice')->nullable();
            $table->string('default_goal', 255)->nullable();
            $table->text('default_cta')->nullable();
            $table->string('default_link', 500)->nullable();
            // How this channel discloses synthetic content: the sentence put in the caption, if any, and whether the platform's own option is set.
            $table->json('disclosure')->nullable();
            $table->uuid('connection_id')->nullable();
            $table->foreign('connection_id')->references('id')->on('video_social_connections')->nullOnDelete();
            // Set only when a connection listed this very external id.
            $table->dateTime('verified_at')->nullable();
            $table->string('status', 20)->default('active');
            $table->uuid('created_by')->nullable();
            $table->timestamps();

            $table->unique(['platform', 'external_id']);
        });

        // One revision of a package. A package is project + channel + format + language; every change of its content is a new revision.
        Schema::create('video_social_packages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('package_group_id');
            $table->unsignedInteger('revision');
            $table->uuid('project_id');
            $table->foreign('project_id')->references('id')->on('video_projects')->cascadeOnDelete();
            $table->uuid('channel_id');
            $table->foreign('channel_id')->references('id')->on('video_social_channels')->restrictOnDelete();
            $table->string('format', 30);
            $table->string('language', 10);
            $table->uuid('previous_revision_id')->nullable();
            // The approved film the package is about, and everything it was read from, frozen with its hash.
            $table->uuid('final_id');
            $table->longText('source_json');
            $table->string('source_hash', 80);
            // The AI run whose answer the content started from, and that answer as it came; null for a package written by hand.
            $table->uuid('ai_stage_id')->nullable();
            $table->longText('ai_content_json')->nullable();
            // What a person edits, and the document code assembles from it: the very text and choices that are approved, exported and posted.
            $table->longText('content_json');
            $table->longText('document_json');
            $table->string('package_hash', 80);
            // The cover of the revision. While a revision names it the file's row cannot be deleted: a cover a package shows is not swept away.
            $table->uuid('artwork_artifact_id')->nullable();
            $table->foreign('artwork_artifact_id')->references('id')->on('video_artifacts')->restrictOnDelete();
            $table->string('status', 20)->default('draft');
            $table->uuid('created_by')->nullable();
            $table->dateTime('approved_at')->nullable();
            $table->dateTime('revoked_at')->nullable();
            $table->timestamps();

            $table->unique(['package_group_id', 'revision']);
            $table->index(['project_id', 'channel_id', 'format', 'language'], 'video_social_packages_identity_index');
        });

        // One posting of one approved revision: reported by a person after posting by hand, or sent through the platform's API.
        Schema::create('video_social_publications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('package_revision_id');
            $table->foreign('package_revision_id')->references('id')->on('video_social_packages')->restrictOnDelete();
            $table->string('package_hash', 80);
            $table->uuid('project_id');
            $table->foreign('project_id')->references('id')->on('video_projects')->cascadeOnDelete();
            $table->uuid('channel_id');
            $table->string('method', 10);
            $table->string('status', 20);
            // One request, however often it is sent: the same key never makes a second posting. A posting made again on purpose has a key of its own.
            $table->uuid('request_key');
            $table->string('intent', 20)->default('first');
            $table->string('external_id', 255)->nullable();
            $table->string('external_url', 500)->nullable();
            $table->dateTime('scheduled_at')->nullable();
            $table->dateTime('published_at')->nullable();
            $table->uuid('requested_by')->nullable();
            $table->uuid('confirmed_by')->nullable();
            // What is sent, frozen when the request is taken in, with its hash and the adapter that reads it.
            $table->longText('payload_json')->nullable();
            $table->string('payload_hash', 80)->nullable();
            $table->string('adapter_version', 60)->nullable();
            // What each step of the posting came to, and what the platform gave back to follow it by; the session is encrypted by the model.
            $table->longText('steps_json')->nullable();
            $table->text('session')->nullable();
            $table->string('error_code', 60)->nullable();
            $table->text('error_message')->nullable();
            $table->uuid('claim_token')->nullable();
            $table->dateTime('claimed_at')->nullable();
            $table->timestamps();

            $table->unique('request_key');
            $table->index(['package_revision_id', 'status']);
            $table->index(['project_id', 'created_at']);
        });

        // What happened to a package or a publication, in order: exported, queued, uploaded, rescheduled, cancelled. Append-only.
        Schema::create('video_social_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('project_id');
            $table->foreign('project_id')->references('id')->on('video_projects')->cascadeOnDelete();
            $table->uuid('package_revision_id')->nullable();
            $table->uuid('publication_id')->nullable();
            $table->string('type', 40);
            $table->uuid('actor_id')->nullable();
            $table->longText('payload_json')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['project_id', 'created_at']);
            $table->index('publication_id');
            $table->index('package_revision_id');
        });

        // One number a platform gave for one publication over one span. A number the platform does not give is stored as not provided, never as zero.
        Schema::create('video_social_metrics', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('publication_id');
            $table->foreign('publication_id')->references('id')->on('video_social_publications')->cascadeOnDelete();
            $table->string('metric', 80);
            $table->decimal('value', 20, 4)->nullable();
            $table->string('availability', 20);
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            // The span as one key, so reading the same span again replaces the number instead of adding a row.
            $table->string('period_key', 40);
            $table->dateTime('fetched_at');
            $table->string('source', 80)->nullable();
            $table->longText('raw_json')->nullable();
            $table->timestamps();

            $table->unique(['publication_id', 'metric', 'period_key'], 'video_social_metrics_span_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('video_social_metrics');
        Schema::dropIfExists('video_social_events');
        Schema::dropIfExists('video_social_publications');
        Schema::dropIfExists('video_social_packages');
        Schema::dropIfExists('video_social_channels');
        Schema::dropIfExists('video_social_connections');
    }
};
