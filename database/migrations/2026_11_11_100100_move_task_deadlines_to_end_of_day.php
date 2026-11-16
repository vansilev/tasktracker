<?php

use App\Support\DeadlineBackfill;
use Illuminate\Database\Migrations\Migration;

/**
 * A deadline date now means "by the end of that day" (23:59 Kyiv). Existing deadlines stored at
 * midnight (or at 02:00/03:00 from Excel imports) move to 23:59 of the same calendar date.
 * Deploy the code that writes 23:59 first, then run this.
 */
return new class extends Migration
{
    public function up(): void
    {
        (new DeadlineBackfill)->apply();
    }

    public function down(): void
    {
        (new DeadlineBackfill)->revert();
    }
};
