<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            CREATE VIRTUAL TABLE videos_fts USING fts5(
                title,
                description,
                content='videos',
                content_rowid='id'
            )
        ");

        DB::statement("
            INSERT INTO videos_fts(rowid, title, description)
            SELECT id, title, COALESCE(description, '') FROM videos
        ");

        DB::statement("
            CREATE TRIGGER videos_fts_ai AFTER INSERT ON videos BEGIN
                INSERT INTO videos_fts(rowid, title, description)
                VALUES (new.id, new.title, COALESCE(new.description, ''));
            END
        ");

        DB::statement("
            CREATE TRIGGER videos_fts_ad AFTER DELETE ON videos BEGIN
                INSERT INTO videos_fts(videos_fts, rowid, title, description)
                VALUES ('delete', old.id, old.title, COALESCE(old.description, ''));
            END
        ");

        DB::statement("
            CREATE TRIGGER videos_fts_au AFTER UPDATE ON videos BEGIN
                INSERT INTO videos_fts(videos_fts, rowid, title, description)
                VALUES ('delete', old.id, old.title, COALESCE(old.description, ''));
                INSERT INTO videos_fts(rowid, title, description)
                VALUES (new.id, new.title, COALESCE(new.description, ''));
            END
        ");
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS videos_fts_ai');
        DB::statement('DROP TRIGGER IF EXISTS videos_fts_ad');
        DB::statement('DROP TRIGGER IF EXISTS videos_fts_au');
        DB::statement('DROP TABLE IF EXISTS videos_fts');
    }
};
