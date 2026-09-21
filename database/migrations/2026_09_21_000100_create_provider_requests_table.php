<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per call to an external provider, across every capability.
     *
     * This is the ledger that makes long-running generation survivable: the
     * provider returns a request id immediately and the result lands minutes
     * later, so the request id has to outlive the worker that submitted it.
     *
     * It is also where NFR-3 is actually enforced. `fingerprint` is a
     * deterministic hash of everything that decides the output, and it carries a
     * UNIQUE index. That is deliberate: a "check whether one already exists,
     * then insert" guard is check-then-act, and two workers — or one
     * double-clicking owner — can both pass the check before either inserts.
     * The database refusing the second row is the only version of this that
     * holds under concurrency, and every duplicate here is money.
     */
    public function up(): void
    {
        Schema::create('provider_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();

            // 'video.clip', 'speech.narration', 'music.track', 'image.reference'
            $table->string('capability', 48);

            $table->string('provider', 48);
            $table->string('provider_model')->nullable();

            // The provider's handle for this job. Null until submission returns.
            $table->string('provider_request_id')->nullable();

            $table->string('fingerprint', 64)->unique();

            $table->string('status', 24)->default('pending');

            // Set once the output has been downloaded into our own storage.
            $table->foreignId('asset_id')->nullable()->constrained('assets')->nullOnDelete();

            $table->decimal('estimated_cost_usd', 8, 4)->default(0);

            // Null until the provider tells us what it really charged. Kept
            // separate from the estimate rather than overwriting it, so the two
            // can be reconciled and the estimator corrected.
            $table->decimal('actual_cost_usd', 8, 4)->nullable();

            // What we sent, with credentials removed. For reproducing a bad
            // generation without re-deriving the prompt.
            $table->json('request_payload')->nullable();
            $table->json('provider_response')->nullable();

            // Where the provider published the output. Kept for traceability
            // only — these URLs expire, so the Asset is the real artifact.
            $table->text('output_url')->nullable();

            $table->string('failure_reason', 32)->nullable();
            $table->text('error_message')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);

            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'capability']);
            $table->index('status');

            // The poller's working set: everything still outstanding.
            $table->index(['status', 'submitted_at']);

            $table->index('provider_request_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_requests');
    }
};
