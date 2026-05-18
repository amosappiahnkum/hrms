<?php

use App\Models\Config\Department;
use App\Models\SelfService\Rank;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('employees', static function (Blueprint $table) {
            $table->id();
            $table->string('title')->nullable();
            $table->string('first_name');
            $table->string('middle_name')->nullable();
            $table->string('last_name');
            $table->string('staff_id')->nullable();
            $table->date('dob')->nullable();
            $table->string('gender')->nullable();
            $table->text('qualification')->nullable();
            $table->string('marital_status')->nullable();
            $table->string('ssnit_number')->nullable();
            $table->boolean('key_officer')->default(false);
            $table->boolean('senior_member')->default(false);
            $table->boolean('senior_staff')->default(false);
            $table->boolean('junior_staff')->default(false);
            $table->boolean('secondment_staff')->default(false);
            $table->foreignIdFor(Rank::class, 'gtec_placement')->nullable()->constrained('ranks');
            $table->foreignIdFor(Department::class)->nullable()->constrained();
            $table->foreignIdFor(Rank::class)->nullable()->constrained();
            $table->foreignIdFor(User::class)->nullable()->constrained();
            $table->softDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('employees');
    }
};
