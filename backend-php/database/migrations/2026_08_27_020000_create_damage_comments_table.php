<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Discussion thread on a damage — "I've ordered the part", "tried gluing
 * it, didn't hold".
 *
 * authorName is snapshotted next to authorId on purpose: the account may
 * later be renamed or deleted, and the thread has to stay readable. Same
 * reasoning as customerName next to createdById on reservations.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('damage_comments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('damageId');
            $table->uuid('authorId')->nullable();
            $table->string('authorName', 120);
            $table->text('body');
            $table->timestamp('createdAt')->useCurrent();
            $table->timestamp('updatedAt')->useCurrent();

            $table->foreign('damageId')->references('id')->on('damages')
                ->cascadeOnUpdate()->cascadeOnDelete();
            // Keep the comment when the account goes; authorName carries on.
            $table->foreign('authorId')->references('id')->on('users')
                ->cascadeOnUpdate()->nullOnDelete();
            $table->index('damageId');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('damage_comments');
    }
};
