<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ServiceMetrik extends Model
{
    protected $table = 'service_metrik';

    public $timestamps = false;

    protected $guarded = ['id'];
}
