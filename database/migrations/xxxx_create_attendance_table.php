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
        Schema::create('attendance', function (Blueprint $table) {
            $table->id();
            $table->foreignId('worker_id')->constrained('workers')->onDelete('cascade');
            $table->enum('type', ['check_in', 'check_out'])->comment('Type of attendance record');
            $table->timestamp('timestamp')->comment('Exact time of check-in/out');
            $table->string('device_id')->nullable()->comment('ID of tablet/kiosk device');
            $table->float('confidence')->nullable()->comment('Face recognition confidence score (0-1)');
            $table->enum('method', ['face', 'pin', 'manual'])->default('face')->comment('Method used for check-in/out');
            $table->text('notes')->nullable()->comment('Additional notes or comments');
            $table->timestamps();
            
            // Indexes for performance
            $table->index(['worker_id', 'timestamp']);
            $table->index(['type']);
            $table->index(['timestamp']);
            $table->index(['device_id']);
            $table->index(['method']);
            $table->index(['created_at']);
            
            // Composite index for daily reports
            $table->index(['worker_id', DB::raw('DATE(timestamp)')]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attendance');
    }
};