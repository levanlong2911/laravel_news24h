<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            Schema::table('posts', function (Blueprint $table) {

                // 1. drop cột domain cũ nếu tồn tại
                if (Schema::hasColumn('posts', 'domain')) {
                    $table->dropColumn('domain');
                }

                // 2. thêm domain_id (UUID)
                if (!Schema::hasColumn('posts', 'domain_id')) {
                    $table->uuid('domain_id')
                        ->nullable()
                        ->after('author_id')
                        ->index();
                }
            });

            // 3. add foreign key
            Schema::table('posts', function (Blueprint $table) {
                $table->foreign('domain_id')
                    ->references('id')
                    ->on('domains')
                    ->onDelete('cascade');
            });
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->dropForeign(['domain_id']);
            $table->dropColumn('domain_id');

            // rollback lại domain string (nếu cần)
            $table->string('domain')->nullable()->index();
        });
    }
};
