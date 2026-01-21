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
        Schema::create('leaves', function (Blueprint $table) {
            $table->id();
            $table->foreignId('worker_id')->constrained('workers')->onDelete('cascade');
            $table->date('start_date')->comment('Start date of leave');
            $table->date('end_date')->comment('End date of leave');
            $table->enum('type', ['vacation', 'sick', 'personal', 'other'])->default('vacation');
            $table->text('reason')->comment('Reason for leave');
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->text('admin_notes')->nullable()->comment('Notes from admin');
            $table->timestamps();
            
            // Indexes for performance
            $table->index(['worker_id']);
            $table->index(['start_date', 'end_date']);
            $table->index(['status']);
            $table->index(['type']);
            $table->index(['created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('leaves');
    }
};