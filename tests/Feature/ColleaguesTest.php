<?php

namespace Tests\Feature;

use App\Models\Config\Department;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\SetsUpAccess;
use Tests\TestCase;

/** The dashboard's Colleagues: the others in the employee's department, never themselves. */
class ColleaguesTest extends TestCase
{
    use RefreshDatabase, SetsUpAccess;

    public function test_colleagues_are_the_rest_of_the_department(): void
    {
        $this->setUpAccess([]);
        $ndt = Department::create(['name' => 'NDT']);
        $stores = Department::create(['name' => 'Stores']);
        [$me, $peer, $other] = [$this->userWithRole('staff'), $this->userWithRole('staff'), $this->userWithRole('staff')];
        $me->employee->update(['department_id' => $ndt->id]);
        $peer->employee->update(['department_id' => $ndt->id]);
        $other->employee->update(['department_id' => $stores->id]);

        Sanctum::actingAs($me);
        $uuids = collect($this->getJson('/api/v1/my-colleagues')->assertOk()->json('data'))->pluck('uuid');
        $this->assertSame([$peer->employee->uuid], $uuids->all());
    }
}
