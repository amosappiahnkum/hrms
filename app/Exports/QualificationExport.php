<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;

class QualificationExport implements FromCollection
{
    /**
    * @return Collection
    */
    public function collection(): Collection
    {
        return collect();
    }
}
