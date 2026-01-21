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
        Schema::create('shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('worker_id')->constrained('workers')->onDelete('cascade');
            $table->date('shift_date')->comment('Date of the shift');
            $table->time('start_time')->comment('Shift start time');
            $table->time('end_time')->comment('Shift end time');
            $table->text('notes')->nullable()->comment('Shift notes or special instructions');
            $table->timestamps();
            
            // Indexes for performance
            $table->index(['worker_id', 'shift_date']);
            $table->index(['shift_date']);
            $table->index(['start_time', 'end_time']);
            $table->index(['created_at']);
            
            // Ensure unique shift per worker per day
            $table->unique(['worker_id', 'shift_date'], 'unique_worker_shift');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shifts');
    }
};