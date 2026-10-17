<?php

namespace Modules\Arca\Models;

use Illuminate\Database\Eloquent\Model;

abstract class ArcaModel extends Model
{
    public function getConnectionName(): ?string
    {
        return $this->connection ?? config('arca.connection');
    }
}
