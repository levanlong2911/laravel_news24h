<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->dropForeign(['author_id']);
        });

        Schema::table('posts', function (Blueprint $table) {
            $table->uuid('author_id')->nullable()->change();
            $table->foreign('author_id')->references('id')->on('admins')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->dropForeign(['author_id']);
        });

        Schema::table('posts', function (Blueprint $table) {
            $table->uuid('author_id')->nullable(false)->change();
            $table->foreign('author_id')->references('id')->on('admins');
        });
    }
};
