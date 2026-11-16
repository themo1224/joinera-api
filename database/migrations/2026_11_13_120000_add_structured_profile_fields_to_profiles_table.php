<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('profiles', function (Blueprint $table): void {
            $table->string('desired_path')->nullable()->after('education');
            $table->string('preferred_occupation')->nullable()->after('desired_path');
            $table->date('available_from')->nullable()->after('preferred_occupation');
            $table->boolean('willing_to_relocate')->nullable()->after('available_from');
            $table->json('education_entries')->nullable()->after('willing_to_relocate');
            $table->json('experiences')->nullable()->after('education_entries');
            $table->json('skills')->nullable()->after('experiences');
            $table->json('languages')->nullable()->after('skills');
            $table->json('driving_licenses')->nullable()->after('languages');
            $table->text('work_residence_note')->nullable()->after('driving_licenses');
            $table->text('about')->nullable()->after('work_residence_note');
        });
    }

    public function down(): void
    {
        Schema::table('profiles', function (Blueprint $table): void {
            $table->dropColumn([
                'desired_path',
                'preferred_occupation',
                'available_from',
                'willing_to_relocate',
                'education_entries',
                'experiences',
                'skills',
                'languages',
                'driving_licenses',
                'work_residence_note',
                'about',
            ]);
        });
    }
};
