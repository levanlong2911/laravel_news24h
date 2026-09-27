<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('video_visual_identities', 'subject_key')) {
            Schema::table('video_visual_identities', function (Blueprint $table): void {
                $table->string('subject_key', 96)->nullable()->after('identity_type');
            });
        }

        DB::table('video_visual_identities')->whereNull('subject_key')->update([
            'subject_key' => 'master_vessel',
        ]);

        if (! $this->hasIndex('video_visual_identities_subject_lookup')) {
            Schema::table('video_visual_identities', function (Blueprint $table): void {
                $table->index(
                    ['project_id', 'identity_type', 'subject_key'],
                    'video_visual_identities_subject_lookup',
                );
            });
        }

        Schema::table('video_visual_identities', function (Blueprint $table): void {
            if ($this->hasIndex('video_visual_identities_project_id_identity_type_version_unique')) {
                $table->dropUnique('video_visual_identities_project_id_identity_type_version_unique');
            }
            if (! $this->hasIndex('video_visual_identities_subject_version_unique')) {
                $table->unique(
                    ['project_id', 'identity_type', 'subject_key', 'version'],
                    'video_visual_identities_subject_version_unique',
                );
            }
        });
    }

    public function down(): void
    {
        if (! $this->hasIndex('video_visual_identities_project_id_identity_type_version_unique')) {
            Schema::table('video_visual_identities', function (Blueprint $table): void {
                $table->unique(['project_id', 'identity_type', 'version']);
            });
        }

        Schema::table('video_visual_identities', function (Blueprint $table): void {
            if ($this->hasIndex('video_visual_identities_subject_version_unique')) {
                $table->dropUnique('video_visual_identities_subject_version_unique');
            }
            if ($this->hasIndex('video_visual_identities_subject_lookup')) {
                $table->dropIndex('video_visual_identities_subject_lookup');
            }
        });

        if (Schema::hasColumn('video_visual_identities', 'subject_key')) {
            Schema::table('video_visual_identities', function (Blueprint $table): void {
                $table->dropColumn('subject_key');
            });
        }
    }

    private function hasIndex(string $name): bool
    {
        foreach (Schema::getIndexes('video_visual_identities') as $index) {
            if (($index['name'] ?? null) === $name) {
                return true;
            }
        }

        return false;
    }
};
