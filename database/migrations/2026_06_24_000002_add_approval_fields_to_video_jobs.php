<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// L10.5 Human Approval gate -- every rendered video must pass admin review
// (thumbnail + hook + title + 3 facts + CTA) before the publisher fires.
// approval_status is NULL until the Python pipeline marks the job 'uploaded',
// at which point it flips to 'pending_review' automatically via a model observer.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('video_jobs', function (Blueprint $table) {
            $table->enum('approval_status', [
                'pending_review', 'approved', 'rejected', 'regenerating',
            ])->nullable()->after('status');

            $table->uuid('reviewed_by')->nullable()->after('approval_status');
            $table->timestamp('reviewed_at')->nullable()->after('reviewed_by');
            $table->text('rejection_note')->nullable()->after('reviewed_at');

            $table->foreign('reviewed_by')->references('id')->on('admins')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('video_jobs', function (Blueprint $table) {
            $table->dropForeign(['reviewed_by']);
            $table->dropColumn(['approval_status', 'reviewed_by', 'reviewed_at', 'rejection_note']);
        });
    }
};
