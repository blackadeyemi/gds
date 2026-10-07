<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The activity log and the notifications built on it (core).
 *
 *   activities              one row per user ACTION — a Save that wrote 40
 *                           pallets is one activity, item_count 40
 *   activity_items          what that action changed, record by record:
 *                           subject (database.table + id), before/after
 *   activity_notifications  who should hear about an activity, and whether
 *                           they have
 *   activity_mutes          modules a user has asked not to be notified about
 *
 * InnoDB on purpose: the log is written once the change it records has
 * committed — never for a change that rolled back (see ActivityRecorder).
 *
 * Nothing here is editable from the app, by anyone. `gds:prune-activity`
 * removes rows past the retention period; nothing else deletes.
 */
return new class extends Migration
{
    protected $connection = 'core';

    public function up(): void
    {
        Schema::connection('core')->create('activities', function (Blueprint $t) {
            $t->engine = 'InnoDB';
            $t->bigIncrements('id');
            $t->unsignedInteger('user_id')->nullable();
            $t->string('username', 100)->nullable();
            $t->string('page_key', 120)->nullable();
            $t->string('module', 80)->nullable();
            $t->string('route', 150)->nullable();
            $t->string('component', 150)->nullable();
            $t->string('method', 80)->nullable();
            // created | updated | deleted | mixed | auth
            $t->string('action', 20);
            $t->string('verb', 40);
            $t->string('summary', 500);
            $t->string('refs', 1000)->nullable();
            $t->unsignedInteger('item_count')->default(0);
            $t->string('ip', 45)->nullable();
            $t->string('user_agent', 255)->nullable();
            $t->timestamp('created_at')->useCurrent();

            $t->index('created_at');
            $t->index(['user_id', 'created_at']);
            $t->index(['page_key', 'created_at']);
            $t->index(['module', 'created_at']);
            $t->index(['action', 'created_at']);
            $t->index(['username', 'created_at']);
        });

        Schema::connection('core')->create('activity_items', function (Blueprint $t) {
            $t->engine = 'InnoDB';
            $t->bigIncrements('id');
            $t->unsignedBigInteger('activity_id');
            $t->string('op', 10); // created | updated | deleted
            $t->string('subject_type', 120); // database.table
            $t->string('subject_id', 64)->nullable();
            $t->string('ref', 190)->nullable();
            $t->json('changes')->nullable();
            $t->string('source', 10)->default('model'); // model | query

            $t->index('activity_id');
            $t->index(['subject_type', 'subject_id']);
            $t->foreign('activity_id')->references('id')->on('activities')->cascadeOnDelete();
        });

        Schema::connection('core')->create('activity_notifications', function (Blueprint $t) {
            $t->engine = 'InnoDB';
            $t->bigIncrements('id');
            $t->unsignedInteger('user_id');
            $t->unsignedBigInteger('activity_id');
            // admin | approval | your_record
            $t->string('reason', 20);
            $t->string('note', 190)->nullable();
            $t->timestamp('read_at')->nullable();
            $t->timestamp('created_at')->useCurrent();

            $t->unique(['user_id', 'activity_id']);
            $t->index(['user_id', 'read_at']);
            $t->foreign('activity_id')->references('id')->on('activities')->cascadeOnDelete();
        });

        Schema::connection('core')->create('activity_mutes', function (Blueprint $t) {
            $t->engine = 'InnoDB';
            $t->unsignedInteger('user_id');
            $t->string('module', 80);
            $t->timestamp('created_at')->useCurrent();

            $t->primary(['user_id', 'module']);
        });
    }

    public function down(): void
    {
        Schema::connection('core')->dropIfExists('activity_mutes');
        Schema::connection('core')->dropIfExists('activity_notifications');
        Schema::connection('core')->dropIfExists('activity_items');
        Schema::connection('core')->dropIfExists('activities');
    }
};
