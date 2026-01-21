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
        Schema::create('workers', function (Blueprint $table) {
            $table->id();
            $table->string('employee_code')->unique()->comment('Unique identifier for worker');
            $table->string('full_name');
            $table->string('email')->unique()->nullable();
            $table->string('phone')->nullable();
            $table->string('department')->nullable()->comment('Department name');
            $table->string('position')->nullable()->comment('Job position');
            $table->date('hire_date')->nullable()->comment('Date when worker was hired');
            $table->enum('status', ['active', 'inactive', 'suspended'])->default('active');
            $table->text('face_embedding')->nullable()->comment('JSON array of face embedding vectors');
            $table->string('face_image_path')->nullable()->comment('Path to face image');
            $table->string('pin_code', 4)->nullable()->comment('4-digit PIN for manual check-in/out');
            $table->text('notes')->nullable()->comment('Additional notes');
            $table->timestamps();
            $table->softDeletes();
            
            // Indexes for performance
            $table->index(['employee_code']);
            $table->index(['status']);
            $table->index(['department']);
            $table->index(['created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('workers');
    }
};