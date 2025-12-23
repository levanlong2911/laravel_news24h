<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('advertisements', function (Blueprint $table) {
            $table\->uuid('id')->primary();
            $table->string('name'); // Tên quảng cáo để quản lý
            $table->enum('position', ['top', 'middle', 'bottom']); // Vị trí hiển thị
            $table->text('script'); // Mã HTML/JS từ nhà quảng cáo
            $table->boolean('active')->default(true); // Cho phép bật/tắt quảng cáo
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('advertisements');
    }
};
