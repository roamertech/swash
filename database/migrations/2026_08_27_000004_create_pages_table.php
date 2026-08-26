<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('title', 200);
            $table->string('slug', 200);
            $table->string('kind', 16)->default('page');
            $table->integer('position')->default(0);
            $table->string('status', 16)->default('draft');
            $table->timestamps();

            $table->unique(['site_id', 'slug']);
            $table->index(['site_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pages');
    }
};
