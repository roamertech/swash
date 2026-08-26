<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('media_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('path', 255);
            $table->string('kind', 12)->default('raster');
            $table->string('alt', 160)->nullable();
            $table->string('source', 12)->default('generated');
            $table->text('prompt')->nullable();
            $table->jsonb('placement')->nullable();
            $table->foreignId('parent_asset_id')->nullable()->constrained('media_assets')->nullOnDelete();
            $table->timestamps();
        });

        DB::statement("ALTER TABLE media_assets ADD COLUMN tags text[] NOT NULL DEFAULT '{}'");
        DB::statement('CREATE INDEX media_assets_tags_gin ON media_assets USING GIN (tags)');
    }

    public function down(): void
    {
        Schema::dropIfExists('media_assets');
    }
};
