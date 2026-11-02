<?php

namespace App\Models\Payroll;

use App\Models\AppModel;
use Illuminate\Database\Eloquent\SoftDeletes;

/** What an employee's pay is charged to (e.g. NDT). Without one, their department is used. */
class CostCentre extends AppModel
{
    use SoftDeletes;

    protected $fillable = ['code', 'name', 'active'];

    protected $casts = ['active' => 'boolean'];
}
