<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NodeMetrik extends Model
{
    protected $table = 'node_metrik';

    public $timestamps = false;

    protected $guarded = ['id'];
}
