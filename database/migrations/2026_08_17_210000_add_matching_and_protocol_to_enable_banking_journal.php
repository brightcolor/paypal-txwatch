<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records HOW a journal entry was recognised - and keeps a protocol per entry.
 *
 * WHY THE PROTOCOL IS ITS OWN TABLE and not a json column that gets overwritten:
 * an entry is looked at again on every pull (four a day, three days of overlap),
 * and the interesting question is usually "since when does this look like this".
 * A column holds only the latest answer; an append-only table holds the course of
 * events - pulled, recognised, proposal changed because a new order appeared,
 * promoted. Overwriting would erase exactly the history that answers the question.
 *
 * `match_candidates` is nevertheless a column on the entry: the CURRENT proposal
 * has to be sortable and filterable, and reading that out of a log table on every
 * table render would be a join per row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('enable_banking_journal', function (Blueprint $table) {
            // exact | normalised | fuzzy | none - see PurposeMatcher.
            $table->string('match_method', 12)->nullable()->index();

            // 0-100. Only exact reaches 95+; a proposal can never look as good.
            $table->unsignedTinyInteger('match_score')->nullable();

            // The proposals with their evidence, best first.
            $table->json('match_candidates')->nullable();

            /*
             * The searched string, i.e. the purpose with separators and stray spaces
             * removed. Stored because otherwise nobody can answer later WHY a code
             * was not found - reconstructing it means guessing which normalisation
             * ran at the time.
             */
            $table->text('match_haystack')->nullable();
        });

        Schema::create('enable_banking_journal_events', function (Blueprint $table) {
            $table->id();

            $table->foreignId('journal_entry_id')
                ->constrained('enable_banking_journal')
                ->cascadeOnDelete();

            // pulled | matched | suggested | unmatched | changed | promoted
            $table->string('kind', 16)->index();

            // One sentence for a human, in German - this is read, not parsed.
            $table->text('message');

            // What the decision was based on: haystack, candidates, scores.
            $table->json('context')->nullable();

            $table->timestamp('at');

            $table->index(['journal_entry_id', 'at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enable_banking_journal_events');

        Schema::table('enable_banking_journal', function (Blueprint $table) {
            $table->dropColumn(['match_method', 'match_score', 'match_candidates', 'match_haystack']);
        });
    }
};
